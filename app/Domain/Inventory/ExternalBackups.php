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
 * For active plugins that expose their last backup it reads, with
 * `wp option pluck <option> <key> --format=json`, the time of the last run and
 * whether it succeeded (UpdraftPlus records failed runs there too, so a failed
 * one must not count as a recent backup). Only option and key names from the
 * config are ever passed to the site. What the site answers is untrusted: it
 * must be a small JSON scalar, the time an integer unix time that is not
 * absurdly in the future; anything else leaves the date null with a warning. A
 * failing command never fails the inventory; a lost connection does propagate.
 */
final readonly class ExternalBackups
{
    /**
     * @param  array<string, array{name: string, last_backup: array{option: string, time_key: string, success_key: string}|null}>  $known
     */
    public function __construct(
        private WpCliReader $reader,
        private array $known,
        private int $commandTimeoutSeconds,
        private int $maxFutureSeconds,
        private int $maxOutputBytes,
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
            $ok = null;
            if ($component->active && $definition['last_backup'] !== null) {
                [$at, $ok] = $this->lastBackup($driver, $definition['name'], $definition['last_backup'], $now, $warnings);
            }
            $plugins[] = new ExternalBackupPlugin($component->slug, $definition['name'], $component->active, $definition['last_backup'] !== null, $at, $ok);
        }
        usort($plugins, static fn (ExternalBackupPlugin $a, ExternalBackupPlugin $b): int => $a->slug <=> $b->slug);

        return new ExternalBackupsResult($plugins, $warnings);
    }

    /**
     * @param  array{option: string, time_key: string, success_key: string}  $where
     * @param  list<string>  $warnings
     * @return array{0: CarbonImmutable|null, 1: bool|null}
     */
    private function lastBackup(SiteDriver $driver, string $name, array $where, CarbonImmutable $now, array &$warnings): array
    {
        $what = "la data de l'última còpia de {$name}";
        [$found, $time] = $this->pluck($driver, $where['option'], $where['time_key'], $what, $warnings);
        if (! $found) {
            return [null, null];
        }
        if (is_string($time) && ctype_digit($time)) {
            $time = (int) $time;
        }
        if (! is_int($time) || $time <= 0 || $time > $now->getTimestamp() + $this->maxFutureSeconds) {
            $warnings[] = "La resposta sobre {$what} no és vàlida: s'ignora.";

            return [null, null];
        }

        $whatOk = "si l'última còpia de {$name} ha anat bé";
        [$foundOk, $success] = $this->pluck($driver, $where['option'], $where['success_key'], $whatOk, $warnings);
        $ok = null;
        if ($foundOk) {
            $ok = match (true) {
                is_bool($success) => $success,
                $success === 1, $success === '1' => true,
                $success === 0, $success === '0', $success === '' => false,
                default => null,
            };
            if ($ok === null) {
                $warnings[] = "La resposta sobre {$whatOk} no és vàlida: s'ignora.";
            }
        }

        return [CarbonImmutable::createFromTimestampUTC($time), $ok];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: bool, 1: mixed} found, decoded JSON scalar
     */
    private function pluck(SiteDriver $driver, string $option, string $key, string $what, array &$warnings): array
    {
        $result = $this->reader->run($driver, ['option', 'pluck', $option, $key, '--format=json'], $this->commandTimeoutSeconds, $what, $warnings);
        if ($result === null) {
            return [false, null];
        }
        if (! $result->successful()) {
            $warnings[] = $this->reader->redact("No s'ha pogut llegir {$what} (codi {$result->exitCode}).");

            return [false, null];
        }
        if (strlen($result->stdout) > $this->maxOutputBytes) {
            $warnings[] = "La resposta sobre {$what} és massa gran: s'ignora.";

            return [false, null];
        }

        $line = $this->reader->lastLine($result->stdout);
        $value = $line === null ? null : json_decode($line, true, 4);
        if ($line === null || json_last_error() !== JSON_ERROR_NONE || is_array($value)) {
            $warnings[] = "La resposta sobre {$what} no és vàlida: s'ignora.";

            return [false, null];
        }

        return [true, $value];
    }
}
