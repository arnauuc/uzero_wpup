<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Inventory\HttpProbe;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\StepContext;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Enums\AuditChannel;
use App\Enums\PhpVersionSource;
use App\Enums\RunStepStatus;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\SiteSnapshot;
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
    [$run] = smokeIntegrationRun();
    $site = $run->site()->firstOrFail();
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $steps = app(SmokeCheckSteps::class);
    $dir = "{$site->wp_path}/wp-content/plugins/wpup-it-smoke";
    $file = escapeshellarg("{$dir}/wpup-it-smoke.php");
    // A message no earlier run wrote: the fatal must be genuinely new, not a preexisting signature.
    $token = bin2hex(random_bytes(6));

    try {
        // As in production, the log is the one the WEB's PHP reported to the HTTP probe.
        $probe = app(HttpProbe::class)->run($site, $driver);
        expect($probe->errorLog)->not->toBeNull('el probe no ha informat de cap error_log');
        $snapshot = SiteSnapshot::factory()->create([
            'site_id' => $site->id, 'php_source' => PhpVersionSource::HttpProbe,
            'environment' => ['php' => ['error_log' => $probe->errorLog]],
        ]);
        $context = new StepContext($run, false, [InventoryStep::NAME => ['snapshot_id' => $snapshot->id]]);

        $baseline = $steps->baseline()->run($context);
        $context = new StepContext($run, false, [InventoryStep::NAME => ['snapshot_id' => $snapshot->id], PhpLogBaselineStep::NAME => $baseline->data]);
        $healthy = $steps->check()->run($context);

        // The "update" breaks the site: every request now dies with a fatal.
        $write = static fn (string $php): string => 'mkdir -p '.escapeshellarg($dir).' && printf %s '.escapeshellarg(base64_encode($php))." | base64 -d > {$file}";
        Fixtures::run(SMOKE_IT_FIXTURE, $write("<?php\n/* Plugin Name: wpup-it-smoke */\n"));
        $activation = $driver->wp(['plugin', 'activate', 'wpup-it-smoke']);
        // A runtime exception with the token in its message: valid PHP, and a log line no earlier run wrote.
        Fixtures::run(SMOKE_IT_FIXTURE, $write("<?php\n/* Plugin Name: wpup-it-smoke */\nthrow new RuntimeException('wpup-smoke-{$token}');\n"));
        $active = $driver->wp(['plugin', 'is-active', 'wpup-it-smoke', '--skip-plugins', '--skip-themes'])->successful();

        // Retries are only for diagnosis: needing one is a failure too (a broken site that
        // a later request would reveal is exactly what the check must not pass).
        $attempts = [];
        $broken = $steps->check()->run($context);
        $attempts[] = $broken->summary;
        for ($try = 1; $try < 4 && $broken->status !== RunStepStatus::Failed; $try++) {
            sleep(3);
            $broken = $steps->check()->run($context);
            $attempts[] = $broken->summary;
        }

        $diagnosis = 'intents: '.json_encode($attempts, JSON_UNESCAPED_UNICODE)
            .' | activació: '.trim($activation->stdout.' '.$activation->stderr)
            .' | activat: '.($active ? 'sí' : 'no')
            .' | log: '.trim(Fixtures::run(SMOKE_IT_FIXTURE, 'tail -n 3 '.escapeshellarg((string) $probe->errorLog).' 2>&1 | cut -c1-200'));

        expect($baseline->status)->toBe(RunStepStatus::Succeeded);
        expect($baseline->summary)->not->toContain('no verificat');
        expect($healthy->status)->toBe(RunStepStatus::Succeeded, $healthy->summary);
        expect($active)->toBeTrue($diagnosis);
        expect(count($attempts))->toBe(1, 'el check ha necessitat reintents: '.$diagnosis);
        expect($broken->status)->toBe(RunStepStatus::Failed, $diagnosis);
        expect($broken->output)->toContain("wpup-smoke-{$token}");
        expect($broken->data['fatals'] > 0 || $broken->data['http_status'] >= 500)->toBeTrue($diagnosis);
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

        expect(fn () => $driver->phpLogScanSince(new LogCursor($path, 0, $inode), 10, 10))->toThrow(DriverException::class);
        expect(fn () => $driver->phpLogScanBefore(new LogCursor($path, 10, $inode), 1024, 10, 10))->toThrow(DriverException::class);
    } finally {
        Fixtures::run(SMOKE_IT_FIXTURE, 'rm -f '.escapeshellarg($path));
    }
});
