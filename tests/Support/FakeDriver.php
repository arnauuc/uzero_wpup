<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Backups\OrderWatermark;
use App\Domain\Backups\ShopWritesReader;
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
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Exceptions\WorkFileNotFound;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\WebFileName;
use App\Domain\Drivers\WorkPath;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteState;
use App\Enums\CheckResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;

/**
 * In-memory WordPress site for unit tests (AGENTS.md: FakeDriver in unit tests,
 * Docker fixture sites in integration tests). It passes SiteDriverContract, so
 * code tested against it behaves the same with the real drivers.
 */
class FakeDriver implements SiteDriver
{
    /** @var array<string, string> remote path => content */
    public array $files = [];

    /** @var array<string, string> options table */
    public array $options = ['blogname' => 'Botiga de prova', 'siteurl' => 'https://fake.test'];

    /** @var array<string, ComponentVersion> "type:slug" => component */
    public array $components = [];

    /** @var list<string> */
    public array $phpLog = [];

    public bool $maintenance = false;

    /** @var array<string, string> files in the WordPress root (putWebFile) */
    public array $webFiles = [];

    /** @var array<string, string> every file ever written with putWebFile, also removed ones */
    public array $webFilesWritten = [];

    /** Simulates an upload that fails after a partial file was written. */
    public bool $failWebUpload = false;

    /** @var array<string, string> other installed PHP versions, "X.Y" => PHP_VERSION (adoptWebPhp) */
    public array $installedPhp = [];

    /** Null: the configured PHP, with $phpVersion. */
    public ?PhpRuntime $phpRuntime = null;

    public string $dbServerVersion = '10.11.9-MariaDB';

    public string $activeTheme = 'storefront';

    public ?string $parentTheme = null;

    /** @var array<string, array<mixed>> site transients by name (update_plugins, update_themes) */
    public array $transients = [];

    /** @var array<string, string> "Requires Plugins" header of installed plugins, by plugin file */
    public array $requiresPluginsHeaders = [];

    /** @var list<array<string, string>> answer of `wp core check-update` */
    public array $coreUpdates = [];

    /** Thrown by every wp() call when set (lost connection, failed command…). */
    public ?\Throwable $wpFailure = null;

    /** Makes `wp core check-update` fail. */
    public bool $coreCheckFails = false;

    /** Makes `wp transient get` fail with this stderr (exit 1). */
    public ?string $transientError = null;

    /** @var list<array{hook: string, timestamp: int}> scheduled WP-Cron events */
    public array $cronEvents = [];

    public bool $cronDisabled = false;

    /** The site's clock for the state snippet; null: the real time. */
    public ?int $siteTime = null;

    /** `$upgrading` of the .maintenance file written by setMaintenanceMode(); null: the site's time. */
    public ?int $maintenanceUpgrading = null;

    /** The .maintenance file exists but has no `$upgrading` timestamp. */
    public bool $maintenanceWithoutTimestamp = false;

    public ?string $wcDbVersion = '9.8.0';

    public ?bool $wcDbUpdateNeeded = false;

    /** The WooCommerce part of the snippet fails (a Throwable inside it). */
    public bool $wcFails = false;

    /** @var list<array{file: string, theme_version: string, core_version: string}> what the snippet reports as overridden templates */
    public array $wcOverriddenTemplates = [];

    /** Raw stdout of the whole state snippet instead of the modelled answer (notices, broken JSON…). */
    public ?string $snippetOutput = null;

    /** @var list<string> WP-CLI commands (first arguments joined by space, e.g. "cron event list") that fail with exit 1 */
    public array $failingCommands = [];

    /** Makes freeDiskBytes() throw a DriverException. */
    public bool $diskFails = false;

    /** Bytes the WordPress root uses on disk (diskUsageBytes()). */
    public int $filesBytes = 200_000_000;

    /** Makes diskUsageBytes() throw a DriverException. */
    public bool $diskUsageFails = false;

    /** @var list<int|null> the timeout of every diskUsageBytes() call */
    public array $duTimeouts = [];

    /** Thrown by installedComponents() when set (WP-CLI broken, lost connection…). */
    public ?\Throwable $installedFailure = null;

    /** Makes unwritablePaths() throw a DriverException. */
    public bool $unwritableFails = false;

    /** @var list<string> paths unwritablePaths() reports */
    public array $unwritable = [];

    /** @var array<string, int> table => bytes, what `wp db size --all-tables-with-prefix` reports */
    public array $dbTables = ['wp_options' => 300_000, 'wp_posts' => 20_000_000, 'wp_postmeta' => 30_000_000];

