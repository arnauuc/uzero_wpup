<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
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
        try {
            $count = $this->expired($now)->count();
            $bytes = (int) $this->expired($now)->sum(DB::raw('octet_length(run_steps.output)'));
            if (! $dryRun && $count > 0) {
                $this->expired($now)->update(['output' => null, 'output_purged_at' => $now]);
            }
        } catch (Throwable $e) {
            Log::warning('Run output cleanup failed: '.$e::class);
            $count = 0;
            $bytes = 0;
            $failures[] = "No s'ha pogut netejar la sortida dels passos.";
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
     * Steps still holding output whose run finished long enough ago.
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

        return RunStep::query()
            ->whereNotNull('output')
            ->whereNull('output_purged_at')
            ->whereIn('run_id', static fn (QueryBuilder $runs): QueryBuilder => $runs->select('id')->from('runs')
                ->whereNotNull('finished_at')
                ->where(static fn (QueryBuilder $q): QueryBuilder => $q
                    ->where(static fn (QueryBuilder $c): QueryBuilder => $c->whereIn('status', $correct)->where('finished_at', '<=', $correctBefore))
                    ->orWhere(static fn (QueryBuilder $f): QueryBuilder => $f->whereIn('status', $failed)->where('finished_at', '<=', $failedBefore))));
    }
}
