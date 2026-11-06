<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Data\AppliedUpdate;
use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Data\WorkArea;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Exceptions\WorkFileNotFound;
use App\Domain\Drivers\RemoteFileName;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\WebFileName;
use App\Domain\Drivers\WorkPath;
use App\Enums\CheckResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Support\PrivateDirectory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
    private const GZIP_FAILED = '__WPUP_GZIP_FAIL__';

    /**
     * WP-CLI without plugins and themes for the backup, restore and maintenance
     * commands: they must work on a site that a plugin or theme breaks with a
     * fatal error (UZEROWPUP-46). Must-use plugins still load.
     */
    private const SAFE_MODE = ['--skip-plugins', '--skip-themes'];

    private const SHELL_MARKER = 'wpup-shell-ok';

    private const REQUIRED_TOOLS = ['timeout', 'tar', 'gzip', 'gunzip', 'sha256sum', 'stat', 'df', 'mktemp', 'rsync', 'tail', 'head', 'base64', 'du', 'find', 'ln'];

    private ?string $home = null;

    private bool $wpCliReady = false;

    private ?PhpRuntime $runtime = null;

    private ?DriverException $runtimeFailure = null;

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

        if ($shell->exitCode === 127 && str_contains($shell->stderr, 'timeout')) {
            $checks[] = new ProbeCheck('eines', CheckResult::Error, 'Falta `timeout` (coreutils) al compte: l\'eina el fa servir per limitar cada comanda.');

            return new ProbeResult($checks);
        }
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

        try {
            $runtime = $this->phpRuntime();
        } catch (DriverException $e) {
            $checks[] = new ProbeCheck('php', CheckResult::Error, "No es pot executar el PHP del domini: {$e->getMessage()}");

            return new ProbeResult($checks);
        }
        $checks[] = new ProbeCheck(
            'php',
            $runtime->warnings === [] ? CheckResult::Ok : CheckResult::Warning,
            trim("{$runtime->version} ({$runtime->binary}, {$runtime->source->value}) ".implode(' ', $runtime->warnings)),
        );

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
        $readOnly = implode(' ', $this->unwritablePaths());

        return $readOnly === ''
            ? new ProbeCheck('permisos', CheckResult::Ok, "{$this->target->user} pot escriure el codi del lloc")
            : new ProbeCheck('permisos', CheckResult::Error, "{$this->target->user} no pot escriure a: {$readOnly}");
    }

    public function unwritablePaths(): array
    {
        $root = rtrim($this->target->wpPath, '/');
        $paths = [$root, $root.'/wp-content', $root.'/wp-content/plugins', $root.'/wp-content/themes'];
        $script = 'for p in '.implode(' ', array_map(escapeshellarg(...), $paths)).'; do [ -w "$p" ] || printf "%s\\n" "$p"; done';
        // A failed or timed out command is an error, never "all writable".
        $result = $this->mustExec($script, 'La comprovació dels permisos d\'escriptura', 30);

        return array_values(array_filter(array_map('trim', explode("\n", $result->stdout)), static fn (string $p): bool => $p !== ''));
    }

    public function diskUsageBytes(?int $timeoutSeconds = null): int
    {
        // GNU du: exit 1 for an unreadable subdirectory still prints the total of the rest.
        // A timeout throws from the connection (exit 124 never reaches here).
        $result = $this->exec('du -sb -- '.escapeshellarg(rtrim($this->target->wpPath, '/')), $timeoutSeconds);
        $bytes = explode("\t", trim($result->stdout))[0];

        return ctype_digit($bytes) && $result->exitCode <= 1 ? (int) $bytes : throw self::failure("La lectura de l'espai que ocupa el lloc", $result);
    }

    public function installedComponents(): InstalledComponents
    {
        $core = trim($this->wpOk(['core', 'version'], 'La lectura de la versió del core')->stdout);
        $php = $this->phpRuntime()->version;
        $db = trim($this->wpOk(['eval', 'echo $GLOBALS["wpdb"]->db_server_info();'], 'La lectura de la versió de la BD')->stdout);

        $components = [];
        foreach ($this->wpJson(['plugin', 'list', '--format=json', '--skip-update-check', '--fields=name,version,status,title']) as $plugin) {
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
        foreach ($this->wpJson(['theme', 'list', '--format=json', '--skip-update-check', '--fields=name,version,status,title']) as $theme) {
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

        // Only the tables with the site's prefix: a database may be shared. The
        // password never reaches this command line: WP-CLI hands it to mysqldump
        // through the MYSQL_PWD environment variable. umask 077: the dump is 0600.
        $this->mustExec(
            'umask 077; t=$('.$this->wpCommand(['db', 'tables', '--all-tables-with-prefix', '--format=csv', ...self::SAFE_MODE]).')'
            .' && '.$this->wpCommand(['db', 'export', $path.'.part.sql', '--single-transaction', '--quick', ...self::SAFE_MODE]).' --tables="$t" >/dev/null'
            ." && gzip -c {$sql} > {$gz} && mv {$gz} ".escapeshellarg($path)
            ."; rc=\$?; rm -f {$sql} {$gz}; exit \$rc",
            'El volcat de la base de dades',
            $this->settings->transferTimeoutSeconds,
        );

        return $this->fileInfo($remotePath);
    }

    public function databaseTables(): array
    {
        // Base tables only (not views) with the site's prefix, as the dump lists
        // them. JSON on its own line: PHP notices may come before it.
        $code = 'global $wpdb; echo json_encode($wpdb->get_col($wpdb->prepare('
            .'"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",'
            .' "BASE TABLE", $wpdb->esc_like($wpdb->base_prefix)."%")));';

        return self::names(WpCliOutput::stringList($this->wpOk(['eval', $code, ...self::SAFE_MODE], 'La llista de taules de la base de dades')->stdout));
    }

    public function dumpedTables(RemoteFile $dump): array
    {
        $path = escapeshellarg($this->path($dump->path));
        // One pass. The dump prints "CREATE TABLE `t`", or "/*!50001 CREATE TABLE
        // `t`" for a view's placeholder. A gzip that fails midway leaves a marker.
        $sed = 's/^\\(\/\*![0-9]\\{5\\} \\)\\{0,1\\}CREATE TABLE \\(IF NOT EXISTS \\)\\{0,1\\}`\\([^`]*\\)`.*/\\3/p';
        $out = $this->mustExec(
            "{ gunzip -c {$path} || { echo; echo ".self::GZIP_FAILED.'; }; } | sed -n -e '.escapeshellarg($sed).' -e '.escapeshellarg('/^'.self::GZIP_FAILED.'$/p'),
            'La lectura de les taules del volcat',
            $this->settings->transferTimeoutSeconds,
        )->stdout;

        $lines = explode("\n", $out);
        if (in_array(self::GZIP_FAILED, array_map(trim(...), $lines), true)) {
            throw new RemoteCommandFailed("El volcat «{$dump->path}» no és un gzip vàlid o està truncat.");
        }

        return self::names($lines);
    }

    public function archiveRoot(): string
    {
        return $this->physicalPath($this->target->wpPath, 'L\'arrel de WordPress');
    }

    public function workDirExclude(): ?string
    {
        $root = rtrim($this->archiveRoot(), '/').'/';
        $work = $this->physicalPath($this->workDir(), 'El directori de treball');

        return str_starts_with($work.'/', $root) && $work.'/' !== $root ? substr($work, strlen($root)) : null;
    }

    /**
     * `cd … && pwd -P` on the site: the real directory, as tar and the web see it.
     */
    private function physicalPath(string $path, string $what): string
    {
        $out = trim($this->mustExec('cd '.escapeshellarg($path).' && pwd -P', $what)->stdout);

        return str_starts_with($out, '/') ? $out : throw new RemoteCommandFailed("{$what} no té una ruta física vàlida: «{$out}».");
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
        // umask 077: the archive is 0600, in the 0700 work directory.
        $this->mustExec(
            'umask 077; m=$(mktemp -d '.escapeshellarg($this->workDir().'/m-XXXXXX').') && '.$manifest
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

    public function applyUpdate(UpdatePackage $package): AppliedUpdate
    {
        $install = $this->installCommand($package);
        [$before, $beforeStatus] = $this->componentState($package->type, $package->slug);
        if ($before !== null && $before->version === $package->targetVersion) {
            return new AppliedUpdate(new CommandResult(0, "{$package->slug} ja és a la versió {$package->targetVersion}.\n", '', 0), $before, true);
        }

        // One update at a time per site (lock): a package left here is a dead attempt's.
        $work = escapeshellarg($this->workDir());
        $this->mustExec("rm -f -- {$work}/package-*.zip {$work}/package-*.zip.part", "L'esborrat dels paquets antics");
        $name = 'package-'.bin2hex(random_bytes(8)).'.zip';
        try {
            $this->uploadPackage($package, $name);
            $result = $this->wp([...$install, $this->path($name), ...self::SAFE_MODE], $this->settings->packageTimeoutSeconds);
        } finally {
            $this->removeUploadedPackage($name);
        }

        [$after, $afterStatus] = $this->componentState($package->type, $package->slug);
        if (! $result->successful() || $after === null || $after->version !== $package->targetVersion) {
            throw new RemoteCommandFailed("No s'ha pogut instal·lar {$package->slug} {$package->targetVersion} (ara: «".($after->version ?? 'no instal·lat').'»).', $result);
        }
        if ($before !== null && $afterStatus !== $beforeStatus) {
            throw new RemoteCommandFailed("La instal·lació de {$package->slug} n'ha canviat l'estat d'activació (de «{$beforeStatus}» a «{$afterStatus}»).", $result);
        }

        return new AppliedUpdate($result, $after, false);
    }

    public function installedState(ComponentType $type, string $slug): ?ComponentVersion
    {
        return $this->componentState($type, $slug)[0];
    }

    /**
     * Uploads the package to $name (through "$name.part") and checks, on the
     * site, the bytes that arrived against the package's size and SHA-256:
     * the local file is not hashed again (the caller has just verified it).
     */
    private function uploadPackage(UpdatePackage $package, string $name): void
    {
        $part = $this->path($name.'.part');
        $this->transfer->upload($package->localPath, $part, $this->settings->packageTimeoutSeconds);
        $stat = $this->statFile($part, true, $name, $this->settings->packageTimeoutSeconds);
        if ($stat === null || $stat[0] !== $package->sizeBytes || ! hash_equals($package->sha256, (string) $stat[2])) {
            throw IntegrityCheckFailed::checksumMismatch($name);
        }
        $this->mustExec('mv -f -- '.escapeshellarg($part).' '.escapeshellarg($this->path($name)), 'La pujada del paquet');
    }

    /**
     * Never hides the install's own result or error: a failed removal is only
     * logged (exit code or exception class, never output) and the file is left
     * for SiteLocalTarget::purgeStale() and the next applyUpdate().
     */
    private function removeUploadedPackage(string $name): void
    {
        try {
            $result = $this->exec('rm -f -- '.escapeshellarg($this->path($name)).' '.escapeshellarg($this->path($name.'.part')));
            if (! $result->successful()) {
                Log::warning('Could not remove an uploaded package from the site; it is left for the stale cleanup.', ['site' => $this->target->label(), 'file' => $name, 'exit_code' => $result->exitCode]);
            }
        } catch (DriverException $e) {
            Log::warning('Could not remove an uploaded package from the site; it is left for the stale cleanup.', ['site' => $this->target->label(), 'file' => $name, 'error' => $e::class]);
        }
    }

    /**
     * The installer of each component type, before the package path. It never
     * activates anything. The core keeps its target, so WP-CLI removes the
     * files the new release no longer has.
     *
     * @return list<string>
     */
    private function installCommand(UpdatePackage $package): array
    {
        return match ($package->type) {
            ComponentType::Core => ['core', 'update', '--version='.$package->targetVersion],
            ComponentType::Plugin, ComponentType::Woocommerce => ['plugin', 'install', '--force'],
            ComponentType::Theme => ['theme', 'install', '--force'],
            default => throw new DriverException("El driver SSH no instal·la paquets de tipus «{$package->type->value}»."),
        };
    }

    /**
     * The component as installedComponents() reports it, with its raw WP-CLI
     * status (to compare activation exactly), or [null, ''] when it is not
     * installed. One WP-CLI call.
     *
     * @return array{ComponentVersion|null, string}
     */
    private function componentState(ComponentType $type, string $slug): array
    {
        if ($type === ComponentType::Core) {
            $version = trim($this->wpOk(['core', 'version', ...self::SAFE_MODE], 'La lectura de la versió del core')->stdout);

            return [new ComponentVersion(ComponentType::Core, 'wordpress', $version), ''];
        }
        $kind = match ($type) {
            ComponentType::Plugin, ComponentType::Woocommerce => 'plugin',
            ComponentType::Theme => 'theme',
            default => throw new DriverException("El driver SSH no llegeix components de tipus «{$type->value}» un per un."),
        };
        foreach ($this->wpJson([$kind, 'list', '--name='.$slug, '--fields=name,version,status,title', '--format=json', '--skip-update-check', ...self::SAFE_MODE]) as $row) {
            if (self::field($row, 'name') === $slug) {
                $status = self::field($row, 'status');
                $active = $kind === 'theme' ? $status !== 'inactive' : str_starts_with($status, 'active');

                return [new ComponentVersion($type, $slug, self::field($row, 'version'), $active, self::field($row, 'title')), $status];
            }
        }

        return [null, ''];
    }

    public function setMaintenanceMode(bool $enabled): void
    {
        if ($this->wp(['maintenance-mode', 'is-active', ...self::SAFE_MODE])->successful() !== $enabled) {
            $this->wpOk(['maintenance-mode', $enabled ? 'activate' : 'deactivate', ...self::SAFE_MODE], 'El canvi del mode manteniment');
        }
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->verify($dump);
        $path = $this->workPath($dump->path);
        // A plain name of the work directory, never next to a stored object.
        $plain = $this->path('restore-'.bin2hex(random_bytes(8)).'.sql');
        $sql = escapeshellarg($plain);
        $this->ensureWpCli();

        // umask 077: the decompressed dump holds the whole database.
        $this->mustExec(
            'umask 077; gunzip -c '.escapeshellarg($path)." > {$sql} && ".$this->wpCommand(['db', 'import', $plain, ...self::SAFE_MODE])
            ."; rc=\$?; rm -f {$sql}; exit \$rc",
            'La restauració de la base de dades',
            $this->settings->transferTimeoutSeconds,
        );
    }

    public function archiveExcludes(RemoteFile $archive): array
    {
        $out = $this->mustExec(
            'tar -xzOf '.escapeshellarg($this->workPath($archive->path)).' .wpup-excludes',
            "La lectura de les exclusions de l'arxiu",
            $this->settings->transferTimeoutSeconds,
        )->stdout;

        return array_values(array_filter(array_map(trim(...), explode("\n", $out)), static fn (string $l): bool => $l !== ''));
    }

    public function restoreFiles(RemoteFile $archive): void
    {
        $this->verify($archive);

        // Extract aside, then mirror onto the docroot, protecting what the archive
        // excluded (listed inside it).
        $this->mustExec(
            'd=$(mktemp -d '.escapeshellarg($this->workDir().'/r-XXXXXX').')'
            .' && tar -xzf '.escapeshellarg($this->workPath($archive->path)).' -C "$d"'
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

    public function workArea(): WorkArea
    {
        $workDir = $this->physicalPath($this->workDir(), 'El directori de treball');

        return new WorkArea($this->physicalPath((string) $this->home, 'El directori personal'), $workDir);
    }

    public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile
    {
        $stat = $this->statFile($this->workPath($path), $withChecksum, $path);

        return $stat === null ? null : new WorkFile($path, ...$stat);
    }

    public function listWorkFiles(string $directory): array
    {
        $dir = $directory === '' ? $this->workDir() : $this->workPath($directory);

        return FileOutput::listing($directory, $this->mustExec(
            FileOutput::listCommand(escapeshellarg($dir)),
            'El llistat de «'.($directory === '' ? '.' : $directory).'»',
        )->stdout);
    }

    public function moveWorkFile(string $from, string $to): WorkFile
    {
        $source = escapeshellarg($this->workPath($from));
        $target = escapeshellarg($this->workPath($to));
        $parent = str_contains($to, '/') ? escapeshellarg($this->workPath(substr($to, 0, (int) strrpos($to, '/')))) : null;

        // `ln -T` fails if the target exists (also a directory) and creates it
        // atomically: never an overwrite, never a partial object under $to.
        $out = trim($this->mustExec(
            "umask 077; [ -f {$source} ] && [ ! -L {$source} ]"
            .($parent === null ? '' : " && mkdir -p -- {$parent} && chmod 700 -- {$parent}")
            ." && ln -T -- {$source} {$target} && rm -f -- {$source} && chmod 600 -- {$target} && stat -c '%s %Y' -- {$target}",
            "El trasllat de «{$from}» a «{$to}»",
        )->stdout);

        [$size, $mtime] = array_pad(explode(' ', $out, 2), 2, '');
        if (! ctype_digit($size) || ! ctype_digit($mtime)) {
            throw new RemoteCommandFailed("No s'ha pogut llegir la mida de «{$to}».");
        }

        return new WorkFile($to, (int) $size, (int) $mtime);
    }

    /**
     * Copies the file with rsync (resumable, checksummed transfer) to a private
     * 0700 directory under the staging directory of the tool server (never
     * /tmp), opens it and deletes it right away: the open handle keeps the
     * bytes readable and nothing stays on disk afterwards.
     */
    public function openWorkFile(string $path)
    {
        $remote = $this->workPath($path);
        if (! $this->exec('test -f '.escapeshellarg($remote).' && test ! -L '.escapeshellarg($remote))->successful()) {
            throw WorkFileNotFound::at($path);
        }

        try {
            $dir = PrivateDirectory::ensure(PrivateDirectory::ensure($this->settings->stagingDir).'/read-'.bin2hex(random_bytes(8)));
        } catch (RuntimeException $e) {
            throw new DriverException($e->getMessage());
        }

        try {
            $this->transfer->download($remote, $dir.'/object');
            $stream = fopen($dir.'/object', 'rb');

            return $stream !== false ? $stream : throw new DriverException("No s'ha pogut obrir la còpia local de «{$path}».");
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function removeWorkFile(string $path): void
    {
        $command = 'rm -f -- '.escapeshellarg($this->workPath($path));
        // Then the directories it leaves empty, deepest first (rmdir never removes a non-empty one).
        $prune = [];
        for ($dir = dirname($path); $dir !== '.'; $dir = dirname($dir)) {
            $prune[] = 'rmdir -- '.escapeshellarg($this->workPath($dir)).' 2>/dev/null';
        }
        if ($prune !== []) {
            $command .= ' && { '.implode(' && ', $prune).'; true; }';
        }

        $this->mustExec($command, "L'esborrat de «{$path}»");
    }

    /**
     * Resolves a WorkPath to its absolute path in the work directory.
     */
    private function workPath(string $path): string
    {
        return $this->workDir().'/'.WorkPath::valid($path);
    }

    /**
     * Resolves a remote file name to its absolute path in the work directory.
     */
    private function path(string $name): string
    {
        return $this->workDir().'/'.RemoteFileName::valid($name);
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
        [$size, , $sha256] = $this->statFile($this->path($name), true, $name)
            ?? throw new RemoteCommandFailed("El fitxer «{$name}» no existeix al lloc.");

        return new RemoteFile($name, $size, (string) $sha256);
    }

    /**
     * Size, mtime and (when asked) SHA-256 of a regular file, computed on the site.
     *
     * @return array{int, int, string|null}|null
     */
    private function statFile(string $absolute, bool $withChecksum, string $name, ?int $timeoutSeconds = null): ?array
    {
        $result = $this->mustExec(
            FileOutput::statCommand(escapeshellarg($absolute), $withChecksum),
            "La lectura de «{$name}»",
            $timeoutSeconds ?? ($withChecksum ? $this->settings->transferTimeoutSeconds : null),
        );

        return FileOutput::stat($result->stdout, $withChecksum, $name);
    }

    /**
     * The file (a WorkPath) still holds the expected bytes, computed on the site.
     */
    private function verify(RemoteFile $file): void
    {
        $stat = $this->statFile($this->workPath($file->path), true, $file->path)
            ?? throw new RemoteCommandFailed("El fitxer «{$file->path}» no existeix al lloc.");
        if ($stat[2] !== $file->sha256 || $stat[0] !== $file->sizeBytes) {
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

    public function putWebFile(string $name, string $contents): void
    {
        // noclobber: never replace a file of the site.
        $this->mustExec(
            'set -C; umask 022; printf %s '.escapeshellarg(base64_encode($contents)).' | base64 -d > '.escapeshellarg($this->webPath($name)),
            "L'escriptura de {$name} al lloc",
        );
    }

    public function removeWebFile(string $name): void
    {
        $this->mustExec('rm -f -- '.escapeshellarg($this->webPath($name)), "L'esborrat de {$name} del lloc");
    }

    private function webPath(string $name): string
    {
        return rtrim($this->target->wpPath, '/').'/'.WebFileName::valid($name);
    }

    public function phpRuntime(): PhpRuntime
    {
        // A failed detection is remembered too: every WP-CLI call needs the
        // binary, and repeating the remote lookups would only fail again.
        if ($this->runtimeFailure !== null) {
            throw $this->runtimeFailure;
        }

        try {
            return $this->runtime ??= $this->detectPhp();
        } catch (DriverException $e) {
            throw $this->runtimeFailure = $e;
        }
    }

    private function php(): string
    {
        return $this->phpRuntime()->binary;
    }

    /**
     * The domain's handler on Plesk, else the binary configured for the site,
     * else the account's default `php` (UZEROWPUP-29). A configured binary that
     * disagrees with the handler is overridden, with a warning.
     */
    private function detectPhp(): PhpRuntime
    {
        $warnings = [];
        $configured = $this->target->phpBinary;
        $handler = null;

        if ($this->target->pleskDomain !== null) {
            // What the web was last seen serving wins over the pools (stale ones
            // stay behind when a domain changes PHP version in Plesk).
            $hint = $this->target->webPhpMinor;
            $handler = ($hint === null ? null : $this->binaryForMinor($hint)) ?? $this->pleskHandlerBinary($warnings);
        }

        if ($handler !== null) {
            try {
                $version = $this->phpVersionOf($handler);

                if ($configured !== null && $configured !== $handler) {
                    $warnings[] = "El PHP configurat al lloc ({$configured}) no és el del domini a Plesk ({$handler}): es fa servir el del domini.";
                }

                return new PhpRuntime($version, $handler, PhpVersionSource::PleskHandler, $warnings);
            } catch (RemoteCommandFailed) {
                $warnings[] = "El PHP del pool del domini ({$handler}) no s'ha pogut executar.";
            }
        }

        if ($configured !== null) {
            try {
                return new PhpRuntime($this->phpVersionOf($configured), $configured, PhpVersionSource::Configured, $warnings);
            } catch (RemoteCommandFailed) {
                $warnings[] = "El PHP configurat al lloc ({$configured}) no s'ha pogut executar.";
            }
        }

        $warnings[] = PhpRuntime::UNVERIFIED_WARNING;

        try {
            return new PhpRuntime($this->phpVersionOf('php'), 'php', PhpVersionSource::Cli, $warnings);
        } catch (RemoteCommandFailed $e) {
            throw new RemoteCommandFailed(implode(' ', [...$warnings, $e->getMessage()]), $e->result);
        }
    }

    /**
     * Finds the domain's PHP-FPM pool as the subscription user (never root):
     * Plesk's own PHP builds (/opt/plesk/php/X.Y) or the OS vendor's (/etc/php/X.Y).
     * Only readable directory listings are needed, not the pool files' contents.
     *
     * @param  list<string>  $warnings
     */
    private function pleskHandlerBinary(array &$warnings): ?string
    {
        $domain = (string) $this->target->pleskDomain;
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1) {
            $warnings[] = "El domini «{$domain}» no és vàlid: no es busca el seu PHP a Plesk.";

            return null;
        }

        $globs = [];
        foreach (array_unique([$domain, (string) preg_replace('/^www\./', '', $domain)]) as $name) {
            $file = escapeshellarg($name.'.conf');
            $globs[] = "/opt/plesk/php/*/etc/php-fpm.d/{$file}";
            $globs[] = "/etc/php/*/fpm/pool.d/{$file}";
        }
        $found = $this->exec('for f in '.implode(' ', $globs).'; do [ -e "$f" ] && stat -c "%Y %n" -- "$f"; done; true')->stdout;

        /** @var array<string, int> $binaries binary => newest pool mtime */
        $binaries = [];
        foreach (array_filter(explode("\n", $found)) as $line) {
            [$mtime, $path] = array_pad(explode(' ', trim($line), 2), 2, '');
            $binary = match (true) {
                preg_match('#^/opt/plesk/php/(\d+\.\d+)/etc/php-fpm\.d/#', $path, $m) === 1 => "/opt/plesk/php/{$m[1]}/bin/php",
                preg_match('#^/etc/php/(\d+\.\d+)/fpm/pool\.d/#', $path, $m) === 1 => "/usr/bin/php{$m[1]}",
                default => null,
            };
            if ($binary !== null && ctype_digit($mtime)) {
                $binaries[$binary] = max((int) $mtime, $binaries[$binary] ?? 0);
            }
        }

        if ($binaries === []) {
            $warnings[] = "No s'ha trobat el pool de PHP-FPM del domini {$domain} a Plesk (handler FastCGI o sense permís de lectura).";

            return null;
        }

        // Plesk leaves the old pool behind when a domain changes PHP version
        // (common, so no warning): the newest one is the last change. The HTTP
        // probe confirms it, and WP-CLI switches if the web says otherwise.
        arsort($binaries);

        return (string) array_key_first($binaries);
    }

    public function adoptWebPhp(string $minor): ?PhpRuntime
    {
        $binary = $this->binaryForMinor($minor);
        if ($binary === null) {
            return null;
        }

        $version = $this->phpVersionOf($binary);
        if (PhpRuntime::minorOf($version) !== $minor) {
            return null;
        }

        $previous = $this->phpRuntime();
        $this->wpCliReady = false;

        return $this->runtime = new PhpRuntime($version, $binary, $previous->source, [
            ...$previous->warnings,
            "El web serveix PHP {$minor}: WP-CLI passa de {$previous->binary} a {$binary}.",
        ]);
    }

    /**
     * The installed binary of PHP "X.Y" (Plesk's builds first, then the OS
     * vendor's), or null.
     */
    private function binaryForMinor(string $minor): ?string
    {
        if (preg_match('/^\d+\.\d+$/', $minor) !== 1) {
            return null;
        }

        foreach (["/opt/plesk/php/{$minor}/bin/php", "/usr/bin/php{$minor}"] as $binary) {
            if ($this->exec('test -x '.escapeshellarg($binary))->successful()) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * PHP_VERSION of $binary. Startup warnings that a php.ini sends to stdout
     * come before it, so only the last line counts, and it must be a version.
     */
    private function phpVersionOf(string $binary): string
    {
        $result = $this->mustExec(escapeshellarg($binary).' -r '.escapeshellarg('echo PHP_VERSION;'), "La lectura de la versió de PHP ({$binary})");
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $result->stdout))));
        $version = (string) end($lines);

        return PhpRuntime::isVersion($version)
            ? $version
            : throw new RemoteCommandFailed("La lectura de la versió de PHP ({$binary}) no ha tornat cap versió.", $result);
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
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function names(array $lines): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), $lines), static fn (string $n): bool => $n !== '')));
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