    /** Where the fake site keeps wp-config.php (`wp config path`). */
    public string $configPath = '/var/www/html/wp-config.php';

    /** The physical root archiveFiles() archives. */
    public string $archiveRoot = '/var/www/html';

    /** Physical home of the account (workArea()); the work dir is "{home}/.wpup" unless $workDirInRoot. */
    public string $home = '/var/www/vhosts/fake.test';

    /** Physical work directory reported by workArea(), when set. */
    public ?string $workDirPath = null;

    /** @var array<string, int> work path => mtime reported by statWorkFile()/listWorkFiles() */
    public array $fileTimes = [];

    /** Where the tool's work dir is relative to the root, null: outside it. */
    public ?string $workDirInRoot = null;

    /** @var list<string> tables dumpDatabase() leaves out of the dump (a broken mysqldump) */
    public array $dumpOmits = [];

    /** Makes databaseTables() throw a DriverException. */
    public bool $tablesFail = false;

    /** @var list<string> names of the driver methods that change the site, in call order (UZEROWPUP-36) */
    public array $writeCalls = [];

    /** @var list<list<string>> every WP-CLI call, for assertions */
    public array $wpCalls = [];

    /** @var list<array{id: int, type: string, status: string, created: string, modified: string}> orders (HPOS wc_orders), in the dump */
    public array $orders = [];

    /** @var list<array{id: int, registered: string, roles: list<string>}> users, in the dump */
    public array $users = [];

    /** Raw stdout of ShopWritesReader::SNIPPET instead of the modelled answer. */
    public ?string $shopWritesOutput = null;

