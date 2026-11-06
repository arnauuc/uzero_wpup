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
 * Declaration covers "is there a backup at all"; evidence found on the site
 * always counts, whatever is declared. Rules:
 * - A backup is known if the profile declares plesk, dropbox or other, or an
 *   active backup plugin was detected. "none" or no declaration without a
 *   detected active plugin warns.
 * - Declaring "plugin" with no active backup plugin detected warns.
 * - An active plugin that exposes its last backup but whose date could not be
 *   read warns (a plugin that never exposes one, per config, does not).
 * - A last backup recorded as failed warns and does not count as recent.
 * - The newest successful (or unknown-outcome) date must be at most $maxAgeDays
 *   old, even when plesk, dropbox or other is declared.
 * - $detected null = detection did not run (no snapshot, or one taken before
 *   this check existed): only the declaration is judged, and the messages say so.
 */
final readonly class ExternalBackupStatus
{
    public const string TYPE_NONE = 'none';

    /** Declared types that name a backup outside the site's WordPress. */
    private const array INDEPENDENT = ['plesk', 'dropbox', 'other'];

    private const int SECONDS_PER_HOUR = 3600;

    private const int HOURS_PER_DAY = 24;

    public function __construct(private int $maxAgeDays) {}

    /**
     * @param  array<string, mixed>|null  $declaration  profile.external_backup: {type, notes}
     * @param  list<ExternalBackupPlugin>|null  $detected
     */
    public function assess(?array $declaration, ?array $detected, CarbonImmutable $now): ExternalBackupAssessment
    {
        $type = is_string($declaration['type'] ?? null) ? $declaration['type'] : null;
        $independent = in_array($type, self::INDEPENDENT, true);
        $active = array_values(array_filter($detected ?? [], static fn (ExternalBackupPlugin $p): bool => $p->active));
        $note = $detected === null ? ' (la detecció de plugins no s\'ha executat)' : '';

        $warnings = [];
        if (! $independent && $active === []) {
            $warnings[] = match ($type) {
                null => 'Sense còpia externa coneguda: no n\'hi ha de declarada ni cap plugin de còpies actiu'.$note.'.',
                self::TYPE_NONE => 'El lloc està declarat sense còpia externa i no s\'hi detecta cap plugin de còpies actiu'.$note.'.',
                default => 'Es declara una còpia per plugin, però no s\'hi detecta cap plugin de còpies actiu'.$note.'.',
            };
        }

        $newest = null;
        foreach ($active as $plugin) {
            if ($plugin->dateUnreadable()) {
                $warnings[] = "No s'ha pogut llegir la data de l'última còpia de {$plugin->name}.";
            } elseif ($plugin->lastBackupOk === false) {
                $when = $plugin->lastBackupAt === null ? '' : ': '.$plugin->lastBackupAt->utc()->format('Y-m-d H:i').' UTC';
                $warnings[] = "L'última còpia de {$plugin->name} ha fallat{$when}.";
            } elseif ($plugin->lastBackupAt !== null && ($newest === null || $plugin->lastBackupAt->greaterThan($newest))) {
                $newest = $plugin->lastBackupAt;
            }
        }

        if ($newest !== null && $newest->lessThan($now->subDays($this->maxAgeDays))) {
            $hours = intdiv($now->getTimestamp() - $newest->getTimestamp(), self::SECONDS_PER_HOUR);
            $days = intdiv($hours, self::HOURS_PER_DAY);
            $rest = $hours % self::HOURS_PER_DAY;
            $warnings[] = "L'última còpia detectada és de fa {$days} dies i {$rest} hores (màxim {$this->maxAgeDays} dies): {$newest->utc()->format('Y-m-d H:i')} UTC.";
        }

        return new ExternalBackupAssessment($warnings, $type, $newest);
    }
}
