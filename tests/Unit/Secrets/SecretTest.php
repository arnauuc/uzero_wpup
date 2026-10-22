<?php

declare(strict_types=1);

use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekDecoder;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

const PLAIN = 'hunter2-plain-secret-value';

it('reveals the original value', function (): void {
    expect((new Secret(PLAIN))->reveal())->toBe(PLAIN);
});

it('masks every accidental representation', function (): void {
    $secret = new Secret(PLAIN);

    $representations = [
        (string) $secret,
        json_encode(['password' => $secret], JSON_THROW_ON_ERROR),
        print_r($secret, true),
        var_export($secret, true),
        serialize_attempt($secret),
        (function () use ($secret): string {
            ob_start();
            var_dump($secret);

            return (string) ob_get_clean();
        })(),
        var_export((array) $secret, true),
    ];

    foreach ($representations as $representation) {
        expect($representation)->not->toContain(PLAIN);
    }
    expect((string) $secret)->toBe(Secret::MASK);
});

function serialize_attempt(Secret $secret): string
{
    try {
        return serialize($secret);
    } catch (LogicException) {
        return 'refused';
    }
}

it('refuses to be serialized or cloned', function (): void {
    expect(fn () => serialize(new Secret(PLAIN)))->toThrow(LogicException::class)
        ->and(fn () => clone new Secret(PLAIN))->toThrow(LogicException::class);
});

it('cannot be revealed after wiping', function (): void {
    $secret = new Secret(PLAIN);
    $secret->wipe();

    $secret->reveal();
})->throws(LogicException::class);

it('handles the empty string', function (): void {
    expect((new Secret(''))->reveal())->toBe('');
});

it('rejects a KEK that is not 32 bytes of base64', function (string $encoded): void {
    KekDecoder::decode($encoded);
})->with([
    'not base64' => ['***'],
    'too short' => [base64_encode(random_bytes(16))],
])->throws(SecretStoreException::class);

it('refuses the env KEK provider in production', function (): void {
    new EnvKekProvider(base64_encode(random_bytes(32)), 'production');
})->throws(SecretStoreException::class, 'development and tests only');
