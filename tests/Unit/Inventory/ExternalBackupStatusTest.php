<?php

declare(strict_types=1);

use App\Domain\Inventory\ExternalBackupPlugin;
use App\Domain\Inventory\ExternalBackupStatus;
use Carbon\CarbonImmutable;

const BACKUP_NOW = '2026-11-06 12:00:00';

function backupPlugin(bool $active = true, ?string $lastBackup = null, string $slug = 'updraftplus', ?bool $exposes = null, ?bool $ok = null): ExternalBackupPlugin
{
    // A plugin with a date is one that exposes it, unless the test says otherwise.
    $exposes ??= $lastBackup !== null;

    return new ExternalBackupPlugin($slug, 'Plugin '.$slug, $active, $exposes, $lastBackup === null ? null : CarbonImmutable::parse($lastBackup, 'UTC'), $ok);
}

/**
 * @param  array<string, mixed>|null  $declaration
 * @param  list<ExternalBackupPlugin>|null  $plugins
 * @return list<string>
 */
function assessBackup(?array $declaration, ?array $plugins, int $maxAge = 7): array
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

it('warns about a stale plugin backup even when an independent backup is declared', function (): void {
    $warnings = assessBackup(['type' => 'plesk'], [backupPlugin(lastBackup: '2026-09-01 00:00:00', ok: true)]);

    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('és de fa');
});

it('warns when plugin is declared but none is active', function (): void {
    expect(assessBackup(['type' => 'plugin'], []))->toHaveCount(1)
        ->and(assessBackup(['type' => 'plugin'], [])[0])->toContain('plugin');
});

it('accepts an active plugin that never exposes a date', function (): void {
    expect(assessBackup(null, [backupPlugin(exposes: false)]))->toBe([])
        ->and(assessBackup(['type' => 'plugin'], [backupPlugin(exposes: false)]))->toBe([]);
});

it('warns when a plugin that exposes its date could not be read, whatever is declared', function (): void {
    $unreadable = backupPlugin(exposes: true);

    expect(assessBackup(null, [$unreadable]))->toBe(["No s'ha pogut llegir la data de l'última còpia de Plugin updraftplus."])
        ->and(assessBackup(['type' => 'plesk'], [$unreadable]))->toHaveCount(1)
        ->and(assessBackup(null, [backupPlugin(active: false, exposes: true)]))->toHaveCount(1); // only the "no active plugin" one
});

it('does not count a failed last backup as recent and warns about it', function (): void {
    $warnings = assessBackup(null, [backupPlugin(lastBackup: '2026-11-06 06:00:00', ok: false)]);

    expect($warnings)->toBe(["L'última còpia de Plugin updraftplus ha fallat: 2026-11-06 06:00 UTC."]);

    // A failed run does not hide an older good one from the age check, nor the other way round.
    $both = assessBackup(null, [backupPlugin(lastBackup: '2026-11-06 06:00:00', ok: false), backupPlugin(lastBackup: '2026-10-01 00:00:00', slug: 'duplicator', ok: true)]);
    expect($both)->toHaveCount(2);
});

it('accepts a recent backup and warns on an old one using the newest date', function (): void {
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-11-01 12:00:00')]))->toBe([]);

    $old = assessBackup(null, [backupPlugin(lastBackup: '2026-10-20 08:30:00'), backupPlugin(lastBackup: '2026-10-10 00:00:00', slug: 'duplicator')]);
    expect($old)->toHaveCount(1)->and($old[0])->toContain('de fa 17 dies i 3 hores')->and($old[0])->toContain('2026-10-20 08:30 UTC');

    // The newest of several wins.
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-10-20 00:00:00'), backupPlugin(lastBackup: '2026-11-05 00:00:00', slug: 'duplicator')]))->toBe([]);
});

it('words the age so that 7 days and 20 hours with a limit of 7 reads correctly', function (): void {
    $warnings = assessBackup(null, [backupPlugin(lastBackup: '2026-10-29 16:00:00')]);

    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('de fa 7 dies i 20 hores (màxim 7 dies)');
});

it('uses the configured threshold exactly', function (): void {
    expect(assessBackup(null, [backupPlugin(lastBackup: '2026-11-04 12:00:00')], 2))->toBe([])
        ->and(assessBackup(null, [backupPlugin(lastBackup: '2026-11-04 11:59:59')], 2))->toHaveCount(1);
});

it('ignores the date of an inactive plugin', function (): void {
    expect(assessBackup(null, [backupPlugin(active: false, lastBackup: '2026-11-05 00:00:00')]))->toHaveCount(1);
});

it('judges only the declaration, and says so, when detection did not run', function (): void {
    expect(assessBackup(['type' => 'dropbox'], null))->toBe([])
        ->and(assessBackup(null, null)[0])->toContain('la detecció de plugins no s\'ha executat')
        ->and(assessBackup(['type' => 'plugin'], null)[0])->toContain('la detecció de plugins no s\'ha executat');
});
