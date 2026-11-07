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
     * Marks the run itself as being undone (runs.reverted_at), right before
     * its code is put back: from then on the retention holds its restore
     * point until an operator reviews it (ADR-0009), even when the update
     * had succeeded and is rolled back by hand (UZEROWPUP-61). A review made
     * before is cleared, since what it reviewed was not this rollback. Only
     * the first time: a retried rollback changes nothing. Written with a
     * query, never through $source: it may be the run the engine is
     * executing (the update's own rollback).
     */
    public function started(Run $source): void
    {
        Run::query()->whereKey($source->id)->whereNull('reverted_at')
            ->update(['reverted_at' => CarbonImmutable::now(), 'reviewed_at' => null, 'reviewed_by' => null]);
    }

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
