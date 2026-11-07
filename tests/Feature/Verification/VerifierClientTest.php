<?php

declare(strict_types=1);

use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Verification\VerificationRequest;
use App\Domain\Verification\VerifierClient;
use App\Domain\Verification\VerifierFailed;
use App\Domain\Verification\VerifierSettings;
use App\Domain\Verification\VerifyStep;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\Site;

const FAKE_VERIFIER_SECRET = 'S3cr3t-Value-For-Redaction-0123456789';

beforeEach(function (): void {
    $base = sys_get_temp_dir().'/wpup-verifier-'.bin2hex(random_bytes(6));
    config([
        'wpup.verifier.node_binary' => PHP_BINARY,
        'wpup.verifier.script' => base_path('tests/Support/fake-verifier.php'),
        'wpup.verifier.work_dir' => $base,
        'wpup.verifier.total_ms' => 1000,
        'wpup.verifier.navigation_timeout_ms' => 500,
        'wpup.verifier.screenshot_timeout_ms' => 500,
        'wpup.verifier.timeout_seconds' => 3,
        'wpup.verifier.stop_grace_seconds' => 1,
        'wpup.verifier.step_timeout_seconds' => 10,
        'wpup.verifier.max_result_bytes' => 4096,
        'wpup.verifier.max_stderr_bytes' => 4096,
        'wpup.verifier.urls' => ['/' => 'home', '/broken' => 'checkout'],
    ]);
});

afterEach(function (): void {
    $dir = config('wpup.verifier.work_dir');
    if (is_string($dir)) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

function verifierRequest(string $siteName): VerificationRequest
{
    return VerificationRequest::withDefaults(42, $siteName, 'https://botiga.test', app(VerifierSettings::class));
}

function verifierOutputDir(): string
{
    return app(VerifierSettings::class)->workDir.'/run-42/test';
}

function processAlive(int $pid): bool
{
    // A zombie (no init in the container to reap it) is dead too.
    $status = @file_get_contents("/proc/{$pid}/status");

    return is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1;
}

it('sends protocol v1 on stdin and reads every page and viewport back', function (): void {
    $result = app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir());

    expect($result->pages)->toHaveCount(4)
        ->and($result->partial)->toBeFalse()
        ->and($result->pages[0]->viewport)->toBe('desktop')
        ->and($result->pages[0]->screenshot)->toBe('desktop/0.png')
        ->and($result->pages[0]->resourceErrors[0]->status)->toBe(404)
        ->and($result->pages[1]->blockedRequests)->toBe(['https://botiga.test/?wc-ajax=checkout'])
        ->and($result->httpErrors())->toBe(2)
        ->and($result->consoleErrors())->toBe(4)
        ->and(fileperms(verifierOutputDir()) & 0777)->toBe(0700);
});

it('never passes the database, the keys or any secret to the verifier', function (): void {
    putenv('DB_PASSWORD=leaked-db');
    $_ENV['WPUP_DEV_KEK'] = 'leaked-kek';
    $_SERVER['APP_KEY'] = 'leaked-app-key';
    try {
        app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir());
    } finally {
        putenv('DB_PASSWORD');
        unset($_ENV['WPUP_DEV_KEK'], $_SERVER['APP_KEY']);
    }

    $env = json_decode((string) file_get_contents(verifierOutputDir().'/env.json'), true);
    expect($env)->toBeArray();
    /** @var array<string, string> $env */
    expect(array_keys($env))->each->toBeIn(['PATH', 'HOME', 'LANG', 'PLAYWRIGHT_BROWSERS_PATH'])
        ->and(json_encode($env))->not->toContain('leaked');
});

it('fails with a structured, redacted error when the verifier rejects its input or crashes', function (string $site, string $message): void {
    expect(fn () => app(VerifierClient::class)->verify(verifierRequest($site), verifierOutputDir()))
        ->toThrow(VerifierFailed::class, $message);
})->with([
    'invalid input' => ['invalid', "El verificador ha rebutjat l'entrada: input does not match protocol version 1 (urls.0.path: invalid_format)."],
    'crash, stderr never read' => ['crash', 'El verificador ha fallat (codi 1): browser crashed.'],
    'no result' => ['silent', 'no ha escrit result.json'],
    'malformed result' => ['garbage', 'no té la forma esperada'],
    'oversized result' => ['huge', 'supera 4096 bytes'],
    'too much stderr' => ['chatty', 'més de 4096 bytes a stderr'],
]);

