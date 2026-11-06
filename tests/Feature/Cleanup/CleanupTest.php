<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Cleanup\CleanupResult;
use App\Domain\Cleanup\CleanupService;
use App\Domain\Cleanup\CleanupSettings;
use App\Domain\Cleanup\CleanupTask;
use App\Domain\Identity\CliCommandGate;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\PackageVersion;
use App\Models\Run;
use App\Models\RunArtifact;
use App\Models\RunStep;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Redis;

/*
 * The daily cleanup and the ADR-0009 retention table (UZEROWPUP-134), with a
 * simulated clock: every row of the table at both sides of its limit.
 */

const CLEANUP_NOW = '2026-11-07 06:45:00';

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-cleanup-'.bin2hex(random_bytes(6))]);
    Carbon::setTestNow(CLEANUP_NOW);
    CarbonImmutable::setTestNow(CLEANUP_NOW);
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    exec('chmod -R u+w '.escapeshellarg(artifactsRoot()).' 2>/dev/null; rm -rf '.escapeshellarg(artifactsRoot()));
});

function cleanupNow(): CarbonImmutable
{
    return CarbonImmutable::parse(CLEANUP_NOW);
}

function runFinished(RunStatus $status, int $daysAgo): Run
{
    return Run::factory()->create(['status' => $status, 'finished_at' => cleanupNow()->subDays($daysAgo)->subMinute()]);
}

function stepWithOutput(Run $run, string $output = 'full masked output'): RunStep
{
    return RunStep::factory()->create(['run_id' => $run->id, 'output' => $output, 'summary' => 'resum']);
}

/**
 * @param  list<CleanupResult>  $results
 */
function resultOf(array $results, string $task): CleanupResult
{
    foreach ($results as $result) {
        if ($result->task === $task) {
            return $result;
        }
    }

    throw new LogicException("No result for {$task}.");
}

function stepKept(RunStep $step): bool
{
    return $step->fresh()?->output !== null;
}

function cleanupArtifact(Run $run, int $daysAgo): RunArtifact
{
    $store = app(ArtifactStore::class);
    $temporary = $store->temporaryFile($run->id);
    file_put_contents($temporary, 'zip bytes');
    $source = PackageSource::create(ComponentType::Plugin, 'plugin-x', '1.0.0', 'https://downloads.wordpress.org/plugin/x.zip', app(PackageUrlGuard::class));
    $artifact = $store->commit($run->id, $source, $temporary, hash('sha256', 'zip bytes'), 9);
    $row = RunArtifact::query()->findOrFail($artifact->id);
    $row->forceFill(['created_at' => cleanupNow()->subDays($daysAgo)])->save();

    return $row;
}

it('empties the full output of correct runs after 90 days and keeps the rest of the step', function (): void {
    $old = stepWithOutput(runFinished(RunStatus::Succeeded, 91));
    $edge = stepWithOutput(runFinished(RunStatus::Succeeded, 90));
    $recent = stepWithOutput(runFinished(RunStatus::Succeeded, 89));

    $results = app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    $output = resultOf($results, 'run_output');
    expect($output->count)->toBe(2)
        ->and($output->bytes)->toBe(2 * strlen('full masked output'))
        ->and(stepKept($old))->toBeFalse()
        ->and(stepKept($edge))->toBeFalse()
        ->and(stepKept($recent))->toBeTrue()
        ->and($old->fresh()?->summary)->toBe('resum')
        ->and($old->fresh()?->output_purged_at?->equalTo(cleanupNow()))->toBeTrue();
});

it('keeps the output of failed and reverted runs for one year', function (RunStatus $status): void {
    $old = stepWithOutput(runFinished($status, 366));
    $edge = stepWithOutput(runFinished($status, 365));
    $recentButPastNinety = stepWithOutput(runFinished($status, 200));

    app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    expect(stepKept($old))->toBeFalse()
        ->and(stepKept($edge))->toBeFalse()
        ->and(stepKept($recentButPastNinety))->toBeTrue();
})->with([RunStatus::Failed, RunStatus::RolledBack]);

