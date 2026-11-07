<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Enums\ChangeResult;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\RunStep;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * What an update run did, for `wpup update` once it is finished (the full
 * report is F5): the plan, the components it updated and, when it rolled
 * itself back, what the rollback restored. Read from the run's own rows.
 */
final readonly class UpdateSummary
{
    /**
     * @param  list<array{type: string, slug: string, before: ?string, after: ?string, result: string}>  $changes
     * @param  bool|null  $filesRestored  null when there was no rollback
     * @param  DatabaseRollbackState|null  $database  null when there was no rollback
     */
    public function __construct(
        public Run $run,
        public ?UpdatePlan $plan,
        public array $changes,
        public ?bool $filesRestored,
        public ?DatabaseRollbackState $database,
        public ?string $databaseWarning,
        public ?string $forceCommand,
    ) {}

    public static function of(Run $run): self
    {
        $steps = $run->steps()->get()->keyBy(static fn (RunStep $s): string => $s->kind->value.':'.$s->name);
        $planRow = $steps->get(RunStepKind::Forward->value.':'.PlanStep::NAME);
        $plan = null;
        if ($planRow !== null && $planRow->status === RunStepStatus::Succeeded) {
            try {
                $plan = UpdatePlan::fromArray($planRow->result ?? []);
            } catch (UnexpectedValueException $e) {
                Log::warning('The stored plan of an update run cannot be read.', ['run_id' => $run->id, 'reason' => $e->getMessage()]);
                $plan = null;
            }
        }

        $changes = array_values($run->changes()->where('step', UpdateChanges::STEP)->orderBy('id')->get()
            ->map(static fn (RunChange $c): array => [
                'type' => $c->component_type->value,
                'slug' => $c->slug,
                'before' => $c->version_before,
                'after' => $c->version_after,
                'result' => $c->result->value,
            ])->all());

        $files = $steps->get(RunStepKind::Recovery->value.':'.FilesRollbackStep::NAME);
        $database = $steps->get(RunStepKind::Recovery->value.':'.DatabaseRollbackStep::NAME);
        $result = $database?->status === RunStepStatus::Succeeded ? ($database->result ?? []) : [];
        $warning = $result['warning'] ?? null;
        $force = $result['force_command'] ?? null;

        return new self(
            $run,
            $plan,
            $changes,
            $files === null ? null : $files->status === RunStepStatus::Succeeded,
            match (true) {
                $database === null => null,
                $database->status === RunStepStatus::Succeeded => ($result['restored'] ?? null) === true ? DatabaseRollbackState::Restored : DatabaseRollbackState::NotRestored,
                $database->status === RunStepStatus::Failed => DatabaseRollbackState::Failed,
                default => DatabaseRollbackState::NotReached,
            },
            is_string($warning) ? $warning : null,
            is_string($force) ? $force : null,
        );
    }

    /**
     * The run planned nothing to apply: it ended without touching the site.
     */
    public function nothingToDo(): bool
    {
        return $this->plan !== null && UpdateSteps::applicable($this->plan) === [];
    }

    public function updatedCount(): int
    {
        return count(array_filter($this->changes, static fn (array $c): bool => in_array($c['result'], [ChangeResult::Updated->value, ChangeResult::Reverted->value], true)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->run->id,
            'site' => $this->run->site?->name,
            'status' => $this->run->status->value,
            'strategy' => $this->run->strategy?->value,
            'reason' => $this->run->failureReason(),
            'plan' => $this->plan?->toArray(),
            'changes' => $this->changes,
            'rollback' => $this->filesRestored === null ? null : [
                'files_restored' => $this->filesRestored,
                'database' => $this->database?->value,
                'database_warning' => $this->databaseWarning,
                'force_command' => $this->forceCommand,
            ],
        ];
    }
}
