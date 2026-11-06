<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Enums\ComponentType;
use Carbon\CarbonImmutable;

/**
 * Detects backup plugins on a site (UZEROWPUP-133, ADR-0009) from the installed
 * plugin list, matched by slug against config('wpup.inventory.external_backups').
 * For active plugins that expose the time of their last backup, it reads it with
 * `wp option get <option> --format=json`. Only the option names from the config
 * are ever passed to the site. What the site answers is untrusted: it must be a
 * small JSON, the value an integer unix time that is not absurdly in the future;
 * anything else leaves the date null with a warning. A failing command never
 * fails the inventory; a lost connection does propagate.
 */
final readonly class ExternalBackups
{
    /** Longer answers are not even parsed: the option can hold a big history. */
    private const int MAX_OUTPUT_BYTES = 262144;

    /**
     * @param  array<string, array{name: string, last_backup: array{option: string, key: string}|null}>  $known
     */
    public function __construct(
        private WpCliReader $reader,
        private array $known,
        private int $commandTimeoutSeconds,
        private int $maxFutureSeconds,
    ) {}

    /**
     * @throws ConnectionFailed
     */
    public function read(SiteDriver $driver, InstalledComponents $installed, CarbonImmutable $now): ExternalBackupsResult
    {
        $plugins = [];
        $warnings = [];

        foreach ($installed->components as $component) {
            $definition = $this->known[$component->slug] ?? null;
            if ($component->type !== ComponentType::Plugin || $definition === null) {
                continue;
            }
            $at = null;
            if ($component->active && $definition['last_backup'] !== null) {
                $at = $this->lastBackup($driver, $definition['name'], $definition['last_backup'], $now, $warnings);
            }
            $plugins[] = new ExternalBackupPlugin($component->slug, $definition['name'], $component->active, $at);
        }
        usort($plugins, static fn (ExternalBackupPlugin $a, ExternalBackupPlugin $b): int => $a->slug <=> $b->slug);

        return new ExternalBackupsResult($plugins, $warnings);
    }

    /**
     * @param  array{option: string, key: string}  $where
     * @param  list<string>  $warnings
     */
    private function lastBackup(SiteDriver $driver, string $name, array $where, CarbonImmutable $now, array &$warnings): ?CarbonImmutable
    {
        $what = "la data de l'última còpia de {$name}";
        $result = $this->reader->run($driver, ['option', 'get', $where['option'], '--format=json'], $this->commandTimeoutSeconds, $what, $warnings);
        if ($result === null) {
            return null;
        }
        if (! $result->successful()) {
            $warnings[] = $this->reader->redact("No s'ha pogut llegir {$what} (codi {$result->exitCode}).");

            return null;
        }
        if (strlen($result->stdout) > self::MAX_OUTPUT_BYTES) {
            $warnings[] = "La resposta sobre {$what} és massa gran: s'ignora.";

            return null;
        }

        $data = $this->reader->json($result->stdout);
        $time = $data[$where['key']] ?? null;
        if (is_string($time) && ctype_digit($time)) {
            $time = (int) $time;
        }
        if (! is_int($time) || $time <= 0 || $time > $now->getTimestamp() + $this->maxFutureSeconds) {
            $warnings[] = "La resposta sobre {$what} no és vàlida: s'ignora.";

            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($time);
    }
}
