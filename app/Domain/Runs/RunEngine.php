<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Concurrency\ConcurrencyLimitReached;
use App\Domain\Runs\Exceptions\ClaimLost;
use App\Domain\Runs\Exceptions\InvalidTransition;
use App\Domain\Runs\Exceptions\RunAlreadyClaimed;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\Exceptions\StepTimedOut;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes a run as a sequence of persisted, idempotent steps (UZEROWPUP-21).
 *
 * - The worker claims the run (RunClaim) and the site lock, and keeps both alive
 *   with the StepTicker. Losing either stops the worker without touching the run.
 * - Steps are materialised in run_steps before running; a resumed run skips the
 *   finished ones and runs the interrupted one again.
 * - A failed step (error, timeout, run deadline, too many interruptions) rolls
 *   the run back through the compensations, in reverse order.
 * - Every summary, output, error and result is redacted before it is stored.
 */
final readonly class RunEngine
{
    public function __construct(
        private PipelineResolver $pipelines,
        private RunClaim $claims,
        private SiteLockManager $locks,
        private ConcurrencyGate $gate,
        private SecretRedactor $redactor,
        private AuditLogger $audit,
        private StepTicker $ticker,
        private RunEngineSettings $settings,
    ) {}

    /**
     * Runs (or resumes) the run until it reaches a final state.
     *
     * @throws RunAlreadyClaimed when another worker is executing it
     * @throws ConcurrencyLimitReached when a concurrency level is full (the run waits)
     * @throws LockedException when the site or its hosting account is busy
     * @throws ClaimLost when the worker lost its claim midway (recovery resumes it)
     */
    public function execute(Run $run): Run
    {
        $run->refresh();
        if ($run->status->isTerminal()) {
            return $run;
        }

        $token = $this->claims->claim($run->id) ?? throw new RunAlreadyClaimed("Run {$run->id} is already being executed.");
        $leases = new ExecutionLeases;

        try {
            $run->refresh();
            if ($run->status->isTerminal()) {
                return $run;
            }

            $leases->slots = $this->gate->acquire($run, $token);
            if ($run->site !== null) {
                $leases->site = $this->locks->acquire($run->site, $run->actor, $run->id);
            }

            try {
                $pipeline = $this->pipelines->resolve($run);
            } catch (RunException $e) {
                // A run that cannot even start must not stay queued in silence.
                if ($run->status === RunStatus::Queued) {
                    $this->finish($run, RunStatus::Failed, ['', $e->getMessage()]);
                }

                throw $e;
            }
            $this->begin($run, $pipeline);

            $failure = $run->status === RunStatus::Running
                ? $this->forward($run, $pipeline, $token, $leases)
                : $this->recordedFailure($run);

            if ($failure === null) {
                $this->finish($run, RunStatus::Succeeded);
            } else {
                $this->rollback($run, $pipeline, $token, $leases, $failure);
            }

            return $run;
        } finally {
            $this->ticker->stop();
            $leases->release();
            $this->claims->release($run->id, $token);
        }
    }

    /**
     * Validated run transition, serialised with a row lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function transition(Run $run, RunStatus $to, array $attributes = []): void
    {
        DB::transaction(static function () use ($run, $to, $attributes): void {
            $current = Run::query()->toBase()->where('id', $run->id)->lockForUpdate()->value('status');
            $from = RunStatus::from(is_string($current) ? $current : '');

            if (! $from->canTransitionTo($to)) {
                throw InvalidTransition::run($run->id, $from, $to);
            }

            Run::query()->whereKey($run->id)->update(['status' => $to->value, ...$attributes]);
        });

        $run->refresh();
    }

    private function begin(Run $run, Pipeline $pipeline): void
    {
        // We hold the claim, so a run still marked active lost its worker
        // before recovery noticed.
        if ($run->status->isActive()) {
            self::transition($run, RunStatus::Interrupted);
        }

        if ($run->status === RunStatus::Queued) {
            $now = CarbonImmutable::now();
            self::transition($run, RunStatus::Running, [
                'started_at' => $now,
                'deadline_at' => $run->deadline_at ?? $now->addSeconds($this->settings->runTimeoutSeconds),
            ]);
            $this->materialize($run, $pipeline);
            $this->audit->record(AuditActor::system(), 'run.started', AuditResult::Success, 'run', $run->id, ['type' => $run->type->value]);

            return;
        }

        // Interrupted: put the step that was running back to pending, then
        // continue in the phase where the run stopped.
        foreach ($this->rows($run) as $row) {
            if ($row->status === RunStepStatus::Running) {
                $this->stepTransition($row, RunStepStatus::Pending, ['error' => 'Interromput; es torna a executar.']);
            }
        }

        $rollingBack = $this->rows($run, RunStepKind::Compensation)->isNotEmpty();
        self::transition($run, $rollingBack ? RunStatus::RollingBack : RunStatus::Running);
        $this->materialize($run, $pipeline);
        $this->audit->record(AuditActor::system(), 'run.resumed', AuditResult::Success, 'run', $run->id, ['phase' => $run->status->value]);
    }

    private function materialize(Run $run, Pipeline $pipeline): void
    {
        if ($this->rows($run, RunStepKind::Forward)->isNotEmpty()) {
            return;
        }

        DB::transaction(static function () use ($run, $pipeline): void {
            foreach ($pipeline->steps as $index => $step) {
                $run->steps()->create(['sequence' => $index + 1, 'name' => $step->name(), 'kind' => RunStepKind::Forward]);
            }
        });
    }

    /**
     * @return array{string, string}|null the failed step and why
     */
    private function forward(Run $run, Pipeline $pipeline, string $token, ExecutionLeases $leases): ?array
    {
        $dryRun = ($run->flags['dry_run'] ?? false) === true;

        foreach ($this->rows($run, RunStepKind::Forward) as $row) {
            if ($row->status === RunStepStatus::Failed) {
                return [$row->name, $row->error ?? ''];
            }
            if ($row->status->isFinished()) {
                continue;
            }

            $step = $pipeline->get($row->name);
            $context = $this->context($run);

            if ($run->deadline_at !== null && CarbonImmutable::now()->greaterThanOrEqualTo($run->deadline_at)) {
                $this->stepTransition($row, RunStepStatus::Failed, ['error' => "S'ha superat el termini de l'execució."]);

                return [$row->name, (string) $row->error];
            }

            if ($dryRun && ! $step instanceof ReadOnlyStep) {
                $this->stepTransition($row, RunStepStatus::Skipped, ['summary' => $this->clip('[dry-run] '.$step->describe($context))]);

                continue;
            }

            $this->runStep($run, $row, $step->timeoutSeconds(), $run->deadline_at, static fn (): StepOutcome => $step->run($context), $token, $leases);

            if ($row->status === RunStepStatus::Failed) {
                return [$row->name, (string) $row->error];
            }
        }

        return null;
    }

    /**
     * @param  array{string, string}  $failure
     */
    private function rollback(Run $run, Pipeline $pipeline, string $token, ExecutionLeases $leases, array $failure): void
    {
        if ($run->status === RunStatus::Running) {
            $toUndo = $this->rows($run, RunStepKind::Forward)
                ->filter(static fn (RunStep $row): bool => in_array($row->status, [RunStepStatus::Succeeded, RunStepStatus::Failed], true)
                    && $pipeline->get($row->name) instanceof CompensableStep)
                ->reverse();

            if ($toUndo->isEmpty()) {
                $this->finish($run, RunStatus::Failed, $failure);

                return;
            }

            self::transition($run, RunStatus::RollingBack);
            DB::transaction(static function () use ($run, $toUndo): void {
                $max = $run->steps()->max('sequence');
                $sequence = is_numeric($max) ? (int) $max : 0;
                foreach ($toUndo as $row) {
                    $run->steps()->create(['sequence' => ++$sequence, 'name' => $row->name, 'kind' => RunStepKind::Compensation]);
                }
            });
        }

        foreach ($this->rows($run, RunStepKind::Compensation) as $row) {
            if ($row->status === RunStepStatus::Pending) {
                $step = $pipeline->get($row->name);
                if (! $step instanceof CompensableStep) {
                    throw new RunException("Step «{$row->name}» has no compensation.");
                }
                $context = $this->context($run, $failure);
                $this->runStep($run, $row, $step->timeoutSeconds(), null, static fn (): StepOutcome => $step->compensate($context), $token, $leases);
            }

            if ($row->status === RunStepStatus::Failed) {
                $this->finish($run, RunStatus::Failed, [$failure[0], "{$failure[1]} El rollback ha fallat a «{$row->name}»: {$row->error}"]);

                return;
            }
        }

        $this->finish($run, RunStatus::RolledBack, $failure);
    }

    /**
     * @param  Closure(): StepOutcome  $action
     */
    private function runStep(Run $run, RunStep $row, int $timeout, ?CarbonImmutable $cap, Closure $action, string $token, ExecutionLeases $leases): void
    {
        if ($row->attempts >= $this->settings->maxStepAttempts) {
            $this->stepTransition($row, RunStepStatus::Failed, [
                'error' => "S'ha interromput {$row->attempts} vegades; no es torna a provar.",
            ]);

            return;
        }

        $started = CarbonImmutable::now();
        $deadline = $started->addSeconds($timeout);
        if ($cap !== null && $cap->lessThan($deadline)) {
            $deadline = $cap;
        }

        $this->stepTransition($row, RunStepStatus::Running, [
            'attempts' => $row->attempts + 1,
            'started_at' => $started,
            'finished_at' => null,
            'timeout_seconds' => $timeout,
            'error' => null,
        ]);

        $heartbeat = $this->settings->heartbeatSeconds;
        $this->ticker->start(min($heartbeat, self::secondsUntil($deadline)), function () use ($run, $token, $leases, $deadline, $heartbeat): int {
            if (! $this->claims->refresh($run->id, $token) || ! $leases->refresh()) {
                throw new ClaimLost("Run {$run->id}: the claim, a concurrency slot or the site lock was lost.");
            }
            $left = self::secondsUntil($deadline);
            if ($left <= 0) {
                throw new StepTimedOut("Run {$run->id}: step timed out.");
            }

            return min($heartbeat, $left);
        });

        $clock = hrtime(true);
        $error = null;

        try {
            $outcome = $action();
        } catch (ClaimLost $e) {
            throw $e;
        } catch (StepTimedOut) {
            $outcome = StepOutcome::failed("Temps esgotat ({$timeout} s).");
        } catch (Throwable $e) {
            report($e);
            $outcome = StepOutcome::failed('Error inesperat: '.$e->getMessage());
        } finally {
            $this->ticker->stop();
        }

        if (CarbonImmutable::now()->greaterThan($deadline) && $outcome->status !== RunStepStatus::Failed) {
            $outcome = StepOutcome::failed("Temps esgotat ({$timeout} s).", $outcome->output);
        }
        if ($outcome->status === RunStepStatus::Failed) {
            $error = $outcome->summary;
        }

        $this->stepTransition($row, $outcome->status, [
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => intdiv(hrtime(true) - $clock, 1_000_000),
            'summary' => $this->clip($outcome->summary),
            'output' => $this->truncate($this->redactor->redact($outcome->output)),
            'result' => $outcome->status === RunStepStatus::Succeeded ? $this->redactor->redactArray($outcome->data) : null,
            'error' => $error === null ? null : $this->clip($error),
        ]);
    }

    /**
     * @param  array{string, string}|null  $failure
     */
    private function finish(Run $run, RunStatus $status, ?array $failure = null): void
    {
        $counts = [];
        foreach ($this->rows($run) as $row) {
            $key = $row->kind->value.'.'.$row->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        self::transition($run, $status, [
            'finished_at' => CarbonImmutable::now(),
            'result_summary' => json_encode([
                'dry_run' => ($run->flags['dry_run'] ?? false) === true,
                'failed_step' => $failure[0] ?? null,
                'reason' => $failure === null ? null : $this->clip($failure[1]),
                'steps' => $counts,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->audit->record(
            AuditActor::system(),
            'run.finished',
            $status === RunStatus::Succeeded ? AuditResult::Success : AuditResult::Failure,
            'run',
            $run->id,
            ['status' => $status->value, 'failed_step' => $failure[0] ?? null],
        );
    }

    /**
     * The failure that sent an interrupted run into rollback.
     *
     * @return array{string, string}
     */
    private function recordedFailure(Run $run): array
    {
        $failed = $this->rows($run, RunStepKind::Forward)
            ->filter(static fn (RunStep $row): bool => $row->status === RunStepStatus::Failed)
            ->values()
            ->get(0);

        return $failed === null
            ? ['', 'Execució interrompuda durant el rollback.']
            : [$failed->name, $failed->error ?? ''];
    }

    /**
     * @param  array{string, string}|null  $failure
     */
    private function context(Run $run, ?array $failure = null): StepContext
    {
        $results = [];
        foreach ($this->rows($run, RunStepKind::Forward) as $row) {
            if ($row->status === RunStepStatus::Succeeded) {
                $results[$row->name] = $row->result ?? [];
            }
        }

        return new StepContext($run, ($run->flags['dry_run'] ?? false) === true, $results, $failure[0] ?? null, $failure[1] ?? null);
    }

    /**
     * @return Collection<int, RunStep>
     */
    private function rows(Run $run, ?RunStepKind $kind = null): Collection
    {
        return $run->steps()
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind?->value))
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stepTransition(RunStep $row, RunStepStatus $to, array $attributes = []): void
    {
        if (! $row->status->canTransitionTo($to)) {
            throw InvalidTransition::step($row->id, $row->status, $to);
        }

        $row->forceFill(['status' => $to, ...$attributes])->save();
    }

    private function clip(string $text): string
    {
        return mb_substr($this->redactor->redact($text), 0, $this->settings->summaryMaxChars);
    }

    private function truncate(string $output): string
    {
        $max = $this->settings->outputMaxBytes;

        return strlen($output) <= $max ? $output : mb_strcut($output, 0, $max)."\n[… sortida retallada]";
    }

    private static function secondsUntil(CarbonImmutable $moment): int
    {
        return $moment->getTimestamp() - CarbonImmutable::now()->getTimestamp();
    }
}
