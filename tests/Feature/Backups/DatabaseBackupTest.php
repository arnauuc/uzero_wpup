<?php

declare(strict_types=1);

use App\Domain\Backups\BackupFailed;
use App\Domain\Backups\DatabaseBackup;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Secrets\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeDriver;

/*
 * Database part of the restore point (UZEROWPUP-38) against the FakeDriver.
 */

it('dumps the database and validates that every table is in the dump', function (): void {
    $driver = new FakeDriver;

    $result = app(DatabaseBackup::class)->run($driver, '42');

    expect($result->file->path)->toMatch('/^db-42-[0-9a-f]{8}\.sql\.gz$/')
        ->and($result->tables)->toBe(['wp_options', 'wp_posts', 'wp_postmeta'])
        ->and($driver->files)->toHaveKey($result->file->path);
});

it('removes the dump and names the missing tables when the dump lacks some', function (): void {
    $driver = new FakeDriver;
    $driver->dumpOmits = ['wp_posts', 'wp_postmeta'];

    $failure = null;
    try {
        app(DatabaseBackup::class)->run($driver, '42');
    } catch (BackupFailed $e) {
        $failure = $e->getMessage();
    }

    expect($failure)->toBeString()
        ->and($failure)->toContain('wp_posts', 'wp_postmeta')
        ->and($failure)->not->toContain('wp_options')
        ->and($failure)->toContain('S\'ha esborrat el volcat')
        ->and($driver->files)->toBe([]);
});

it('fails when the site has no tables, without dumping anything', function (): void {
    $driver = new FakeDriver;
    $driver->dbTables = [];

    expect(fn () => app(DatabaseBackup::class)->run($driver, '42'))->toThrow(BackupFailed::class, 'cap taula')
        ->and($driver->writeCalls)->toBe([]);
});

it('does not dump when the tables cannot be listed', function (): void {
    $driver = new FakeDriver;
    $driver->tablesFail = true;

    expect(fn () => app(DatabaseBackup::class)->run($driver, '42'))->toThrow(DriverException::class)
        ->and($driver->writeCalls)->toBe([]);
});

it('refuses a label that would make an invalid remote file name', function (string $label): void {
    $driver = new FakeDriver;

    expect(fn () => app(DatabaseBackup::class)->run($driver, $label))->toThrow(BackupFailed::class, 'no és vàlid')
        ->and($driver->writeCalls)->toBe([]);
})->with(['../x', 'a b', 'a;rm', '']);

it('keeps the original error, says the dump stays, and redacts the log when the rejected dump cannot be removed', function (): void {
    app(SecretRedactor::class)->register('s3cr3t-db-password');
    $driver = new class extends FakeDriver
    {
        public function remove(string $remotePath): void
        {
            throw new DriverException('no es pot esborrar amb s3cr3t-db-password');
        }
    };
    $driver->dumpOmits = ['wp_posts'];
    Log::shouldReceive('warning')->once()->withArgs(
        static fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), 's3cr3t-db-password'),
    );

    expect(fn () => app(DatabaseBackup::class)->run($driver, '42'))
        ->toThrow(BackupFailed::class, 'No s\'ha pogut esborrar el volcat');
});
