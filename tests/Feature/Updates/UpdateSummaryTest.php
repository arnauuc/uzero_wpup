<?php

declare(strict_types=1);

use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Updates\DatabaseRollbackState;
use App\Domain\Updates\UpdateSummary;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Models\Run;

/*
 * What `wpup update` says about a finished run (UZEROWPUP-60).
 */

function summaryRun(?RunStepStatus $database, ?bool $restored = null): Run
{
    $run = Run::factory()->create(['status' => RunStatus::RolledBack]);
    $run->steps()->create(['sequence' => 1, 'name' => FilesRollbackStep::NAME, 'kind' => RunStepKind::Recovery, 'status' => RunStepStatus::Succeeded]);
    if ($database !== null) {
        $run->steps()->create(['sequence' => 2, 'name' => DatabaseRollbackStep::NAME, 'kind' => RunStepKind::Recovery, 'status' => $database,
            'result' => $restored === null ? null : ['restored' => $restored]]);
    }

    return $run;
}

it('never says the database was untouched when its rollback failed', function (): void {
    $summary = UpdateSummary::of(summaryRun(RunStepStatus::Failed));
    /** @var array{database: ?string} $rollback */
    $rollback = $summary->toArray()['rollback'];

    expect($summary->database)->toBe(DatabaseRollbackState::Failed)
        ->and($summary->database?->label())->toBe('estat de la base de dades desconegut: el rollback de la BD ha fallat, cal revisar-la')
        ->and($rollback['database'])->toBe('failed');
});

it('tells restored, not restored and not reached apart', function (?RunStepStatus $status, ?bool $restored, DatabaseRollbackState $expected): void {
    expect(UpdateSummary::of(summaryRun($status, $restored))->database)->toBe($expected);
})->with([
    'restored' => [RunStepStatus::Succeeded, true, DatabaseRollbackState::Restored],
    'new orders' => [RunStepStatus::Succeeded, false, DatabaseRollbackState::NotRestored],
    'stopped before' => [RunStepStatus::Pending, null, DatabaseRollbackState::NotReached],
]);

it('has nothing to do with only translations to update, as the pipeline', function (): void {
    $run = Run::factory()->create(['status' => RunStatus::Succeeded]);
    $plan = new UpdatePlan('8.3', PhpVersionSource::PleskHandler, true, null, [
        new PlannedChange(ComponentType::Translation, 'ca', '1', '2', PlanAction::Update),
    ]);
    $run->steps()->create(['sequence' => 1, 'name' => PlanStep::NAME, 'status' => RunStepStatus::Succeeded, 'result' => $plan->toArray()]);

    expect(UpdateSummary::of($run)->nothingToDo())->toBeTrue();
});
