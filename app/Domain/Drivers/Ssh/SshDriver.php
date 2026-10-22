<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Enums\CheckResult;
use App\Enums\ComponentType;

/**
 * SiteDriver over SSH (UZEROWPUP-23): commands through phpseclib, files through
 * rsync with an ephemeral agent. The tool works in its own directory inside the
 * account's home ({home}/{work_dir}), where it also installs its pinned WP-CLI,
 * which always runs with the domain's PHP binary.
 *
 * Remote paths given to this driver are plain file names inside that directory.
 * Every argument is escaped with escapeshellarg(); no command is built by
 * concatenating unchecked input.
 */
final class SshDriver implements SiteDriver
{
    private const SHELL_MARKER = 'wpup-shell-ok';

    private const REQUIRED_TOOLS = ['tar', 'gzip', 'gunzip', 'sha256sum', 'stat', 'df', 'mktemp', 'rsync', 'tail', 'head'];

    private ?string $home = null;

    private bool $wpCliReady = false;

    public function __construct(
        private readonly SshTarget $target,
        private readonly SshConnection $connection,
        private readonly RsyncTransfer $transfer,
        private readonly SshSettings $settings,
    ) {}

    public function probe(): ProbeResult
    {
        try {
            $shell = $this->exec('echo '.self::SHELL_MARKER, 30);
        } catch (ConnectionFailed $e) {
            return new ProbeResult([new ProbeCheck('connexió', CheckResult::Error, $e->getMessage())]);
        }

        $checks = [new ProbeCheck('connexió', CheckResult::Ok, "{$this->target->label()}, host key fixada")];

        if (! str_contains($shell->stdout, self::SHELL_MARKER)) {
            $checks[] = new ProbeCheck('shell', CheckResult::Error, "El compte {$this->target->user} no té un shell que executi comandes (p. ex. /bin/false). A Plesk, cal l'accés SSH amb /bin/bash, no chrooted.");

            return new ProbeResult($checks);
        }

        $missing = trim($this->exec('for c in '.implode(' ', self::REQUIRED_TOOLS).'; do command -v "$c" >/dev/null 2>&1 || printf "%s " "$c"; done', 30)->stdout);
        if ($missing !== '') {
            $checks[] = new ProbeCheck('eines', CheckResult::Error, "Falten eines al compte: {$missing}. Pot ser un shell chrooted; cal /bin/bash.");

            return new ProbeResult($checks);
        }
        $checks[] = new ProbeCheck('eines', CheckResult::Ok, implode(', ', self::REQUIRED_TOOLS));

        $php = $this->exec(escapeshellarg($this->php()).' -r '.escapeshellarg('echo PHP_VERSION;'), 30);
        if (! $php->successful()) {
            $checks[] = new ProbeCheck('php', CheckResult::Error, "No es pot executar el PHP del domini ({$this->php()}).");

            return new ProbeResult($checks);
        }
        $checks[] = new ProbeCheck('php', CheckResult::Ok, trim($php->stdout)." ({$this->php()})");

        try {
            $version = $this->wpOk(['cli', 'version'], 'WP-CLI');
            $checks[] = new ProbeCheck('wp-cli', CheckResult::Ok, trim($version->stdout));
            $this->wpOk(['core', 'is-installed'], 'La comprovació de WordPress');
            $checks[] = new ProbeCheck('wordpress', CheckResult::Ok, $this->target->wpPath);
            $checks[] = $this->writableCheck();
            $checks[] = new ProbeCheck('disc', CheckResult::Ok, sprintf('%.1f GB lliures', $this->freeDiskBytes() / 1e9));
        } catch (DriverException $e) {
            $checks[] = new ProbeCheck('wordpress', CheckResult::Error, $e->getMessage());
        }

        return new ProbeResult($checks);
    }

    /**
     * The account must be able to write the code it updates and restores.
     */
    private function writableCheck(): ProbeCheck
    {
        $root = rtrim($this->target->wpPath, '/');
        $paths = [$root, $root.'/wp-content', $root.'/wp-content/plugins', $root.'/wp-content/themes'];
        $script = 'for p in '.implode(' ', array_map(escapeshellarg(...), $paths)).'; do [ -w "$p" ] || printf "%s " "$p"; done';
        $readOnly = trim($this->exec($script, 30)->stdout);

        return $readOnly === ''
            ? new ProbeCheck('permisos', CheckResult::Ok, "{$this->target->user} pot escriure el codi del lloc")
            : new ProbeCheck('permisos', CheckResult::Error, "{$this->target->user} no pot escriure a: {$readOnly}");
    }

