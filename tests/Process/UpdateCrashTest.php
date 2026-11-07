<?php

declare(strict_types=1);

use App\Domain\Backups\BackupStep;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Runs\RunInterruption;
use App\Domain\Runs\RunRecovery;
use App\Domain\Updates\SmokeCheckStep;
use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunArtifact;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\CrashingDriver;

/*
 * UZEROWPUP-62: the real in-place update pipeline (InPlaceTestPipeline over a
 * CrashingDriver) in a worker process killed with SIGKILL during the backup,
 * a component update, the smoke check and the files rollback. Recovery picks
 * the run up and a second worker resumes or rolls it back: it never stays
 * half-done, and the interruption is recorded with its step and decision.
 * No transaction: the worker is another process. Each test removes what it
 * created; audit_log is insert-only, so the worker keeps only the engine's
 * rows there (run.*).
 * See docs/runbooks/represa-actualitzacions.md.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
});

/**
 * Runs $test with a shop on a CrashingDriver ($prepare sets where it dies)
 * and a queued update run.
 *
 * @param  Closure(CrashingDriver): void  $prepare
 * @param  Closure(Run, Closure(): CrashingDriver, Closure(): bool): void  $test  gets the run, the site's state and the worker
 */
function withUpdateCrash(Closure $prepare, Closure $test): void
{
    $dir = sys_get_temp_dir().'/wpup-update-crash-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $state = "{$dir}/site.state";
    $artifacts = "{$dir}/artifacts";
    $driver = new CrashingDriver;
    $prepare($driver);
    $driver->saveTo($state);

    $site = Site::factory()->create(['name' => 'botiga-'.bin2hex(random_bytes(3)), 'url' => 'https://botiga.test']);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);
    // A nightly update the scheduler queued: no operator, so no user is left
    // referenced by the audit_log rows (insert-only) the test cannot remove.
    $run = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'channel' => AuditChannel::System, 'strategy' => UpdateStrategy::InPlace]);

    try {
        $test($run, static fn (): CrashingDriver => CrashingDriver::load($state), static fn (): bool => runUpdateWorker($run, $state, $artifacts));
    } finally {
        RunArtifact::query()->where('run_id', $run->id)->delete();
        SiteSnapshot::query()->where('site_id', $site->id)->delete();
        Backup::query()->where('site_id', $site->id)->delete();
        $run->delete();
        $site->delete();
        $account = $site->hostingAccount;
        $account?->delete();
        $account?->hostingServer?->delete();
        exec('rm -rf '.escapeshellarg($dir));
    }
}

/**
 * @return bool whether the worker died from SIGKILL (it must otherwise exit 0)
 */
function runUpdateWorker(Run $run, string $state, string $artifacts): bool
{
    // The worker writes to a file, not a pipe: a full pipe could never block it.
    $log = "{$state}.log";
    $process = proc_open([PHP_BINARY, base_path('tests/Process/update-worker.php'), (string) $run->id, $state, $artifacts], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the worker.');
    }
    do {
        $status = proc_get_status($process);
        usleep(10_000);
    } while ($status['running']);
    proc_close($process);
    $output = (string) @file_get_contents($log);

    $killed = $status['signaled'] && $status['termsig'] === SIGKILL;
    if (! $killed && $status['exitcode'] !== 0) {
        throw new RuntimeException("Worker failed ({$status['exitcode']}): {$output}");
    }

    return $killed;
}

/**
 * Kills the worker where $prepare said, lets recovery notice (the dead
 * worker's claim and slot expired) and resumes the run in a second worker.
 *
 * @param  Closure(): bool  $worker
 */
function crashAndResume(Run $run, Closure $worker, RunStatus $whenKilled): Run
{
    expect($worker())->toBeTrue('the first worker was not killed')
        ->and($run->fresh()?->status)->toBe($whenKilled);

    // The dead worker's claim, slot and site lock expire, as their TTLs would.
    expect(Redis::connection('locks')->command('ttl', ["wpup:run:{$run->id}:claim"]))->toBeGreaterThan(0);
    Redis::connection('locks')->command('flushdb');
    expect(app(RunRecovery::class)->sweep())->toBe([$run->id]);

    expect($worker())->toBeFalse('the second worker was killed');
    $run = $run->fresh() ?? throw new LogicException;

    // Never left half-done: a final state and no step still running.
    expect($run->status->isTerminal())->toBeTrue()
        ->and($run->steps()->where('status', RunStepStatus::Running->value)->exists())->toBeFalse();

    return $run;
}

