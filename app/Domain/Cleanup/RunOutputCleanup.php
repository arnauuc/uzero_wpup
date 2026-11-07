<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\ChangeResult;
use App\Enums\RunStatus;
use App\Models\RunStep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full (masked) step output: 90 days after a correct run finished, 1 year
 * after a failed or reverted one (ADR-0009). Only `run_steps.output` is
 * emptied (and `output_purged_at` set): the step summary, run_changes,
 * verifications and reports stay forever. Unfinished runs are never touched.
 */
final readonly class RunOutputCleanup implements CleanupTask
{
    public const string TASK = 'run_output';

    public function __construct(private CleanupSettings $settings, private AuditLogger $audit) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        $count = 0;
        $bytes = 0;
        $failures = [];
        $problem = $this->settings->problem(['run_output_days', 'run_output_failed_days']);
        if ($problem !== null) {
            Log::error($problem);
            $failures[] = $problem;
        } else {
            try {
                if ($dryRun) {
                    $totals = $this->expired($now)->toBase()->selectRaw('count(*) as steps, coalesce(sum(octet_length(run_steps.output)), 0) as bytes')->first();
                    $count = is_object($totals) && is_numeric($totals->steps) ? (int) $totals->steps : 0;
                    $bytes = is_object($totals) && is_numeric($totals->bytes) ? (int) $totals->bytes : 0;
                } else {
                    // Chunked: the counts are the rows actually updated.
                    do {
                        $ids = $this->expired($now)->orderBy('run_steps.id')->limit($this->settings->chunkSize)->pluck('run_steps.id')->all();
                        if ($ids === []) {
                            break;
                        }
                        $bytes += (int) RunStep::query()->whereIn('id', $ids)->sum(DB::raw('octet_length(output)'));
                        $count += RunStep::query()->whereIn('id', $ids)->whereNotNull('output')->update(['output' => null, 'output_purged_at' => $now]);
                    } while (true);
                }
            } catch (Throwable $e) {
                Log::warning('Run output cleanup failed: '.$e::class);
                $failures[] = "No s'ha pogut netejar la sortida dels passos.";
            }
        }
        if (! $dryRun && ($count > 0 || $failures !== [])) {
            $this->audit->record($actor, 'cleanup.run_output', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                'steps' => $count,
                'bytes' => $bytes,
                'days' => $this->settings->runOutputDays,
                'failed_days' => $this->settings->runOutputFailedDays,
                'failures' => $failures,
            ]);
        }

        return new CleanupResult(self::TASK, $count, $bytes, $dryRun, null, $failures);
    }

    /**
     * Steps still holding output whose run finished long enough ago. A run
     * counts as failed (1 year) if it failed, was rolled back, or any of its
     * changes was reverted.
     *
     * @return Builder<RunStep>
     */
    private function expired(CarbonImmutable $now): Builder
    {
        $failed = [RunStatus::Failed->value, RunStatus::RolledBack->value];
        $correct = array_map(
            static fn (RunStatus $s): string => $s->value,
            array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isTerminal() && ! in_array($s->value, $failed, true)),
        );
        $correctBefore = $now->subDays($this->settings->runOutputDays);
        $failedBefore = $now->subDays($this->settings->runOutputFailedDays);
        $reverted = static fn (QueryBuilder $q): QueryBuilder => $q->selectRaw('1')->from('run_changes')
            ->whereColumn('run_changes.run_id', 'runs.id')
            ->where(static fn (QueryBuilder $c): QueryBuilder => $c->where('run_changes.result', ChangeResult::Reverted->value)->orWhereNotNull('run_changes.reverted_at'));

        return RunStep::query()
            ->whereNotNull('run_steps.output')
            ->whereNull('run_steps.output_purged_at')
            ->whereIn('run_steps.run_id', static fn (QueryBuilder $runs): QueryBuilder => $runs->select('runs.id')->from('runs')
                ->whereNotNull('runs.finished_at')
                ->where(static fn (QueryBuilder $q): QueryBuilder => $q
                    ->where(static fn (QueryBuilder $f): QueryBuilder => $f->where('runs.finished_at', '<=', $failedBefore)
                        ->where(static fn (QueryBuilder $k): QueryBuilder => $k->whereIn('runs.status', $failed)->orWhereExists($reverted)))
                    ->orWhere(static fn (QueryBuilder $c): QueryBuilder => $c->where('runs.finished_at', '<=', $correctBefore)
                        ->whereIn('runs.status', $correct)->whereNotExists($reverted))));
    }
}
