<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Enums\ChangeResult;
use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * Marks the applied updates of a run as undone (UZEROWPUP-57): the rows the
 * update steps wrote (run_changes.step = update) with result `updated` become
 * `reverted`, keeping their versions before and after. A `failed` row keeps
 * its result: it is why the run was rolled back, and the files of that
 * component are restored all the same. Idempotent.
 */
final class RevertedChanges
{
    /**
     * run_changes.step of the component updates.
     *
     * TODO(UZEROWPUP-57): use UpdateChanges::STEP once UZEROWPUP-54 is on main.
     */
    public const string UPDATE_STEP = 'update';

    /**
     * @return int rows marked now
     */
    public function mark(Run $source): int
    {
        return $source->changes()
            ->where('step', self::UPDATE_STEP)
            ->where('result', ChangeResult::Updated->value)
            ->update(['result' => ChangeResult::Reverted->value, 'reverted_at' => CarbonImmutable::now()]);
    }
}