    public function installedComponents(): InstalledComponents
    {
        $core = trim($this->wpOk(['core', 'version'], 'La lectura de la versió del core')->stdout);
        $php = trim($this->mustExec(escapeshellarg($this->php()).' -r '.escapeshellarg('echo PHP_VERSION;'), 'La lectura de la versió de PHP')->stdout);
        $db = trim($this->wpOk(['eval', 'echo $GLOBALS["wpdb"]->db_server_info();'], 'La lectura de la versió de la BD')->stdout);

        $components = [];
        foreach ($this->wpJson(['plugin', 'list', '--format=json', '--fields=name,version,status,title']) as $plugin) {
            $status = self::field($plugin, 'status');
            $type = match (true) {
                $status === 'must-use' => ComponentType::MuPlugin,
                $status === 'dropin' => ComponentType::Dropin,
                self::field($plugin, 'name') === 'woocommerce' => ComponentType::Woocommerce,
                default => ComponentType::Plugin,
            };
            $active = str_starts_with($status, 'active') || $type === ComponentType::MuPlugin || $type === ComponentType::Dropin;
            $components[] = new ComponentVersion($type, self::field($plugin, 'name'), self::field($plugin, 'version'), $active, self::field($plugin, 'title'));
        }

        $activeTheme = $parentTheme = null;
        foreach ($this->wpJson(['theme', 'list', '--format=json', '--fields=name,version,status,title']) as $theme) {
            $status = self::field($theme, 'status');
            $name = self::field($theme, 'name');
            $activeTheme = $status === 'active' ? $name : $activeTheme;
            $parentTheme = $status === 'parent' ? $name : $parentTheme;
            $components[] = new ComponentVersion(ComponentType::Theme, $name, self::field($theme, 'version'), $status !== 'inactive', self::field($theme, 'title'));
        }

        return new InstalledComponents($core, $php, $db, $components, $activeTheme, $parentTheme);
    }

    public function wp(array $args, ?int $timeoutSeconds = null): CommandResult
    {
        $this->ensureWpCli();

        return $this->exec($this->wpCommand($args), $timeoutSeconds);
    }

    public function dumpDatabase(string $remotePath): RemoteFile
    {
        $path = $this->path($remotePath);
        [$sql, $gz] = [escapeshellarg($path.'.part.sql'), escapeshellarg($path.'.part.gz')];
        $this->ensureWpCli();

        // Only the tables with the site's prefix: a database may be shared.
        $this->mustExec(
            't=$('.$this->wpCommand(['db', 'tables', '--all-tables-with-prefix', '--format=csv']).')'
            .' && '.$this->wpCommand(['db', 'export', $path.'.part.sql', '--single-transaction', '--quick']).' --tables="$t" >/dev/null'
            ." && gzip -c {$sql} > {$gz} && mv {$gz} ".escapeshellarg($path)
            ."; rc=\$?; rm -f {$sql} {$gz}; exit \$rc",
            'El volcat de la base de dades',
            $this->settings->transferTimeoutSeconds,
        );

        return $this->fileInfo($remotePath);
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        $path = $this->path($remotePath);
        $tmp = escapeshellarg($path.'.part');
        $manifest = ': > "$m/.wpup-excludes"';
        $options = '';
        foreach ($excludes as $exclude) {
            $exclude = self::relativePath($exclude);
            $manifest .= ' && printf "%s\n" '.escapeshellarg($exclude).' >> "$m/.wpup-excludes"';
            $options .= ' '.escapeshellarg('--exclude=./'.$exclude);
        }

        // The archive carries its own exclude list, so restoreFiles() never deletes
        // what was left out (uploads…).
        $this->mustExec(
            'm=$(mktemp -d '.escapeshellarg($this->workDir().'/m-XXXXXX').') && '.$manifest
            ." && tar -czf {$tmp}{$options} -C ".escapeshellarg($this->target->wpPath).' . -C "$m" .wpup-excludes'
            ." && mv {$tmp} ".escapeshellarg($path)
            ."; rc=\$?; rm -rf \"\$m\" {$tmp}; exit \$rc",
            "L'arxiu dels fitxers",
            $this->settings->transferTimeoutSeconds,
        );

        return $this->fileInfo($remotePath);
    }

