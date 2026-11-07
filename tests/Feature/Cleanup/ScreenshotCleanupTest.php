<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Cleanup\ScreenshotCleanup;
use App\Enums\ChangeResult;
use App\Enums\RunStatus;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use Carbon\CarbonImmutable;

/*
 * Screenshot retention (ADR-0009), with a simulated clock: 30 days, 180 with a
 * warning or an error, and each site's last run always kept.
 */

const SHOTS_NOW = '2026-11-07 06:45:00';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(SHOTS_NOW);
    config(['wpup.verifier.work_dir' => sys_get_temp_dir().'/wpup-shots-'.bin2hex(random_bytes(6))]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    exec('rm -rf '.escapeshellarg(shotsRoot()));
});

function shotsRoot(): string
{
    $dir = config('wpup.verifier.work_dir');

    return is_string($dir) ? $dir : throw new LogicException('wpup.verifier.work_dir is not set.');
}

/**
 * A finished run of $site with one screenshot and its result.json.
 *
 * @param  array<string, mixed>  $page  overrides of the page result
 */
function runWithShots(Site $site, int $daysAgo, RunStatus $status = RunStatus::Succeeded, array $page = [], bool $partial = false): Run
{
    $run = Run::factory()->create(['site_id' => $site->id, 'status' => $status, 'finished_at' => CarbonImmutable::parse(SHOTS_NOW)->subDays($daysAgo)->subMinute()]);
    $dir = shotsRoot()."/run-{$run->id}/verify-after";
    mkdir("{$dir}/desktop", 0700, true);
    file_put_contents("{$dir}/desktop/0.png", 'png');
    file_put_contents("{$dir}/result.json", json_encode(['version' => 1, 'run_id' => $run->id, 'partial' => $partial, 'verifier_errors' => [], 'results' => [[
        'path' => '/', 'profile' => 'home', 'viewport' => 'desktop', 'http_status' => 200, 'screenshot' => 'desktop/0.png',
        'console_errors' => [], 'resource_errors' => [], 'blocked_requests' => [], 'timings' => ['navigation_ms' => 1], 'error' => null, ...$page,
    ]]]));

    return $run;
}

function shotKept(Run $run): bool
{
    return is_file(shotsRoot()."/run-{$run->id}/verify-after/desktop/0.png");
}

function cleanShots(bool $dryRun = false): int
{
    return app(ScreenshotCleanup::class)->run(AuditActor::system(), CarbonImmutable::parse(SHOTS_NOW), $dryRun)->count;
}

it('deletes clean screenshots after 30 days and flagged ones after 180, keeping result.json', function (): void {
    $site = Site::factory()->create();
    $clean31 = runWithShots($site, 31);
    $clean29 = runWithShots($site, 29);
    $console = runWithShots($site, 31, page: ['console_errors' => ['boom']]);
    $http = runWithShots($site, 31, page: ['http_status' => 500]);
    $partial = runWithShots($site, 31, partial: true);
    $failed = runWithShots($site, 31, RunStatus::Failed);
    $reverted = runWithShots($site, 31);
    RunChange::factory()->create(['run_id' => $reverted->id, 'result' => ChangeResult::Reverted]);
    $flagged181 = runWithShots($site, 181, RunStatus::RolledBack);
    $latest = runWithShots($site, 1);

    expect(cleanShots())->toBe(2)
        ->and(shotKept($clean31))->toBeFalse()
        ->and(is_file(shotsRoot()."/run-{$clean31->id}/verify-after/result.json"))->toBeTrue()
        ->and(shotKept($clean29))->toBeTrue()
        ->and(collect([$console, $http, $partial, $failed, $reverted, $latest])->every(shotKept(...)))->toBeTrue()
        ->and(shotKept($flagged181))->toBeFalse();

    $audit = AuditLogEntry::query()->where('action', 'cleanup.screenshots')->sole();
    expect($audit->context['screenshots'])->toBe(2);
});

it("always keeps each site's last run, whatever its age, and never touches unfinished runs", function (): void {
    $old = runWithShots(Site::factory()->create(), 400);
    $otherSite = Site::factory()->create();
    $previous = runWithShots($otherSite, 400);
    $running = runWithShots($otherSite, 400);
    $running->update(['status' => RunStatus::Running, 'finished_at' => null]);

    expect(cleanShots())->toBe(1)
        ->and(shotKept($old))->toBeTrue()
        ->and(shotKept($previous))->toBeFalse()
        ->and(shotKept($running))->toBeTrue();
});

it('keeps longer when the result is missing or unreadable, and changes nothing on a dry run', function (): void {
    $site = Site::factory()->create();
    $unreadable = runWithShots($site, 31);
    file_put_contents(shotsRoot()."/run-{$unreadable->id}/verify-after/result.json", '{"version":9}');
    $clean = runWithShots($site, 31);
    runWithShots($site, 1);

    expect(cleanShots(dryRun: true))->toBe(1)
        ->and(shotKept($clean))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'cleanup.screenshots')->exists())->toBeFalse()
        ->and(cleanShots())->toBe(1)
        ->and(shotKept($clean))->toBeFalse()
        ->and(shotKept($unreadable))->toBeTrue();
});