it('never touches the output of unfinished runs, however old', function (): void {
    $running = stepWithOutput(Run::factory()->create(['status' => RunStatus::Running, 'started_at' => cleanupNow()->subDays(500)]));
    $interrupted = stepWithOutput(runFinished(RunStatus::Interrupted, 500));

    app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    expect(stepKept($running))->toBeTrue()->and(stepKept($interrupted))->toBeTrue();
});

it('keeps changes, verifications and the run itself when emptying the output', function (): void {
    $run = runFinished(RunStatus::Succeeded, 400);
    stepWithOutput($run);

    app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    expect(Run::query()->whereKey($run->id)->exists())->toBeTrue()
        ->and(RunStep::query()->where('run_id', $run->id)->count())->toBe(1);
});

it('purges artifacts only after 30 days and once the restore point is gone', function (): void {
    $gone = runFinished(RunStatus::Succeeded, 40);
    Backup::factory()->create(['run_id' => $gone->id, 'site_id' => $gone->site_id, 'deleted_at' => cleanupNow()->subDays(10)]);
    $purgeable = cleanupArtifact($gone, 31);

    $tooRecent = cleanupArtifact(runFinished(RunStatus::Succeeded, 40), 29);

    $live = runFinished(RunStatus::Succeeded, 40);
    Backup::factory()->create(['run_id' => $live->id, 'site_id' => $live->site_id, 'deleted_at' => null]);
    $protected = cleanupArtifact($live, 200);

    $result = resultOf(app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false), 'artifacts');

    expect($result->count)->toBe(1)
        ->and($purgeable->fresh()?->purged_at)->not->toBeNull()
        ->and($tooRecent->fresh()?->purged_at)->toBeNull()
        ->and($protected->fresh()?->purged_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'artifacts.purged')->count())->toBe(1);
});

it('drops package versions no inventory offered for a year', function (): void {
    foreach ([['old', 366], ['recent', 364]] as [$slug, $days]) {
        PackageVersion::query()->create([
            'component_type' => 'plugin', 'slug' => $slug, 'version' => '1.0', 'source' => "wordpress.org:{$slug}",
            'first_seen_at' => cleanupNow()->subDays($days + 10), 'last_seen_at' => cleanupNow()->subDays($days), 'released_at' => null,
        ]);
    }

    app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    expect(PackageVersion::query()->pluck('slug')->all())->toBe(['recent']);
    $audit = AuditLogEntry::query()->where('action', 'cleanup.package_versions')->sole();
    expect($audit->context['versions'])->toBe(1);
});

it('has a screenshots extension point that does nothing until F4 and refuses lower periods', function (): void {
    $result = resultOf(app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false), 'screenshots');
    $settings = app(CleanupSettings::class);

    expect($result->count)->toBe(0)->and($result->note)->toContain('F4')
        ->and([$settings->screenshotsDays, $settings->screenshotsFlaggedDays])->toBe([30, 180])
        ->and(AuditLogEntry::query()->where('action', 'cleanup.screenshots')->exists())->toBeFalse()
        ->and(fn () => new CleanupSettings(89, 365, 30, 180))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CleanupSettings(90, 364, 30, 180))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CleanupSettings(90, 365, 29, 180))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CleanupSettings(90, 365, 30, 179))->toThrow(InvalidArgumentException::class);
});

it('never touches audit_log, however old the entries', function (): void {
    $entry = AuditLogEntry::factory()->create(['occurred_at' => cleanupNow()->subYears(5)]);
    stepWithOutput(runFinished(RunStatus::Succeeded, 400));

    app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), false);

    expect(AuditLogEntry::query()->whereKey($entry->id)->exists())->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'like', 'cleanup.%')->count())->toBe(1);
});

it('writes nothing on a dry run', function (): void {
    $step = stepWithOutput(runFinished(RunStatus::Succeeded, 400));
    PackageVersion::query()->create([
        'component_type' => 'plugin', 'slug' => 'old', 'version' => '1.0', 'source' => 'wordpress.org:old',
        'first_seen_at' => cleanupNow()->subDays(500), 'last_seen_at' => cleanupNow()->subDays(500), 'released_at' => null,
    ]);
    $audits = AuditLogEntry::query()->count();

    $results = app(CleanupService::class)->run(AuditActor::system(), cleanupNow(), true);

    expect(resultOf($results, 'run_output')->count)->toBe(1)->and(resultOf($results, 'run_output')->dryRun)->toBeTrue()
        ->and(resultOf($results, 'package_versions')->count)->toBe(1)
        ->and(stepKept($step))->toBeTrue()
        ->and(PackageVersion::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->count())->toBe($audits);
});

