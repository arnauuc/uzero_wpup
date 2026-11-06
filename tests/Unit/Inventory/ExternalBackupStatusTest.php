<?php

declare(strict_types=1);

use App\Domain\Inventory\ExternalBackupPlugin;
use App\Domain\Inventory\ExternalBackupStatus;
use Carbon\CarbonImmutable;

const BACKUP_NOW = '2026-11-06 12:00:00';

function backupPlugin(bool $active = true, ?string $lastBackup = null, string $slug = 'updraftplus'): ExternalBackupPlugin
{
    return new ExternalBackupPlugin($slug, 'Plugin', $active, $lastBackup === null ? null : CarbonImmutable::parse($lastBackup, 'UTC'));
}

/**
 * @param  array<string, mixed>|null  $declaration
 * @param  list<ExternalBackupPlugin>  $plugins
 * @return list<string>
 */
function assessBackup(?array $declaration, array $plugins, int $maxAge = 7): array
{
    return (new ExternalBackupStatus($maxAge))->assess($declaration, $plugins, CarbonImmutable::parse(BACKUP_NOW, 'UTC'))->warnings;
}

it('warns when nothing is declared and no active backup plugin is detected', function (): void {
    expect(assessBackup(null, []))->toHaveCount(1)->and(assessBackup(null, [])[0])->toContain('Sense còpia externa coneguda')
        ->and(assessBackup(null, [backupPlugin(active: false)]))->toHaveCount(1);
});

it('warns when the site is declared without external backup', function (): void {
    expect(assessBackup(['type' => 'none'], []))->toHaveCount(1)
        ->and(assessBackup(['type' => 'none'], [])[0])->toContain('sense còpia externa');
});

it('accepts an independent declared backup without any plugin', function (string $type): void {
    expect(assessBackup(['type' => $type, 'notes' => 'diària'], []))->toBe([]);
})->with(['plesk', 'dropbox', 'other']);

it('does not let a stale plugin warn when an independent backup is declared', function (): void {
    expect(assessBackup(['type' => 'plesk'], [backupPlugin(lastBackup: '2026-09-01 00:00:00')]))->toBe([]);
});

it('warns when plugin is declared but none is active', function (): void {
    expect(assessBackup(['type' => 'plugin'], []))->toHaveCount(1)
        ->and(assessBackup(['type' => 'plugin'], [])[0])->toContain('plugin');
});

it('accepts an active plugin without a known date', function (): void {
    expect(assessBackup(null, [backupPlugin()]))->toBe([])
        ->and(assessBackup(['type' => 'plugin'], [backupPlugin()]))->toBe([]);
});

it('accepts a recent backup and warns on an old one using the newest date', function (): void {
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-11-01 12:00:00')]))->toBe([]);

    $old = assessBackup(null, [backupPlugin(lastBackup: '2026-10-20 08:30:00'), backupPlugin(lastBackup: '2026-10-10 00:00:00', slug: 'duplicator')]);
    expect($old)->toHaveCount(1)->and($old[0])->toContain('17 dies')->and($old[0])->toContain('2026-10-20 08:30 UTC');

    // The newest of several wins.
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-10-20 00:00:00'), backupPlugin(lastBackup: '2026-11-05 00:00:00', slug: 'duplicator')]))->toBe([]);
});

it('uses the configured threshold exactly', function (): void {
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-11-04 12:00:00')], 2))->toBe([])
        ->and(assessBackup(null, [backupPlugin(lastBackup: '2026-11-04 11:59:59')], 2))->toHaveCount(1);
});

it('ignores the date of an inactive plugin', function (): void {
    expect(assessBackup(null, [backupPlugin(active: false, lastBackup: '2026-11-05 00:00:00')]))->toHaveCount(1);
});
