<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Support\Logging\RedactSecretsTap;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

const REMOTE_PASSWORD = 'remote-db-Pa55w0rd!';

beforeEach(function (): void {
    app(SecretRedactor::class)->reset();
});

/**
 * Logs through the "single" channel into a temporary file and returns its content.
 */
function captureSingleChannel(Closure $log): string
{
    $file = tempnam(sys_get_temp_dir(), 'wpup-log-');
    config(['logging.channels.single.path' => $file]);
    Log::forgetChannel('single');

    try {
        $log(Log::channel('single'));

        return (string) file_get_contents($file);
    } finally {
        Log::forgetChannel('single');
        @unlink((string) $file);
    }
}

it('registers revealed secrets so later logs are masked', function (): void {
    $store = app(SecretStore::class);
    $id = $store->put('hosting_account.db_password', new Secret(REMOTE_PASSWORD), AuditActor::system('test'));
    $store->reveal($id, AuditActor::system('test'), 'test');

    $log = captureSingleChannel(function (LoggerInterface $logger): void {
        $logger->info('mysqldump failed with '.REMOTE_PASSWORD, ['stderr' => 'access denied for '.REMOTE_PASSWORD]);
    });

    expect($log)->toContain('mysqldump failed with')
        ->and($log)->toContain(SecretRedactor::MASK)
        ->and(str_contains($log, REMOTE_PASSWORD))->toBeFalse();
});

it('masks secrets inside logged exceptions', function (): void {
    app(SecretRedactor::class)->register(REMOTE_PASSWORD);

    $log = captureSingleChannel(function (LoggerInterface $logger): void {
        $logger->error('remote command failed', ['exception' => new RuntimeException('ssh: '.REMOTE_PASSWORD)]);
    });

    expect($log)->toContain('RuntimeException')
        ->and(str_contains($log, REMOTE_PASSWORD))->toBeFalse();
});

it('masks secret shapes in logs even if never registered', function (): void {
    $log = captureSingleChannel(function (LoggerInterface $logger): void {
        $logger->warning('dsn mysql://wp:unregistered99@db/wp');
    });

    expect($log)->not->toContain('unregistered99');
});

it('redacts the audit log context', function (): void {
    app(SecretRedactor::class)->register(REMOTE_PASSWORD);

    $entry = app(AuditLogger::class)->record(
        AuditActor::system('test'),
        'site.test',
        AuditResult::Failure,
        context: ['stderr' => 'denied: '.REMOTE_PASSWORD],
    );

    expect(json_encode($entry->fresh()?->context, JSON_THROW_ON_ERROR))->not->toContain(REMOTE_PASSWORD);
});

it('installs the redaction tap on every log channel', function (): void {
    $channels = config('logging.channels');
    expect($channels)->toBeArray();

    foreach ((array) $channels as $channel) {
        $taps = is_array($channel) && is_array($channel['tap'] ?? null) ? $channel['tap'] : [];
        expect($taps)->toContain(RedactSecretsTap::class);
    }
});