    public function download(RemoteFile $remote, string $localPath): void
    {
        $path = $this->path($remote->path);
        if (! $this->exec('test -f '.escapeshellarg($path))->successful()) {
            throw new RemoteCommandFailed("El fitxer «{$remote->path}» no existeix al lloc.");
        }

        $this->transfer->download($path, $localPath);

        if (hash_file('sha256', $localPath) !== $remote->sha256) {
            @unlink($localPath);

            throw IntegrityCheckFailed::checksumMismatch($remote->path);
        }
    }

    public function upload(string $localPath, string $remotePath): RemoteFile
    {
        $path = $this->path($remotePath);
        $sha256 = is_file($localPath) ? hash_file('sha256', $localPath) : false;
        if ($sha256 === false) {
            throw new DriverException("No es pot llegir el fitxer local «{$localPath}».");
        }

        $this->transfer->upload($localPath, $path.'.part');
        if ($this->fileInfo($remotePath.'.part')->sha256 !== $sha256) {
            $this->exec('rm -f -- '.escapeshellarg($path.'.part'));

            throw IntegrityCheckFailed::checksumMismatch($remotePath);
        }
        $this->mustExec('mv -f -- '.escapeshellarg($path.'.part').' '.escapeshellarg($path), 'La pujada');

        return new RemoteFile($remotePath, (int) filesize($localPath), $sha256);
    }

    public function applyUpdate(UpdatePackage $package): CommandResult
    {
        $this->verify($package->file);
        $zip = $this->path($package->file->path);

        [$current, $install] = match ($package->type) {
            ComponentType::Core => [['core', 'version'], ['core', 'update', $zip]],
            ComponentType::Plugin, ComponentType::Woocommerce => [['plugin', 'get', $package->slug, '--field=version'], ['plugin', 'install', $zip, '--force']],
            ComponentType::Theme => [['theme', 'get', $package->slug, '--field=version'], ['theme', 'install', $zip, '--force']],
            default => throw new DriverException("El driver SSH no instal·la paquets de tipus «{$package->type->value}»."),
        };

        $installed = $this->wp($current);
        if ($installed->successful() && trim($installed->stdout) === $package->targetVersion) {
            return new CommandResult(0, "{$package->slug} ja és a la versió {$package->targetVersion}.\n", '', $installed->durationMs);
        }

        $result = $this->wp($install, $this->settings->transferTimeoutSeconds);
        $after = trim($this->wp($current)->stdout);
        if (! $result->successful() || $after !== $package->targetVersion) {
            throw new RemoteCommandFailed("No s'ha pogut instal·lar {$package->slug} {$package->targetVersion} (ara: «{$after}»).", $result);
        }

        return $result;
    }

    public function setMaintenanceMode(bool $enabled): void
    {
        if ($this->wp(['maintenance-mode', 'is-active'])->successful() !== $enabled) {
            $this->wpOk(['maintenance-mode', $enabled ? 'activate' : 'deactivate'], 'El canvi del mode manteniment');
        }
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->verify($dump);
        $path = $this->path($dump->path);
        $sql = escapeshellarg($path.'.restore.sql');
        $this->ensureWpCli();

        $this->mustExec(
            'gunzip -c '.escapeshellarg($path)." > {$sql} && ".$this->wpCommand(['db', 'import', $path.'.restore.sql'])
            ."; rc=\$?; rm -f {$sql}; exit \$rc",
            'La restauració de la base de dades',
            $this->settings->transferTimeoutSeconds,
        );
    }

