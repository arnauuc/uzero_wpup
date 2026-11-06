<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Data\LogScan;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckSettings;
use App\Domain\Updates\SmokeCheckStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Enums\PhpVersionSource;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDriver;

const SMOKE_FATAL = '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /x.php:1';

beforeEach(function (): void {
    app(SecretRedactor::class)->reset();
    Http::preventStrayRequests();
});

function smokeSteps(FakeDriver $driver, ?SmokeCheckSettings $settings = null, int $connect = 15, int $command = 300): SmokeCheckSteps
{
    return new SmokeCheckSteps(
        static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class),
        app(HttpFactory::class),
        app(SecretRedactor::class),
        $settings ?? app(SmokeCheckSettings::class),
        $connect,
        $command,
    );
}

/**
 * Baseline taken, then the lines the update wrote.
 *
 * @param  list<string>  $newLines
 */
function smokeContext(FakeDriver $driver, array $newLines = [], string $url = 'https://shop.test'): StepContext
{
    $run = Run::factory()->create(['site_id' => Site::factory(['url' => $url])]);
    $baseline = smokeSteps($driver)->baseline()->run(new StepContext($run, false));
    foreach ($newLines as $line) {
        $driver->phpLog[] = $line;
    }

    return new StepContext($run, false, [PhpLogBaselineStep::NAME => $baseline->data]);
}

it('saves the log position before the update and reads only what comes after', function (): void {
    $driver = new FakeDriver;
    $driver->phpLog = ['[05-Nov-2026 09:00:00 UTC] PHP Fatal error:  Uncaught Error: old in /old.php:1'];
    Http::fake(['shop.test/*' => Http::response('<html>', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, ['[06-Nov-2026 10:00:00 UTC] PHP Warning:  harmless']));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->data['fatals'])->toBe(0)
        ->and($outcome->data['http_status'])->toBe(200)
        ->and($outcome->summary)->toContain('HTTP 200');
});

it('fails on a new fatal and shows it redacted', function (): void {
    $driver = new FakeDriver;
    app(SecretRedactor::class)->register('hunter2-secret');
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, ['[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom hunter2-secret in /x.php:1']));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('1 error(s) fatal(s) nou(s)')
        ->and($outcome->output)->toContain('Uncaught Error: boom')
        ->and($outcome->output)->not->toContain('hunter2-secret');
});

it('does not count application messages that only mention a fatal error', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, ['[06-Nov-2026 10:00:00 UTC] Stripe: Fatal error: card declined']));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded);
});

it('only warns about a fatal that was already in the log before the baseline', function (): void {
    $driver = new FakeDriver;
    $driver->phpLog = ['[05-Nov-2026 09:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /x.php:1'];
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $context = smokeContext($driver, [SMOKE_FATAL, '[06-Nov-2026 10:00:05 UTC] PHP Fatal error:  Uncaught Error: another in /y.php:2']);
    $outcome = smokeSteps($driver)->check()->run($context);

    expect($context->resultOf(PhpLogBaselineStep::NAME)['signatures'])->toHaveCount(1)
        ->and($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->data['fatals'])->toBe(1)
        ->and($outcome->data['repeated_fatals'])->toBe(1)
        ->and($outcome->summary)->toContain('fatal preexistent repetit');

    // The repeat alone passes.
    $driver = new FakeDriver;
    $driver->phpLog = ['[05-Nov-2026 09:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /x.php:1'];
    $repeat = smokeSteps($driver)->check()->run(smokeContext($driver, [SMOKE_FATAL]));
    expect($repeat->status)->toBe(RunStepStatus::Succeeded)->and($repeat->summary)->toContain('fatal preexistent repetit');
});

it('fails on a 5xx even when the log is clean', function (int $status): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('', $status)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain("HTTP {$status}");
})->with([500, 502, 503]);

it('does not fail on a 4xx', function (int $status): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('', $status)]);

    expect(smokeSteps($driver)->check()->run(smokeContext($driver))->status)->toBe(RunStepStatus::Succeeded);
})->with([301, 403, 404]);

