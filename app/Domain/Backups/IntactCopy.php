<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Backup;
use Closure;
use Throwable;

/**
 * The first copy of a list that a check or a restore can use, the preferred
 * one first (UZEROWPUP-46, 57, 61): $attempt is tried on each candidate in
 * order, and a copy it refuses (RestoreRefused, or one of $skip) changed
 * nothing, so the next one is tried. When a later copy is used, a warning
 * says why the earlier ones were not.
 */
final class IntactCopy
{
    /**
     * @template T
     *
     * @param  non-empty-list<Backup>  $candidates
     * @param  Closure(Backup): T  $attempt
     * @param  list<string>  $warnings
     * @param  list<class-string<Throwable>>  $skip  more exceptions that mean "this copy cannot be used"
     * @param  (Closure(Backup, Throwable): string)|null  $describe  how a refused copy is reported (its message by default)
     * @return array{Backup, T}
     *
     * @throws RestoreRefused with the reason of every copy, when none can be used
     */
    public static function first(array $candidates, Closure $attempt, array &$warnings, array $skip = [], ?Closure $describe = null): array
    {
        $describe ??= static fn (Backup $row, Throwable $e): string => $e->getMessage();
        $failures = [];
        foreach ($candidates as $row) {
            try {
                $result = $attempt($row);
            } catch (Throwable $e) {
                if (! $e instanceof RestoreRefused && array_filter($skip, static fn (string $class): bool => $e instanceof $class) === []) {
                    throw $e;
                }
                $failures[] = $describe($row, $e);

                continue;
            }
            if ($failures !== []) {
                $warnings[] = implode(' ', $failures)." Es fa servir la còpia #{$row->id} de «".($row->backupTarget->name ?? '?').'».';
            }

            return [$row, $result];
        }

        throw new RestoreRefused(implode(' ', $failures));
    }
}