    public function restoreFiles(RemoteFile $archive): void
    {
        $this->verify($archive);

        // Extract aside, then mirror onto the docroot, protecting what the archive
        // excluded (listed inside it).
        $this->mustExec(
            'd=$(mktemp -d '.escapeshellarg($this->workDir().'/r-XXXXXX').')'
            .' && tar -xzf '.escapeshellarg($this->path($archive->path)).' -C "$d"'
            .' && test -f "$d/.wpup-excludes"'
            .' && sed "s|^|/|" "$d/.wpup-excludes" > "$d.ex" && rm -f "$d/.wpup-excludes"'
            .' && rsync -a --delete --exclude-from="$d.ex" "$d/" '.escapeshellarg(rtrim($this->target->wpPath, '/').'/')
            .'; rc=$?; rm -rf "$d" "$d.ex"; exit $rc',
            'La restauració dels fitxers',
            $this->settings->transferTimeoutSeconds,
        );
    }

    public function phpLogCursor(): LogCursor
    {
        $log = $this->phpLogPath();
        if ($log === null) {
            return new LogCursor(null, 0);
        }

        [$size, $inode] = $this->statLog($log);

        return new LogCursor($log, $size, $inode);
    }

    public function phpLogSince(LogCursor $cursor, int $maxBytes = 1_048_576): array
    {
        $log = $cursor->path ?? $this->phpLogPath();
        if ($log === null) {
            return [];
        }

        [$size, $inode] = $this->statLog($log);
        // Rotated or truncated log: read the new file from the start.
        $offset = $cursor->path === null || $inode !== $cursor->fileId || $size < $cursor->offset ? 0 : $cursor->offset;
        if ($size <= $offset) {
            return [];
        }

        $content = $this->mustExec(
            'tail -c +'.($offset + 1).' '.escapeshellarg($log).' | head -c '.max(1, $maxBytes),
            'La lectura del log de PHP',
        )->stdout;

        return array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
    }

    public function freeDiskBytes(): int
    {
        $kb = trim($this->mustExec('df -Pk "$HOME" | awk \'NR==2 {print $4}\'', "La lectura de l'espai lliure")->stdout);

        return ctype_digit($kb) ? (int) $kb * 1024 : throw new RemoteCommandFailed("Resposta inesperada de df: «{$kb}».");
    }

    public function remove(string $remotePath): void
    {
        $this->mustExec('rm -f -- '.escapeshellarg($this->path($remotePath)), "L'esborrat de «{$remotePath}»");
    }