it('treats a connection error or timeout as a failure', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => static fn () => throw new ConnectionException('cURL error 28: timed out')]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('la portada no respon');
});

it('asks for the home with a cache buster and no-cache headers, without credentials or cookies', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    smokeSteps($driver)->check()->run(smokeContext($driver));
    smokeSteps($driver)->check()->run(smokeContext($driver));

    Http::assertSent(static fn (Request $request): bool => preg_match('#^https://shop\.test/\?wpup-smoke=[0-9a-f]{16}$#', $request->url()) === 1
        && $request->header('Cache-Control') === ['no-cache'] && $request->header('Pragma') === ['no-cache']
        && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie'));
    $urls = Http::recorded()->map(static fn (array $pair): string => $pair[0]->url())->all();
    expect(array_unique($urls))->toHaveCount(2);
});

it('keeps the query string of the site url when adding the cache buster', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    smokeSteps($driver)->check()->run(smokeContext($driver, [], 'https://shop.test/en/?lang=ca'));

    Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://shop.test/en/?lang=ca&wpup-smoke='));
});

it('requests the home before it reads the log, so what the request breaks counts', function (): void {
    $driver = new class extends FakeDriver
    {
        public function phpLogFatalsSince(LogCursor $cursor, int $maxLines): LogScan
        {
            // The request already happened, and it wrote this fatal.
            Http::assertSentCount(1);
            $this->phpLog[] = SMOKE_FATAL;

            return parent::phpLogFatalsSince($cursor, $maxLines);
        }
    };
    Http::fake(['shop.test/*' => Http::response('', 500)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Failed)->and($outcome->data['fatals'])->toBe(1);
});

it('warns and keeps the HTTP check when the log was unreadable at the baseline', function (): void {
    $driver = new class extends FakeDriver
    {
        public function phpLogCursor(?string $webLogPath = null): LogCursor
        {
            throw new DriverException('permission denied');
        }
    };
    Http::fake(['shop.test/*' => Http::response('', 500)]);

    $context = smokeContext($driver);
    $outcome = smokeSteps($driver)->check()->run($context);

    expect($context->resultOf(PhpLogBaselineStep::NAME)['path'])->toBeNull()
        ->and($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('AVÍS')->toContain('HTTP 500');
});

it('only warns when the log cannot be read after the update, whatever the error, and the site answers', function (Throwable $error): void {
    $driver = new class extends FakeDriver
    {
        public ?Throwable $error = null;

        public function phpLogFatalsSince(LogCursor $cursor, int $maxLines): LogScan
        {
            throw $this->error ?? new RuntimeException('?');
        }
    };
    $driver->error = $error;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toContain('AVÍS')->toContain('no s\'ha pogut llegir el log');
})->with([
    'driver error' => fn () => new DriverException('ssh dropped'),
    'unexpected error' => fn () => new TypeError('bad output'),
]);

it('warns when no baseline was saved', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['url' => 'https://shop.test'])]);
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $outcome = smokeSteps(new FakeDriver)->check()->run(new StepContext($run, false));

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toContain('no hi ha cap posició base');
});

it('warns explicitly when the log rotated, and still finds the fatals of the rotated file', function (): void {
    $driver = new FakeDriver;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);
    $context = smokeContext($driver);
    $driver->logRotated = true;
    $driver->rotatedSiblingLines = [SMOKE_FATAL];

    $outcome = smokeSteps($driver)->check()->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('el log ha rotat durant l\'actualització: poden faltar línies')->toContain('fitxer rotat');
});

it('warns when there were more candidate lines than the cap', function (): void {
    $driver = new FakeDriver;
    $driver->logCandidateLimit = 1;
    Http::fake(['shop.test/*' => Http::response('ok', 200)]);

    $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, [SMOKE_FATAL, SMOKE_FATAL.'2']));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('línies candidates');
});