    public function __construct(
        public string $coreVersion = '6.8.3',
        public string $phpVersion = '8.3.14',
        public int $freeDisk = 10_000_000_000,
    ) {
        $this->addComponent(new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0'));
        $this->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', '1.6.3'));
        $this->addComponent(new ComponentVersion(ComponentType::Theme, 'storefront', '4.6.0'));
    }

    public function addComponent(ComponentVersion $component): void
    {
        $this->components[$component->type->value.':'.$component->slug] = $component;
    }

    public function probe(): ProbeResult
    {
        return new ProbeResult([
            new ProbeCheck('connexió', CheckResult::Ok, 'fake'),
            new ProbeCheck('wp-cli', CheckResult::Ok, 'fake'),
            new ProbeCheck('php', CheckResult::Ok, $this->phpRuntime()->version),
        ]);
    }

    public function putWebFile(string $name, string $contents): void
    {
        $this->writeCalls[] = 'putWebFile';
        WebFileName::valid($name);
        if (isset($this->webFiles[$name])) {
            throw new DriverException("{$name} ja existeix");
        }
        $this->webFiles[$name] = $contents;
        $this->webFilesWritten[$name] = $contents;

        if ($this->failWebUpload) {
            throw new DriverException('connexió perduda a mitja escriptura');
        }
    }

    public function removeWebFile(string $name): void
    {
        $this->writeCalls[] = 'removeWebFile';
        WebFileName::valid($name);
        unset($this->webFiles[$name]);
    }

    public function phpRuntime(): PhpRuntime
    {
        return $this->phpRuntime ?? new PhpRuntime($this->phpVersion, 'php', PhpVersionSource::Configured);
    }

    public function adoptWebPhp(string $minor): ?PhpRuntime
    {
        $version = $this->installedPhp[$minor] ?? null;
        if ($version === null) {
            return null;
        }

        $previous = $this->phpRuntime();

        return $this->phpRuntime = new PhpRuntime($version, "/opt/plesk/php/{$minor}/bin/php", $previous->source, [...$previous->warnings, "WP-CLI passa a PHP {$minor}."]);
    }

    public function installedComponents(): InstalledComponents
    {
        if ($this->installedFailure !== null) {
            throw $this->installedFailure;
        }

        return new InstalledComponents($this->coreVersion, $this->phpRuntime()->version, $this->dbServerVersion, array_values($this->components), $this->activeTheme, $this->parentTheme);
    }

    public function wp(array $args, ?int $timeoutSeconds = null): CommandResult
    {
        $this->wpCalls[] = $args;

        if ($this->wpFailure !== null) {
            throw $this->wpFailure;
        }

        foreach ($this->failingCommands as $failing) {
            if (implode(' ', array_slice($args, 0, count(explode(' ', $failing)))) === $failing) {
                return new CommandResult(1, '', "Error: {$failing} failed\n", 1);
            }
        }

        return match (true) {
            $args === ['eval', SiteState::SNIPPET] => $this->ok($this->snippetOutput ?? $this->stateSnippet()),
            count($args) === 4 && $args[0] === 'eval' && str_ends_with($args[1], ShopWritesReader::SNIPPET) && array_slice($args, 2) === ['--skip-plugins', '--skip-themes'] => $this->ok($this->shopWritesOutput ?? $this->shopWrites($args[1])),
            $args === ['eval', PendingUpdates::REQUIRES_PLUGINS_SNIPPET] => $this->ok("\n".json_encode((object) $this->requiresPluginsHeaders)."\n"),
            $args === ['db', 'size', '--size_format=b', '--all-tables-with-prefix', '--format=json'] => $this->ok((string) json_encode(array_map(
                static fn (string $name, int $bytes): array => ['Name' => $name, 'Size' => "{$bytes} B"],
                array_keys($this->dbTables),
                array_values($this->dbTables),
            ))),
            $args === ['config', 'path'] => $this->ok($this->configPath."\n"),
            count($args) === 2 && $args[0] === 'eval' && preg_match("/^echo realpath\\(dirname\\('(.*)'\\)\\);\$/", $args[1], $m) === 1 => $this->ok(dirname($m[1])),
            $args === ['core', 'version'] => $this->ok($this->coreVersion."\n"),
            $args === ['cache', 'flush'] => $this->ok("Success: The cache was flushed.\n"),
            $args === ['maintenance-mode', 'activate', '--force', '--skip-plugins', '--skip-themes'] => $this->refreshMaintenance(),
            in_array($args, [['maintenance-mode', 'is-active'], ['maintenance-mode', 'is-active', '--skip-plugins', '--skip-themes']], true) => $this->maintenance
                ? $this->ok('')
                : new CommandResult(1, '', '', 1),
            $args === ['core', 'check-update', '--format=json'] => $this->coreCheckFails
                ? new CommandResult(1, '', "Error: could not check\n", 1)
                : $this->ok((string) json_encode($this->coreUpdates)),
            count($args) === 5 && array_slice($args, 0, 2) === ['transient', 'get'] && array_slice($args, 3) === ['--network', '--format=json'] => $this->transientError !== null
                ? new CommandResult(1, '', $this->transientError, 1)
                : (isset($this->transients[$args[2]])
                    ? $this->ok((string) json_encode($this->transients[$args[2]]))
                    // Real WP-CLI: warning on stderr, empty stdout, exit 0.
                    : new CommandResult(0, '', "Warning: Transient with key \"{$args[2]}\" is not set.\n", 1)),
            count($args) === 3 && $args[0] === 'option' && $args[1] === 'get' => isset($this->options[$args[2]])
                ? $this->ok($this->options[$args[2]]."\n")
                : new CommandResult(1, '', "Error: Could not get '{$args[2]}' option.\n", 1),
            count($args) === 5 && array_slice($args, 0, 2) === ['option', 'pluck'] && $args[4] === '--format=json' => $this->pluckOption($args[2], $args[3]),
            count($args) === 4 && array_slice($args, 0, 2) === ['option', 'get'] && $args[3] === '--format=json' => isset($this->options[$args[2]])
                ? $this->ok($this->options[$args[2]]."\n")
                : new CommandResult(1, '', "Error: Could not get '{$args[2]}' option.\n", 1),
            count($args) === 4 && $args[0] === 'option' && $args[1] === 'update' => $this->updateOption($args[2], $args[3]),
            count($args) === 2 && $args[0] === 'eval' && preg_match('/^error_log\("(.*)"\);$/', $args[1], $m) === 1 => $this->logLine($m[1]),
            default => new CommandResult(1, '', 'Error: FakeDriver does not support: '.implode(' ', $args)."\n", 1),
        };
    }

    /**
     * `wp option pluck <option> <key> --format=json`: the option holds JSON in the fake.
     */
    private function pluckOption(string $option, string $key): CommandResult
    {
        $data = isset($this->options[$option]) ? json_decode($this->options[$option], true) : null;
        if (! is_array($data) || ! array_key_exists($key, $data)) {
            return new CommandResult(1, '', "Error: Could not get '{$option}' option.\n", 1);
        }

        return $this->ok(json_encode($data[$key])."\n");
    }

    public function dumpDatabase(string $remotePath): RemoteFile
    {
        $this->writeCalls[] = 'dumpDatabase';

        return $this->store($remotePath, (string) json_encode([
            'options' => $this->options,
            'tables' => array_values(array_diff(array_keys($this->dbTables), $this->dumpOmits)),
            'orders' => $this->orders,
            'users' => $this->users,
        ]));
    }

    public function databaseTables(): array
    {
        if ($this->tablesFail) {
            throw new DriverException('wp db tables ha fallat');
        }

        return array_map(strval(...), array_keys($this->dbTables));
    }

    public function dumpedTables(RemoteFile $dump): array
    {
        $data = json_decode($this->files[$dump->path] ?? throw new RemoteCommandFailed("El fitxer «{$dump->path}» no existeix."), true);
        $tables = is_array($data) && is_array($data['tables'] ?? null) ? $data['tables'] : [];

        return array_values(array_filter($tables, is_string(...)));
    }

    public function archiveRoot(): string
    {
        return $this->archiveRoot;
    }

    public function workDirExclude(): ?string
    {
        return $this->workDirInRoot;
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        $this->writeCalls[] = 'archiveFiles';

        return $this->store($remotePath, serialize(['core' => $this->coreVersion, 'components' => $this->components, 'excludes' => $excludes]));
    }

    public function download(RemoteFile $remote, string $localPath): void
    {
        $content = $this->files[$remote->path] ?? throw new RemoteCommandFailed("El fitxer «{$remote->path}» no existeix.");

        if (hash('sha256', $content) !== $remote->sha256) {
            throw IntegrityCheckFailed::checksumMismatch($remote->path);
        }

        file_put_contents($localPath, $content);
    }

    public function upload(string $localPath, string $remotePath): RemoteFile
    {
        $this->writeCalls[] = 'upload';

        return $this->store($remotePath, (string) file_get_contents($localPath));
    }

    public function applyUpdate(UpdatePackage $package): CommandResult
    {
        $this->writeCalls[] = 'applyUpdate';
        $content = $this->files[$package->file->path] ?? throw new RemoteCommandFailed('El paquet no és al lloc.');

        if (hash('sha256', $content) !== $package->file->sha256) {
            throw IntegrityCheckFailed::checksumMismatch($package->file->path);
        }

        if ($package->type === ComponentType::Core) {
            $this->coreVersion = $package->targetVersion;
        } else {
            $this->addComponent(new ComponentVersion($package->type, $package->slug, $package->targetVersion));
        }

        return $this->ok("Success: {$package->slug} {$package->targetVersion}\n");
    }

    /** `wp maintenance-mode activate --force`: `.maintenance` written again with the current time. */
    private function refreshMaintenance(): CommandResult
    {
        $this->writeCalls[] = 'refreshMaintenance';
        $this->maintenance = true;
        $this->maintenanceUpgrading = null;

        return $this->ok("Success: Activated Maintenance mode.\n");
    }

    public function setMaintenanceMode(bool $enabled): void
    {
        $this->writeCalls[] = 'setMaintenanceMode';
        $this->maintenance = $enabled;
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->verifyWorkFile($dump);
        $this->writeCalls[] = 'restoreDatabase';
        $data = json_decode($this->files[$dump->path], true);
        $restored = [];

        foreach (is_array($data) && is_array($data['options'] ?? null) ? $data['options'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $restored[$name] = $value;
            }
        }

        $this->options = $restored;
        /** @var array{orders?: list<array{id: int, type: string, status: string, created: string, modified: string}>, users?: list<array{id: int, registered: string, roles: list<string>}>} $data */
        $this->orders = $data['orders'] ?? [];
        $this->users = $data['users'] ?? [];
    }

    public function archiveExcludes(RemoteFile $archive): array
    {
        WorkPath::valid($archive->path);
        $data = unserialize($this->files[$archive->path] ?? throw new RemoteCommandFailed("El fitxer «{$archive->path}» no existeix."), ['allowed_classes' => [ComponentVersion::class, ComponentType::class]]);
        $excludes = is_array($data) && is_array($data['excludes'] ?? null) ? $data['excludes'] : throw new RemoteCommandFailed("L'arxiu no té llista d'exclusions.");

        return array_values(array_filter($excludes, is_string(...)));
    }

    public function restoreFiles(RemoteFile $archive): void
    {
        $this->verifyWorkFile($archive);
        $this->writeCalls[] = 'restoreFiles';
        // The mirror removes .maintenance, which no archive holds.
        $this->maintenance = false;
        $data = unserialize($this->files[$archive->path], ['allowed_classes' => [ComponentVersion::class, ComponentType::class]]);

        if (is_array($data) && is_string($data['core'] ?? null) && is_array($data['components'] ?? null)) {
            $this->coreVersion = $data['core'];
            /** @var array<string, ComponentVersion> $components */
            $components = $data['components'];
            $this->components = $components;
        }
    }

    public function phpLogCursor(): LogCursor
    {
        return new LogCursor('fake.log', count($this->phpLog));
    }

    public function phpLogSince(LogCursor $cursor, int $maxBytes = 1_048_576): array
    {
        return array_slice($this->phpLog, $cursor->offset);
    }

    public function freeDiskBytes(): int
    {
        if ($this->diskFails) {
            throw new DriverException('df ha fallat');
        }

        return $this->freeDisk;
    }

    public function diskUsageBytes(?int $timeoutSeconds = null): int
    {
        $this->duTimeouts[] = $timeoutSeconds;

        if ($this->diskUsageFails) {
            throw new DriverException('du ha fallat');
        }

        return $this->filesBytes;
    }

    public function unwritablePaths(): array
    {
        if ($this->unwritableFails) {
            throw new DriverException('la comprovació de permisos ha fallat');
        }

        return $this->unwritable;
    }

    public function remove(string $remotePath): void
    {
        $this->writeCalls[] = 'remove';
        unset($this->files[$remotePath]);
    }

    public function workArea(): WorkArea
    {
        return new WorkArea($this->home, $this->workDirPath ?? ($this->workDirInRoot === null ? $this->home.'/.wpup' : $this->archiveRoot.'/'.$this->workDirInRoot));
    }

    public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile
    {
        WorkPath::valid($path);
        if (! isset($this->files[$path])) {
            return null;
        }

        return new WorkFile($path, strlen($this->files[$path]), $this->fileTimes[$path] ?? null, $withChecksum ? hash('sha256', $this->files[$path]) : null);
    }

    public function listWorkFiles(string $directory): array
    {
        if ($directory !== '') {
            WorkPath::valid($directory);
        }
        $files = [];
        foreach ($this->files as $path => $content) {
            if ($directory === '' || str_starts_with($path, $directory.'/')) {
                $files[] = new WorkFile($path, strlen($content), $this->fileTimes[$path] ?? null);
            }
        }

        return $files;
    }

    public function moveWorkFile(string $from, string $to): WorkFile
    {
        $this->writeCalls[] = 'moveWorkFile';
        WorkPath::valid($from);
        WorkPath::valid($to);
        $content = $this->files[$from] ?? throw new RemoteCommandFailed("El fitxer «{$from}» no existeix.");
        // A file is never replaced, and a path that is a directory of others is taken too.
        if (isset($this->files[$to]) || $this->listWorkFiles($to) !== []) {
            throw new RemoteCommandFailed("«{$to}» ja existeix.");
        }

        unset($this->files[$from], $this->fileTimes[$from]);
        $this->files[$to] = $content;

        return new WorkFile($to, strlen($content));
    }

    public function openWorkFile(string $path)
    {
        WorkPath::valid($path);
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false || ! isset($this->files[$path])) {
            throw WorkFileNotFound::at($path);
        }
        fwrite($stream, $this->files[$path]);
        rewind($stream);

        return $stream;
    }