it('redacts the error before cutting it, so no piece of a secret survives the cut', function (): void {
    app(SecretRedactor::class)->register(FAKE_VERIFIER_SECRET);

    try {
        app(VerifierClient::class)->verify(verifierRequest('leaky'), verifierOutputDir());
        throw new LogicException('The verifier should have failed.');
    } catch (VerifierFailed $e) {
        expect($e->getMessage())->not->toContain('S3cr3t');
    }
});

it('stops the whole process group at the hard timeout, leaving no orphan behind', function (): void {
    $started = microtime(true);
    expect(fn () => app(VerifierClient::class)->verify(verifierRequest('hang-child'), verifierOutputDir()))
        ->toThrow(VerifierFailed::class, "El verificador no ha acabat en 3 s i s'ha aturat.")
        ->and(microtime(true) - $started)->toBeLessThan(8);

    $child = (int) file_get_contents(verifierOutputDir().'/child.pid');
    expect($child)->toBeGreaterThan(0);
    usleep(200_000);
    expect(processAlive($child))->toBeFalse();
});

it('says clearly that the verifier is not installed instead of failing with 127', function (): void {
    config(['wpup.verifier.script' => base_path('verifier/missing/bin.js')]);
    expect(fn () => app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir()))
        ->toThrow(VerifierFailed::class, 'falta missing/bin.js (cal `npm ci && npm run build` a verifier/)');

    config(['wpup.verifier.node_binary' => 'no-such-node']);
    expect(fn () => app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir()))
        ->toThrow(VerifierFailed::class, 'no es troba Node');
});

it('keeps the reason when the output directory cannot be created', function (): void {
    $blocker = app(VerifierSettings::class)->workDir;
    @mkdir(dirname($blocker), 0700, true);
    file_put_contents($blocker, 'not a directory');

    try {
        app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir());
        throw new LogicException('The verifier should have failed.');
    } catch (VerifierFailed $e) {
        expect($e->getMessage())->toContain("No s'ha pogut preparar el directori de sortida del verificador: No s'ha pogut crear")
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    } finally {
        unlink($blocker);
    }
});

it('records the verification as a step, with its coverage, and fails when the verifier fails', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['name' => 'botiga', 'url' => 'https://botiga.test'])]);
    $step = new VerifyStep('verify:baseline', app(VerifierClient::class), app(VerifierSettings::class));

    $outcome = $step->run(new StepContext($run, false));
    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toBe('4 pàgina(es) verificada(es): 2 amb error HTTP, 4 error(s) de consola.')
        ->and($outcome->data)->toMatchArray(['expected' => 4, 'captured' => 4, 'partial' => false])
        ->and($outcome->data['coverage'])->toContain(['path' => '/broken', 'viewport' => 'mobile', 'captured' => true])
        ->and($outcome->data['output_dir'])->toBe(app(VerifierSettings::class)->workDir."/run-{$run->id}/verify-baseline");

    $run->site?->update(['name' => 'crash']);
    expect($step->run(new StepContext($run->refresh(), false))->status)->toBe(RunStepStatus::Failed);
});

it('fails the step when pages are missing: a blind baseline is no baseline', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['name' => 'partial', 'url' => 'https://botiga.test'])]);
    $outcome = (new VerifyStep('verify:baseline', app(VerifierClient::class), app(VerifierSettings::class)))->run(new StepContext($run, false));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toStartWith('Verificació incompleta: 1 de 4 captures; falten /broken (desktop), / (mobile), /broken (mobile); resultat parcial.')
        ->and($outcome->data)->toMatchArray(['expected' => 4, 'captured' => 1, 'partial' => true])
        ->and($outcome->data['coverage'])->toContain(['path' => '/', 'viewport' => 'mobile', 'captured' => false]);
});