it('uses the error_log the web reported, and warns when it only has the CLI one', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['url' => 'https://shop.test'])]);
    $snapshot = SiteSnapshot::factory()->create([
        'site_id' => $run->site_id,
        'php_source' => PhpVersionSource::HttpProbe,
        'environment' => ['php' => ['error_log' => '/var/www/logs/web-php.log']],
    ]);
    $driver = new FakeDriver;

    $web = smokeSteps($driver)->baseline()->run(new StepContext($run, false, [InventoryStep::NAME => ['snapshot_id' => $snapshot->id]]));

    expect($driver->requestedWebLog)->toBe('/var/www/logs/web-php.log')
        ->and($web->data['path'])->toBe('/var/www/logs/web-php.log')
        ->and($web->data['web_verified'])->toBeTrue()
        ->and($web->summary)->not->toContain('no verificat');

    $driver = new FakeDriver;
    $cli = smokeSteps($driver)->baseline()->run(new StepContext(Run::factory()->create(['site_id' => Site::factory()]), false));

    expect($driver->requestedWebLog)->toBeNull()
        ->and($cli->data['web_verified'])->toBeFalse()
        ->and($cli->summary)->toContain('camí del log no verificat pel web');
});

it('declares the baseline read only and the check not', function (): void {
    $steps = smokeSteps(new FakeDriver);

    expect($steps->baseline())->toBeInstanceOf(ReadOnlyStep::class)
        ->and($steps->check())->not->toBeInstanceOf(ReadOnlyStep::class)
        ->and($steps->baseline()->name())->toBe('smoke:php-log-baseline')
        ->and($steps->check()->name())->toBe(SmokeCheckStep::NAME)
        ->and($steps->check()->timeoutSeconds())->toBe(config('wpup.smoke_check.step_timeout_seconds'));
});

it('refuses a step timeout below the worst case', function (): void {
    $settings = new SmokeCheckSettings(600, 30, 5, true, 200, 1024, 5, 300, app(SmokeCheckSettings::class)->fatalPatterns);

    expect(fn () => smokeSteps(new FakeDriver, $settings))->toThrow(InvalidArgumentException::class, 'pitjor cas');
    expect(smokeSteps(new FakeDriver, $settings, 15, 20))->toBeInstanceOf(SmokeCheckSteps::class);
});

/*
 * Real redirects: Http::fake() answers before Guzzle's redirect middleware,
 * so these talk to a throwaway PHP server on the loopback.
 */
describe('with a real server', function (): void {
    /** @var resource|null $server */
    $server = null;
    $port = 0;

    beforeEach(function () use (&$server, &$port): void {
        $router = sys_get_temp_dir().'/wpup-smoke-router-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($router, <<<'PHP'
        <?php
        $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($p === '/hop') { header('Location: /landing', true, 302); exit; }
        if ($p === '/loop') { header('Location: /loop', true, 302); exit; }
        if ($p === '/broken') { http_response_code(503); echo 'down'; exit; }
        echo 'landed';
        PHP);
        $port = random_int(20000, 40000);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100_000);
        }
        Http::allowStrayRequests();
    });

    afterEach(function () use (&$server): void {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    });

    it('follows a redirect to a healthy page', function () use (&$port): void {
        $driver = new FakeDriver;

        $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, [], "http://127.0.0.1:{$port}/hop"));

        expect($outcome->status)->toBe(RunStepStatus::Succeeded)->and($outcome->data['http_status'])->toBe(200);
    });

    it('fails with a clear message on a redirect loop', function () use (&$port): void {
        $driver = new FakeDriver;

        $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, [], "http://127.0.0.1:{$port}/loop"));

        expect($outcome->status)->toBe(RunStepStatus::Failed)->and($outcome->summary)->toContain('massa redireccions');
    });

    it('fails on a real 503', function () use (&$port): void {
        $driver = new FakeDriver;

        $outcome = smokeSteps($driver)->check()->run(smokeContext($driver, [], "http://127.0.0.1:{$port}/broken"));

        expect($outcome->status)->toBe(RunStepStatus::Failed)->and($outcome->summary)->toContain('HTTP 503');
    });
});
