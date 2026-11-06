<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Inventory\PendingUpdate;

/**
 * Looks at the package of an update the planner would apply (UZEROWPUP-50).
 * The planner calls it last, only for updates no earlier rule skipped, in
 * update order.
 */
interface ChangeAnalyzer
{
    /**
     * @param  PendingUpdate|null  $update  the snapshot's row the planner built the change from; null if it was unusable
     * @return ChangeAnalysis|null null: this component is not analysed (core, translations)
     */
    public function analyze(PlannedChange $change, ?PendingUpdate $update): ?ChangeAnalysis;
}
