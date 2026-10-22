<?php

declare(strict_types=1);

use App\Domain\Runs\RunRecovery;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\CrashPipeline;

/*
 * Kills a real worker process (SIGKILL) at each step, then checks that recovery
 * detects it and that the run is resumed or rolled back without repeating any
 * effect. No transaction here: the worker is another process and must see the
 * data, so each test removes its run itself.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
});

/**
 * @param  Closure(Run, string): void  $test
 */
function withCrashRun(Closure $test): void
{
    $journal = (string) tempnam(sys_get_temp_dir(), 'wpup-crash-');
    $run = Run::factory()->create(['site_id' => null, 'type' => RunType::Update]);

    try {
        $test($run, $journal);
    } finally {
        @unlink($journal);
        $run->delete();
    }
}

/**
 * @return bool whether the worker died from SIGKILL (it must otherwise exit 0)
 */
function runWorker(Run $run, string $journal, string $crashAt, ?string $failAt = null): bool
{
    $command = [PHP_BINARY, base_path('tests/Process/run-worker.php'), (string) $run->id, $journal, $crashAt];
    if ($failAt !== null) {
        $command[] = $failAt;
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the worker.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    do {
        $status = proc_get_status($process);
        usleep(10_000);
    } while ($status['running']);
    proc_close($process);

    $killed = $status['signaled'] && $status['termsig'] === SIGKILL;
    if (! $killed && $status['exitcode'] !== 0) {
        throw new RuntimeException("Worker failed ({$status['exitcode']}): {$output}");
    }

    return $killed;
}

/**
 * The dead worker's claim would expire after its TTL; expire it now.
 */
function expireClaim(Run $run): void
{
    expect(Redis::connection('locks')->command('ttl', ["wpup:run:{$run->id}:claim"]))->toBeGreaterThan(0);
    Redis::connection('locks')->command('del', ["wpup:run:{$run->id}:claim"]);
}

/**
 * @return list<string>
 */
function effects(string $journal): array
{
    return array_values(array_filter(CrashPipeline::read($journal), static fn (string $line): bool => str_starts_with($line, 'effect ')));
}

it('resumes a run whose worker was killed during a step', function (string $crashAt): void {
    withCrashRun(function (Run $run, string $journal) use ($crashAt): void {
        expect(runWorker($run, $journal, $crashAt))->toBeTrue()
            ->and($run->fresh()?->status)->toBe(RunStatus::Running);

        expireClaim($run);
        expect(app(RunRecovery::class)->sweep())->toBe([$run->id]);

        expect(runWorker($run, $journal, $crashAt))->toBeFalse()
            ->and($run->fresh()?->status)->toBe(RunStatus::Succeeded)
            ->and(effects($journal))->toBe(['effect run:a', 'effect run:b', 'effect run:c'])
            ->and($run->steps()->where('name', substr($crashAt, 4))->value('attempts'))->toBe(2);
    });
})->with(['run:a', 'run:b', 'run:c']);

it('finishes the rollback when the worker is killed during a compensation', function (string $crashAt): void {
    withCrashRun(function (Run $run, string $journal) use ($crashAt): void {
        expect(runWorker($run, $journal, $crashAt, 'c'))->toBeTrue()
            ->and($run->fresh()?->status)->toBe(RunStatus::RollingBack);

        expireClaim($run);
        expect(app(RunRecovery::class)->sweep())->toBe([$run->id]);

        expect(runWorker($run, $journal, $crashAt, 'c'))->toBeFalse()
            ->and($run->fresh()?->status)->toBe(RunStatus::RolledBack)
            ->and(effects($journal))->toBe(['effect run:a', 'effect run:b', 'effect compensate:c', 'effect compensate:b', 'effect compensate:a']);
    });
})->with(['compensate:c', 'compensate:b', 'compensate:a']);
