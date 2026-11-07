<?php

declare(strict_types=1);

use App\Domain\Verification\VerificationRequest;
use App\Domain\Verification\VerifierSettings;

it('sends the hide selectors and the capture limits from the config to the verifier input', function (): void {
    config([
        'wpup.verifier.hide_selectors' => ['.promo-popup', '#newsletter'],
        'wpup.verifier.prepare_ms' => 20_000,
        'wpup.verifier.max_page_height' => 15_000,
    ]);

    $document = VerificationRequest::withDefaults(7, 'botiga', 'https://botiga.test', app(VerifierSettings::class))->toProtocol('/var/lib/wpup/out');

    expect($document['version'])->toBe(1)
        ->and($document['hide_selectors'])->toBe(['.promo-popup', '#newsletter'])
        ->and($document['capture'])->toBe(['prepare_ms' => 20_000, 'max_height' => 15_000]);
});

it('leaves the optional fields out when there is nothing to hide', function (): void {
    config(['wpup.verifier.hide_selectors' => []]);

    $document = (new VerificationRequest(7, 'botiga', 'https://botiga.test', [], [], [], 0.01, 1000, 1000, 5000))->toProtocol('/var/lib/wpup/out');

    expect($document)->not->toHaveKeys(['hide_selectors', 'capture']);
});

it('has defaults for the capture in the config', function (): void {
    expect(config('wpup.verifier.hide_selectors'))->toBe([])
        ->and(config('wpup.verifier.prepare_ms'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('wpup.verifier.max_page_height'))->toBeInt()->toBeGreaterThan(0);
});
