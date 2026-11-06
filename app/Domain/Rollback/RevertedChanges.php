<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Updates\UpdateChanges;
use App\Enums\ChangeResult;
use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * Marks the applied updates of a run as undone (UZEROWPUP-57): the rows the
 * update steps wrote (UpdateChanges::STEP) with result `updated` become
 * `reverted`, keeping their versions before and after. A `failed` row keeps
 * its result: it is why the run was rolled back, and the files of that
 * component are restored all the same. Idempotent.
 */
final class RevertedChanges
{
    /**
     * @return int rows marked now
     */
    public function mark(Run $source): int
    {
        return $source->changes()
            ->where('step', UpdateChanges::STEP)
            ->where('result', ChangeResult::Updated->value)
            ->update(['result' => ChangeResult::Reverted->value, 'reverted_at' => CarbonImmutable::now()]);
    }
}