it('keeps going when a class fails and reports it', function (): void {
    $failing = new class implements CleanupTask
    {
        public function name(): string
        {
            return 'failing';
        }

        public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
        {
            throw new RuntimeException('secret-token-123');
        }
    };
    $ran = new class implements CleanupTask
    {
        public function name(): string
        {
            return 'ran';
        }

        public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
        {
            return new CleanupResult('ran', 1, 0, $dryRun);
        }
    };

    $results = (new CleanupService([$failing, $ran]))->run(AuditActor::system(), cleanupNow(), false);

    expect($results)->toHaveCount(2)
        ->and($results[0]->failed())->toBeTrue()
        ->and(implode(' ', $results[0]->failures))->not->toContain('secret-token-123')
        ->and($results[1]->count)->toBe(1);
});

it('audits a failed class and exits with an error', function (): void {
    app()->bind(CleanupService::class, static fn (): CleanupService => new CleanupService([new class implements CleanupTask
    {
        public function name(): string
        {
            return 'failing';
        }

        public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
        {
            return new CleanupResult('failing', 0, 0, $dryRun, null, ['No ha anat.']);
        }
    }]));
    actingAsOperator('admin1');

    command('cleanup', ['--force' => true])->expectsOutputToContain('No ha anat.')->assertFailed();
});

it('is admin only, asks for confirmation and audits what it purges', function (): void {
    $step = stepWithOutput(runFinished(RunStatus::Succeeded, 400));

    actingAsOperator('oper1');
    command('cleanup', ['--force' => true])->assertFailed();
    expect(stepKept($step))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'command.denied')->where('result', AuditResult::Denied->value)->exists())->toBeTrue();

    actingAsOperator('admin1');
    command('cleanup')->expectsConfirmation('Aplicar la neteja de retenció? No es pot desfer.', 'no')->assertFailed();
    expect(stepKept($step))->toBeTrue();

    command('cleanup', ['--dry-run' => true])->assertSuccessful();
    expect(stepKept($step))->toBeTrue();

    command('cleanup')->expectsConfirmation('Aplicar la neteja de retenció? No es pot desfer.', 'yes')->assertSuccessful();
    $audit = AuditLogEntry::query()->where('action', 'cleanup.run_output')->sole();
    expect(stepKept($step))->toBeFalse()
        ->and($audit->result)->toBe(AuditResult::Success)
        ->and($audit->channel)->toBe(AuditChannel::Cli)
        ->and($audit->context['steps'])->toBe(1);
});

it('refuses --json without --dry-run or --force', function (): void {
    actingAsOperator('admin1');

    command('cleanup', ['--json' => true])->assertFailed();
    command('cleanup', ['--json' => true, '--dry-run' => true])->assertSuccessful();
});

it('runs from the scheduler daily without asking, audited as the system', function (): void {
    $step = stepWithOutput(runFinished(RunStatus::Succeeded, 400));

    command('engine:cleanup', ['--dry-run' => true])->assertSuccessful();
    expect(stepKept($step))->toBeTrue();

    command('engine:cleanup')->assertSuccessful();
    $audit = AuditLogEntry::query()->where('action', 'cleanup.run_output')->sole();
    expect(stepKept($step))->toBeFalse()->and($audit->channel)->toBe(AuditChannel::System);

    $events = array_values(array_filter(app(Schedule::class)->events(), static fn (ScheduledEvent $e): bool => str_contains((string) $e->command, 'engine:cleanup')));
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('45 6 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue();

    $gate = app(CliCommandGate::class);
    expect($gate->allows('engine:cleanup'))->toBeFalse()->and($gate->allows('cleanup'))->toBeTrue();
});
