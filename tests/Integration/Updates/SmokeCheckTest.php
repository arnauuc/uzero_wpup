<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Runs\StepContext;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Enums\AuditChannel;
use App\Enums\RunStepStatus;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\User;
use Tests\Support\Fixtures;

/*
 * The minimal layer-3 check against a Docker test site (make fixtures-up),
 * never a real one: a plugin that breaks the site after the baseline must make
 * the step fail (the failure is what triggers the rollback). The plugin is
 * always removed afterwards; restore the fixture with docker/fixtures/snapshot.sh.
 */

const SMOKE_IT_FIXTURE = 'wp83';

if (! Fixtures::up(SMOKE_IT_FIXTURE)) {
    it('fails the smoke check when an update breaks the site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/**
 * @return array{Run, StepContext}
 */
function smokeIntegrationRun(): array
{
    $site = Fixtures::site(SMOKE_IT_FIXTURE);
    $site->update(['url' => 'http://'.SMOKE_IT_FIXTURE.':8083']);
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $run = Run::factory()->create(['site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name, 'channel' => AuditChannel::Cli]);

    return [$run, new StepContext($run, false)];
}

it('passes on a healthy site and fails when a plugin updated after the baseline breaks it', function (): void {
    [$run, $context] = smokeIntegrationRun();
    $site = $run->site()->firstOrFail();
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $steps = app(SmokeCheckSteps::class);
    $dir = "{$site->wp_path}/wp-content/plugins/wpup-it-smoke";
    $file = escapeshellarg("{$dir}/wpup-it-smoke.php");
    // A message no earlier run wrote: the fatal must be genuinely new, not a preexisting signature.
    $token = bin2hex(random_bytes(6));

    try {
        $baseline = $steps->baseline()->run($context);
        $context = new StepContext($run, false, [PhpLogBaselineStep::NAME => $baseline->data]);
        $healthy = $steps->check()->run($context);

        // The "update" breaks the site: every request now dies with a fatal.
        Fixtures::run(SMOKE_IT_FIXTURE, 'mkdir -p '.escapeshellarg($dir)." && printf '<?php\\n/* Plugin Name: wpup-it-smoke */\\n' > {$file}");
        $driver->wp(['plugin', 'activate', 'wpup-it-smoke']);
        Fixtures::run(SMOKE_IT_FIXTURE, "printf '<?php\\n/* Plugin Name: wpup-it-smoke */\\nthrow new Error(\"wpup-it-smoke fatal {$token}\");\\n' > {$file}");
        $broken = $steps->check()->run($context);

        expect($baseline->status)->toBe(RunStepStatus::Succeeded)
            ->and($healthy->status)->toBe(RunStepStatus::Succeeded, $healthy->summary)
            ->and($broken->status)->toBe(RunStepStatus::Failed, $broken->summary)
            ->and($broken->data['fatals'] > 0 || $broken->data['http_status'] >= 500)->toBeTrue($broken->summary);
    } finally {
        Fixtures::run(SMOKE_IT_FIXTURE, 'rm -rf '.escapeshellarg($dir));
        $driver->wp(['plugin', 'deactivate', 'wpup-it-smoke', '--skip-plugins', '--skip-themes']);
    }
});

it('reports an unreadable log as an error, never as a log without fatals', function (): void {
    [$run] = smokeIntegrationRun();
    $driver = app(DriverFactory::class)->forSite($run->site()->firstOrFail(), AuditActor::system('tests'));
    $name = 'wpup-it-unreadable-'.bin2hex(random_bytes(4)).'.log';
    $path = trim(Fixtures::run(SMOKE_IT_FIXTURE, 'echo "$HOME"')).'/'.$name;

    try {
        Fixtures::run(SMOKE_IT_FIXTURE, 'printf "PHP Fatal error:  hidden\\n" > '.escapeshellarg($path).' && chmod 000 '.escapeshellarg($path));
        $inode = trim(Fixtures::run(SMOKE_IT_FIXTURE, 'stat -c %i '.escapeshellarg($path)));

        expect(fn () => $driver->phpLogFatalsSince(new LogCursor($path, 0, $inode), 10))->toThrow(DriverException::class);
        expect(fn () => $driver->phpLogFatalsBefore(new LogCursor($path, 10, $inode), 1024, 10))->toThrow(DriverException::class);
    } finally {
        Fixtures::run(SMOKE_IT_FIXTURE, 'rm -f '.escapeshellarg($path));
    }
});