    /**
     * Resolves a remote file name to its absolute path in the work directory.
     */
    private function path(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $name) !== 1 || str_contains($name, '..')) {
            throw new DriverException("Nom de fitxer remot no vàlid: «{$name}».");
        }

        return $this->workDir().'/'.$name;
    }

    private function workDir(): string
    {
        if ($this->home === null) {
            $home = trim($this->mustExec('printf "%s" "$HOME"', 'La lectura del directori personal')->stdout);
            if (! str_starts_with($home, '/')) {
                throw new RemoteCommandFailed("El directori personal del compte no és vàlid: «{$home}».");
            }
            $work = $home.'/'.$this->settings->workDir;
            $this->mustExec('mkdir -p '.escapeshellarg($work).' && chmod 700 '.escapeshellarg($work), 'La creació del directori de treball');
            $this->home = $home;
        }

        return $this->home.'/'.$this->settings->workDir;
    }

    /**
     * Installs the tool's pinned WP-CLI in the work directory if it is not there.
     */
    private function ensureWpCli(): void
    {
        if ($this->wpCliReady) {
            return;
        }

        $phar = $this->workDir().'/wp-cli.phar';
        $remote = $this->exec('sha256sum '.escapeshellarg($phar).' 2>/dev/null')->stdout;

        if (! str_starts_with($remote, $this->settings->wpCliSha256)) {
            $local = $this->settings->wpCliPhar;
            if (! is_file($local) || hash_file('sha256', $local) !== $this->settings->wpCliSha256) {
                throw new DriverException("Falta el WP-CLI fixat al servidor de l'eina o no és l'esperat: executa `make tools`.");
            }
            $this->upload($local, 'wp-cli.phar');
        }

        $this->wpCliReady = true;
    }

    /**
     * @param  list<string>  $args
     */
    private function wpCommand(array $args): string
    {
        $command = [$this->php(), $this->workDir().'/wp-cli.phar', '--path='.$this->target->wpPath, ...$args];

        return implode(' ', array_map(escapeshellarg(...), $command));
    }

    /**
     * @param  list<string>  $args
     */
    private function wpOk(array $args, string $what): CommandResult
    {
        $result = $this->wp($args);

        return $result->successful() ? $result : throw self::failure($what, $result);
    }

    /**
     * @param  list<string>  $args
     * @return list<array<mixed, mixed>>
     */
    private function wpJson(array $args): array
    {
        $stdout = $this->wpOk($args, 'La lectura de l\'inventari')->stdout;
        // Old sites may print PHP notices before the JSON.
        $start = strpos($stdout, '[');
        $decoded = $start === false ? null : json_decode(substr($stdout, $start), true);

        if (! is_array($decoded)) {
            throw new RemoteCommandFailed('WP-CLI ha tornat un inventari que no és JSON.');
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    private function fileInfo(string $name): RemoteFile
    {
        $path = escapeshellarg($this->path($name));
        $lines = explode("\n", trim($this->mustExec("stat -c %s {$path} && sha256sum {$path}", "La lectura de «{$name}»")->stdout));
        $sha256 = substr($lines[1] ?? '', 0, 64);

        if (! ctype_digit($lines[0]) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new RemoteCommandFailed("No s'ha pogut llegir la mida i el checksum de «{$name}».");
        }

        return new RemoteFile($name, (int) $lines[0], $sha256);
    }

    private function verify(RemoteFile $file): void
    {
        if ($this->fileInfo($file->path)->sha256 !== $file->sha256) {
            throw IntegrityCheckFailed::checksumMismatch($file->path);
        }
    }

    /**
     * The PHP error log: the domain PHP's error_log, then the Plesk logs/ files,
     * then WordPress' debug.log. An error_log that does not exist yet still counts.
     */
    private function phpLogPath(): ?string
    {
        $ini = escapeshellarg($this->php()).' -r '.escapeshellarg('echo ini_get("error_log");').' 2>/dev/null';
        $debug = escapeshellarg(rtrim($this->target->wpPath, '/').'/wp-content/debug.log');
        $path = trim($this->mustExec(
            "i=\$({$ini}); for f in \"\$i\" \"\$HOME/logs/php_error.log\" \"\$HOME/logs/error_log\" {$debug}; do"
            .' if [ -n "$f" ] && [ -f "$f" ] && [ -r "$f" ]; then printf "%s" "$f"; exit 0; fi; done;'
            .' case "$i" in /*) printf "%s" "$i" ;; esac',
            'La cerca del log de PHP',
        )->stdout);

        return $path === '' ? null : $path;
    }

    /**
     * @return array{int, string}
     */
    private function statLog(string $log): array
    {
        $out = trim($this->exec("stat -c '%s %i' ".escapeshellarg($log).' 2>/dev/null || echo "0 none"')->stdout);
        [$size, $inode] = array_pad(explode(' ', $out, 2), 2, 'none');

        return [ctype_digit($size) ? (int) $size : 0, $inode];
    }

    private function php(): string
    {
        return $this->target->phpBinary ?? 'php';
    }

    private function exec(string $command, ?int $timeoutSeconds = null): CommandResult
    {
        return $this->connection->run($command, $timeoutSeconds ?? $this->settings->commandTimeoutSeconds);
    }

    private function mustExec(string $command, string $what, ?int $timeoutSeconds = null): CommandResult
    {
        $result = $this->exec($command, $timeoutSeconds);

        return $result->successful() ? $result : throw self::failure($what, $result);
    }

    private static function failure(string $what, CommandResult $result): RemoteCommandFailed
    {
        $lines = array_values(array_filter(explode("\n", trim($result->stderr."\n".$result->stdout))));
        $reason = mb_substr((string) end($lines), 0, 300);

        return new RemoteCommandFailed("{$what} ha fallat (codi {$result->exitCode}): {$reason}", $result);
    }

    /**
     * A path relative to the WordPress root, without "..", for tar and rsync.
     */
    private static function relativePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1f]/', $path) === 1) {
            throw new DriverException("Exclusió no vàlida: «{$path}».");
        }

        return $path;
    }

    /**
     * @param  array<mixed, mixed>  $row
     */
    private static function field(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
