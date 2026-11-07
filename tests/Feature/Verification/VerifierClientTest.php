<?php

declare(strict_types=1);

use App\Domain\Runs\StepContext;
use App\Domain\Verification\VerificationRequest;
use App\Domain\Verification\VerifierClient;
use App\Domain\Verification\VerifierFailed;
use App\Domain\Verification\VerifierSettings;
use App\Domain\Verification\VerifyStep;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\Site;

beforeEach(function (): void {
    $base = sys_get_temp_dir().'/wpup-verifier-'.bin2hex(random_bytes(6));
    config([
        'wpup.verifier.command' => [PHP_BINARY, base_path('tests/Support/fake-verifier.php')],
        'wpup.verifier.work_dir' => $base,
        'wpup.verifier.timeout_seconds' => 3,
        'wpup.verifier.step_timeout_seconds' => 10,
        'wpup.verifier.total_ms' => 2000,
        'wpup.verifier.max_result_bytes' => 4096,
        'wpup.verifier.urls' => ['/' => 'home', '/broken' => 'page'],
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

it('sends protocol v1 on stdin and reads every page and viewport back', function (): void {
    $result = app(VerifierClient::class)->verify(verifierRequest('botiga'), verifierOutputDir());

    expect($result->pages)->toHaveCount(4)
        ->and($result->pages[0]->viewport)->toBe('desktop')
        ->and($result->pages[0]->screenshot)->toBe('desktop/0.png')
        ->and($result->pages[0]->resourceErrors[0]->status)->toBe(404)
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
]);

it('kills the verifier at the hard timeout', function (): void {
    $started = microtime(true);
    expect(fn () => app(VerifierClient::class)->verify(verifierRequest('slow'), verifierOutputDir()))
        ->toThrow(VerifierFailed::class, 'El verificador no ha acabat en 3 s.')
        ->and(microtime(true) - $started)->toBeLessThan(10);
});

it('records the verification as a step, and fails the step only when the verifier fails', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory(['name' => 'botiga', 'url' => 'https://botiga.test'])]);
    $step = new VerifyStep('verify:baseline', app(VerifierClient::class), app(VerifierSettings::class));

    $outcome = $step->run(new StepContext($run, false));
    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toBe('4 pàgina(es) verificada(es): 2 amb error HTTP, 4 error(s) de consola.')
        ->and($outcome->data['output_dir'])->toBe(app(VerifierSettings::class)->workDir."/run-{$run->id}/verify-baseline");

    $run->site?->update(['name' => 'crash']);
    expect($step->run(new StepContext($run->refresh(), false))->status)->toBe(RunStepStatus::Failed);
});
