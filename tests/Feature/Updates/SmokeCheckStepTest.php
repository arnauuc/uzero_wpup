<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckSettings;
use App\Domain\Updates\SmokeCheckStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    app(SecretRedactor::class)->reset();
    Http::preventStrayRequests();
});

function smokeSteps(FakeDriver $driver): SmokeCheckSteps
{
    return new SmokeCheckSteps(
        static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class),
        app(HttpFactory::class),
        app(SecretRedactor::class),
        app(SmokeCheckSettings::class),
    );
}

/**
 * Baseline taken, then the lines the update wrote.
 *
 * @param  list<string>  $newLines
 */
function smokeContext(FakeDriver $driver, array $newLines = []): StepContext
{
    $run = Run::factory()->create(['site_id' => Site::factory(['url' => 'https://shop.test'])]);
    $baseline = smokeSteps($driver)->baseline()->run(new StepContext($run, false));
    foreach ($newLines as $line) {
        $driver->phpLog[] = $line;
    }

    return new StepContext($run, false, [PhpLogBaselineStep::NAME => $baseline->data]);
}

it('saves the log position before the update and reads only what comes after', function (): void {
    $driver = new FakeDriver;
    $driver->phpLog = ['PHP Fatal error:  old, before the update'];
    Http::fake(['shop.test' => Http::response('<html>', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, ['PHP Warning:  harmless']));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->data['fatals'])->toBe(0)
        ->and($outcome->data['http_status'])->toBe(200)
        ->and($outcome->summary)->toContain('HTTP 200');
});

it('fails on a new fatal and shows it redacted', function (): void {
    $driver = new FakeDriver;
    app(SecretRedactor::class)->register('hunter2-secret');
    Http::fake(['shop.test' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, ['PHP Fatal error:  Uncaught Error: boom hunter2-secret in /x.php:1']));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('1 error(s) fatal(s) nou(s)')
        ->and($outcome->output)->toContain('Uncaught Error: boom')
        ->and($outcome->output)->not->toContain('hunter2-secret');
});

it('fails on a 5xx even when the log is clean', function (int $status): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test' => Http::response('', $status)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain("HTTP {$status}");
})->with([500, 502, 503]);

it('does not fail on a 4xx or a redirect that ends well', function (int $status): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test' => Http::response('', $status)]);

    expect(smokeSteps($driver)->check()->run(smokeContext($driver))->status)->toBe(RunStepStatus::Succeeded);
})->with([301, 403, 404]);

it('treats a connection error or timeout as a failure', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test' => static fn () => throw new ConnectionException('cURL error 28: timed out')]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('la portada no respon');
});

it('asks for the home without credentials or cookies', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test' => Http::response('ok', 200)]);

    smokeSteps($driver)->check()->run(smokeContext($driver));

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://shop.test'
        && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie'));
});

it('warns and keeps the HTTP check when the log was unreadable at the baseline', function (): void {
    $driver = new class extends FakeDriver
    {
        public function phpLogCursor(): LogCursor
        {
            throw new DriverException('permission denied');
        }
    };
    Http::fake(['shop.test' => Http::response('', 500)]);

    $context = smokeContext($driver);
    $outcome = smokeSteps($driver)->check()->run($context);

    expect($context->resultOf(PhpLogBaselineStep::NAME)['path'])->toBeNull()
        ->and($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('AVÍS')->toContain('HTTP 500');
});

it('only warns when the log cannot be read after the update and the site answers', function (): void {
    $driver = new class extends FakeDriver
    {
        public function phpLogSince(LogCursor $cursor, int $maxBytes = 1_048_576): array
        {
            throw new DriverException('ssh dropped');
        }
    };
    Http::fake(['shop.test' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toContain('AVÍS')->toContain('ssh dropped');
});

it('warns when no baseline was saved', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['url' => 'https://shop.test'])]);
    Http::fake(['shop.test' => Http::response('ok', 200)]);

    $outcome = smokeSteps(new FakeDriver)->check()->run(new StepContext($run, false));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toContain('no hi ha cap posició base');
});

it('declares the baseline read only and the check not', function (): void {
    $steps = smokeSteps(new FakeDriver);

    expect($steps->baseline())->toBeInstanceOf(ReadOnlyStep::class)
        ->and($steps->check())->not->toBeInstanceOf(ReadOnlyStep::class)
        ->and($steps->baseline()->name())->toBe('smoke:php-log-baseline')
        ->and($steps->check()->name())->toBe(SmokeCheckStep::NAME)
        ->and($steps->check()->timeoutSeconds())->toBe(config('wpup.smoke_check.step_timeout_seconds'));
});
