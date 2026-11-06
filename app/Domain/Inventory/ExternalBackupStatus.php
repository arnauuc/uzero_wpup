<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

/**
 * Judges whether a site has a known, recent external backup (UZEROWPUP-133,
 * ADR-0009). Pure: it combines what the operator declared on the profile with
 * what the inventory detected, at the time it is given. Reused by the inventory
 * output and, later, by the dashboard, the site card and the weekly inventory.
 *
 * Rules:
 * - A backup is known if the profile declares plesk, dropbox or other, or an
 *   active backup plugin was detected. "none" or no declaration without a
 *   detected active plugin warns.
 * - Declaring "plugin" with no active backup plugin detected warns.
 * - If plugins expose the date of their last backup, the newest one must be at
 *   most $maxAgeDays old, unless the profile declares plesk, dropbox or other
 *   (an independent backup covers the site; the stale plugin is then noise).
 *   A plugin that exposes no date is never treated as stale.
 */
final readonly class ExternalBackupStatus
{
    public const string TYPE_NONE = 'none';

    /** Declared types that name a backup outside the site's WordPress. */
    private const array INDEPENDENT = ['plesk', 'dropbox', 'other'];

    public function __construct(private int $maxAgeDays) {}

    /**
     * @param  array<string, mixed>|null  $declaration  profile.external_backup: {type, notes}
     * @param  list<ExternalBackupPlugin>  $detected
     */
    public function assess(?array $declaration, array $detected, CarbonImmutable $now): ExternalBackupAssessment
    {
        $type = is_string($declaration['type'] ?? null) ? $declaration['type'] : null;
        $active = array_values(array_filter($detected, static fn (ExternalBackupPlugin $p): bool => $p->active));

        $newest = null;
        foreach ($active as $plugin) {
            if ($plugin->lastBackupAt !== null && ($newest === null || $plugin->lastBackupAt->greaterThan($newest))) {
                $newest = $plugin->lastBackupAt;
            }
        }

        $warnings = [];
        $independent = in_array($type, self::INDEPENDENT, true);

        if (! $independent && $active === []) {
            $warnings[] = match ($type) {
                null => 'Sense còpia externa coneguda: no n\'hi ha de declarada ni cap plugin de còpies actiu.',
                self::TYPE_NONE => 'El lloc està declarat sense còpia externa i no s\'hi detecta cap plugin de còpies actiu.',
                default => 'Es declara una còpia per plugin, però no s\'hi detecta cap plugin de còpies actiu.',
            };
        }

        if (! $independent && $newest !== null && $newest->lessThan($now->subDays($this->maxAgeDays))) {
            $days = (int) $newest->diffInDays($now);
            $warnings[] = "L'última còpia detectada té {$days} dies (màxim {$this->maxAgeDays}): {$newest->utc()->format('Y-m-d H:i')} UTC.";
        }

        return new ExternalBackupAssessment($warnings, $type, $newest);
    }
}