/**
 * @return list<array{string, string|null, string|null, int, string}>
 */
function recordedInterruptions(Run $run): array
{
    return array_map(static fn (RunInterruption $i): array => [$i->phase, $i->step, $i->kind?->value, $i->attempts, $i->decision->value], RunInterruption::of($run));
}

/**
 * @return array{string, string}
 */
function siteVersions(CrashingDriver $site): array
{
    return [$site->components['plugin:classic-editor']->version, $site->components['theme:storefront']->version];
}

it('redoes an interrupted backup and updates the site', function (): void {
    withUpdateCrash(static function (CrashingDriver $d): void {
        $d->crashAt = 'archiveFiles';
    }, static function (Run $run, Closure $site, Closure $worker): void {
        $run = crashAndResume($run, $worker, RunStatus::Running);

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and(recordedInterruptions($run))->toBe([['running', BackupStep::NAME, 'forward', 1, 'retry_step']])
            ->and($run->steps()->where('name', BackupStep::NAME)->value('attempts'))->toBe(2)
            // One verified restore point (dump + code), the half-made one discarded.
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            ->and(Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')->whereNotNull('verified_at')->count())->toBe(2)
            ->and(siteVersions($site()))->toBe(['1.6.4', '4.6.1'])
            ->and($site()->maintenance)->toBeFalse()
            ->and(AuditLogEntry::query()->where('object_id', (string) $run->id)->where('action', 'run.resumed')->sole()->context['decision'] ?? null)->toBe('retry_step');
    });
});

it('rolls back a component left half-updated by the dead worker', function (): void {
    withUpdateCrash(static function (CrashingDriver $d): void {
        $d->crashAt = 'applyUpdate:storefront';
    }, static function (Run $run, Closure $site, Closure $worker): void {
        $run = crashAndResume($run, $worker, RunStatus::Running);

        // The theme says 4.6.1 but its install never finished: not trusted, the run reverts.
        expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
            ->and($run->result_summary['failed_step'] ?? null)->toBe('update:theme:storefront')
            ->and($run->failureReason())->toContain("es va interrompre abans d'acabar la instal·lació")
            ->and(recordedInterruptions($run))->toBe([['running', 'update:theme:storefront', 'forward', 1, 'retry_step']])
            ->and(siteVersions($site()))->toBe(['1.6.3', '4.6.0'])
            // The shop stays closed during the rollback, and opens again at the end.
            ->and($site()->maintenanceAtRestore)->toBe(['files on', 'database on'])
            ->and($site()->maintenance)->toBeFalse();
    });
});

it('checks again after a worker killed during the smoke check', function (): void {
    withUpdateCrash(static function (CrashingDriver $d): void {
        $d->crashAt = 'smoke';
    }, static function (Run $run, Closure $site, Closure $worker): void {
        $run = crashAndResume($run, $worker, RunStatus::Running);

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and(recordedInterruptions($run))->toBe([['running', SmokeCheckStep::NAME, 'forward', 1, 'retry_step']])
            ->and($run->steps()->where('name', SmokeCheckStep::NAME)->value('attempts'))->toBe(2)
            ->and(siteVersions($site()))->toBe(['1.6.4', '4.6.1'])
            ->and($site()->maintenance)->toBeFalse();
    });
});

it('finishes a rollback whose worker was killed halfway through the files mirror, with the shop closed', function (): void {
    withUpdateCrash(static function (CrashingDriver $d): void {
        $d->failOn = 'storefront';
        $d->crashAt = 'restoreFiles';
    }, static function (Run $run, Closure $site, Closure $worker): void {
        $run = crashAndResume($run, $worker, RunStatus::RollingBack);

        expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
            ->and($run->result_summary['failed_step'] ?? null)->toBe('update:theme:storefront')
            ->and(recordedInterruptions($run))->toBe([['rolling_back', FilesRollbackStep::NAME, 'recovery', 1, 'retry_step']])
            ->and(siteVersions($site()))->toBe(['1.6.3', '4.6.0'])
            // The mirror keeps `.maintenance`: closed during the dead mirror, the retried one and the database.
            ->and($site()->maintenanceAtRestore)->toBe(['files on', 'files on', 'database on'])
            ->and($site()->maintenance)->toBeFalse();
    });
});
