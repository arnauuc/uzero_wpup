<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Enums\CheckResult;
use App\Enums\ComponentType;

/**
 * In-memory WordPress site for unit tests (AGENTS.md: FakeDriver in unit tests,
 * Docker fixture sites in integration tests). It passes SiteDriverContract, so
 * code tested against it behaves the same with the real drivers.
 */
final class FakeDriver implements SiteDriver
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

    /** @var list<list<string>> every WP-CLI call, for assertions */
    public array $wpCalls = [];

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
            new ProbeCheck('php', CheckResult::Ok, $this->phpVersion),
        ]);
    }

    public function installedComponents(): InstalledComponents
    {
        return new InstalledComponents($this->coreVersion, $this->phpVersion, '10.11.9-MariaDB', array_values($this->components), 'storefront');
    }

    public function wp(array $args, ?int $timeoutSeconds = null): CommandResult
    {
        $this->wpCalls[] = $args;

        return match (true) {
            $args === ['core', 'version'] => $this->ok($this->coreVersion."\n"),
            $args === ['maintenance-mode', 'is-active'] => $this->maintenance
                ? $this->ok('')
                : new CommandResult(1, '', '', 1),
            count($args) === 3 && $args[0] === 'option' && $args[1] === 'get' => isset($this->options[$args[2]])
                ? $this->ok($this->options[$args[2]]."\n")
                : new CommandResult(1, '', "Error: Could not get '{$args[2]}' option.\n", 1),
            count($args) === 4 && $args[0] === 'option' && $args[1] === 'update' => $this->updateOption($args[2], $args[3]),
            count($args) === 2 && $args[0] === 'eval' && preg_match('/^error_log\("(.*)"\);$/', $args[1], $m) === 1 => $this->logLine($m[1]),
            default => new CommandResult(1, '', 'Error: FakeDriver does not support: '.implode(' ', $args)."\n", 1),
        };
    }

    public function dumpDatabase(string $remotePath): RemoteFile
    {
        return $this->store($remotePath, (string) json_encode(['options' => $this->options]));
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
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
        return $this->store($remotePath, (string) file_get_contents($localPath));
    }

    public function applyUpdate(UpdatePackage $package): CommandResult
    {
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

    public function setMaintenanceMode(bool $enabled): void
    {
        $this->maintenance = $enabled;
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $data = json_decode($this->files[$dump->path] ?? throw new RemoteCommandFailed('No hi ha el dump.'), true);
        $restored = [];

        foreach (is_array($data) && is_array($data['options'] ?? null) ? $data['options'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $restored[$name] = $value;
            }
        }

        $this->options = $restored;
    }

    public function restoreFiles(RemoteFile $archive): void
    {
        $data = unserialize($this->files[$archive->path] ?? throw new RemoteCommandFailed("No hi ha l'arxiu."), ['allowed_classes' => [ComponentVersion::class, ComponentType::class]]);

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
        return $this->freeDisk;
    }

    public function remove(string $remotePath): void
    {
        unset($this->files[$remotePath]);
    }

    private function store(string $remotePath, string $content): RemoteFile
    {
        $this->files[$remotePath] = $content;

        return new RemoteFile($remotePath, strlen($content), hash('sha256', $content));
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