    public function removeWorkFile(string $path): void
    {
        $this->writeCalls[] = 'removeWorkFile';
        WorkPath::valid($path);
        unset($this->files[$path], $this->fileTimes[$path]);
    }

    /**
     * A file to restore from: a WorkPath that still holds the expected bytes.
     */
    private function verifyWorkFile(RemoteFile $file): void
    {
        WorkPath::valid($file->path);
        $content = $this->files[$file->path] ?? throw new RemoteCommandFailed("El fitxer «{$file->path}» no existeix.");
        if (strlen($content) !== $file->sizeBytes || hash('sha256', $content) !== $file->sha256) {
            throw IntegrityCheckFailed::checksumMismatch($file->path);
        }
    }

    private function store(string $remotePath, string $content): RemoteFile
    {
        $this->files[$remotePath] = $content;

        return new RemoteFile($remotePath, strlen($content), hash('sha256', $content));
    }

    /** What SiteState::SNIPPET prints on a site in this state. */
    public function stateSnippet(): string
    {
        $now = $this->siteTime ?? time();
        $due = [];
        foreach ($this->cronEvents as $event) {
            if ($event['timestamp'] <= $now) {
                $due[$event['timestamp']] = ($due[$event['timestamp']] ?? 0) + 1;
            }
        }

        $woocommerce = null;
        if (isset($this->components['woocommerce:woocommerce']) && $this->components['woocommerce:woocommerce']->active) {
            $woocommerce = $this->wcFails ? ['failed' => true] : [
                'db_version' => $this->wcDbVersion,
                'db_update_needed' => $this->wcDbUpdateNeeded,
                'overridden_templates' => $this->wcOverriddenTemplates,
            ];
        }

        return (string) json_encode([
            'now' => $now,
            'cron_disabled' => $this->cronDisabled,
            'cron_overdue' => array_map(static fn (int $ts, int $n): array => [$ts, $n], array_keys($due), $due),
            'maintenance' => [
                'file' => $this->maintenance,
                'upgrading' => $this->maintenance && ! $this->maintenanceWithoutTimestamp ? ($this->maintenanceUpgrading ?? $now) : null,
            ],
            'woocommerce' => $woocommerce,
        ]);
    }

    /**
     * What ShopWritesReader::SNIPPET prints: only wc_orders and users exist,
     * and a row is written after the watermark by a higher ID or a later date.
     */
    private function shopWrites(string $code): string
    {
        /** @var array{mode: string, limit?: int, since?: array<string, array{max_id: int, max: array<string, string|null>}>} $in */
        $in = preg_match("/^\\\$in = json_decode\\(base64_decode\\('([A-Za-z0-9+\\/=]*)'\\), true\\);\n/", $code, $m) === 1
            ? json_decode((string) base64_decode($m[1]), true) : ['mode' => 'watermark'];
        $now = $this->siteTime ?? time();
        $max = static function (array $rows, string $key): mixed {
            $values = array_column($rows, $key);

            return $values === [] ? null : max($values);
        };
        // Any change of a user row, also in place (the fake keeps the roles there).
        $fingerprint = count($this->users).':'.crc32((string) json_encode($this->users));
        $tables = array_fill_keys(OrderWatermark::TABLES, ['present' => false]);
        if (($in['since'] ?? null) === null) {
            $tables['wc_orders'] = ['present' => true, 'max_id' => $max($this->orders, 'id'), 'max' => ['date_created_gmt' => $max($this->orders, 'created'), 'date_updated_gmt' => $max($this->orders, 'modified')]];
            $tables['users'] = ['present' => true, 'max_id' => $max($this->users, 'id'), 'max' => ['user_registered' => $max($this->users, 'registered')], 'fingerprint' => $fingerprint];

            return "\n".json_encode(['v' => 1, 'now' => gmdate('Y-m-d H:i:s', $now), 'unix' => $now, 'tables' => $tables])."\n";
        }

        $since = static fn (string $table, string $column): string => $in['since'][$table]['max'][$column] ?? '';
        $orders = array_values(array_filter($this->orders, static fn (array $o): bool => $o['id'] > ($in['since']['wc_orders']['max_id'] ?? 0)
            || $o['created'] > $since('wc_orders', 'date_created_gmt') || $o['modified'] > $since('wc_orders', 'date_updated_gmt')));
        $users = array_values(array_filter($this->users, static fn (array $u): bool => $u['id'] > ($in['since']['users']['max_id'] ?? 0)
            || $u['registered'] > $since('users', 'user_registered')));
        usort($orders, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        usort($users, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        $tables['wc_orders'] = ['present' => true, 'changed' => count($orders)];
        $tables['users'] = ['present' => true, 'changed' => count($users), 'fingerprint' => $fingerprint];

        return "\n".json_encode(['v' => 1, 'now' => gmdate('Y-m-d H:i:s', $now), 'unix' => $now, 'tables' => $tables,
            'orders_total' => count($orders),
            'users_total' => count($users),
            'truncated' => false,
            'orders' => array_slice($orders, 0, $in['limit'] ?? 0),
            'users' => array_slice($users, 0, $in['limit'] ?? 0),
        ])."\n";
    }

    private function ok(string $stdout): CommandResult
    {
        return new CommandResult(0, $stdout, '', 1);
    }

    private function updateOption(string $name, string $value): CommandResult
    {
        $this->options[$name] = $value;

        return $this->ok("Success: Updated '{$name}' option.\n");
    }

    private function logLine(string $line): CommandResult
    {
        $this->phpLog[] = $line;

        return $this->ok('');
    }
}
