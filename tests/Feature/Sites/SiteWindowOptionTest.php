<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Site;
use App\Models\User;

/*
 * UZEROWPUP-129: the whole window in one option and the automatic updates toggle.
 */

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    actingAsOperator('admin1');
    command('site:add', [
        'name' => 'botiga-w', '--url' => 'https://botiga-w.test', '--wp-path' => '/var/www/botiga-w',
        '--server' => 'srv1', '--hostname' => 'srv1.example.test', '--account' => 'botiga_w', '--no-interaction' => true,
    ])->assertSuccessful();
});

it('sets a day window from «dl-dv 10:00-13:00» and a night one that crosses midnight', function (): void {
    command('site:edit', ['name' => 'botiga-w', '--window' => 'dl-dv 10:00-13:00', '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->maintenance_window)->toEqual(['days' => [1, 2, 3, 4, 5], 'start' => '10:00', 'end' => '13:00']);

    command('site:edit', ['name' => 'botiga-w', '--window' => 'ds,dg 23:00–02:00', '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->maintenance_window)->toEqual(['days' => [6, 7], 'start' => '23:00', 'end' => '02:00']);

    command('site:edit', ['name' => 'botiga-w', '--window' => 'cada dia 01:00-06:00', '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->maintenance_window)->toEqual(['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00']);
});

it('wraps a range of days past Sunday', function (): void {
    command('site:edit', ['name' => 'botiga-w', '--window' => 'dv-dl 22:00-06:00', '--no-interaction' => true])->assertSuccessful();

    expect(Site::query()->sole()->maintenance_window)->toEqual(['days' => [1, 5, 6, 7], 'start' => '22:00', 'end' => '06:00']);
});

it('refuses a malformed window with the same rules as the separate options', function (array $params, string $message): void {
    /** @var array<string, mixed> $params */
    command('site:edit', ['name' => 'botiga-w', '--no-interaction' => true] + $params)
        ->expectsOutputToContain($message)->assertFailed();

    expect(Site::query()->sole()->maintenance_window)->toBeNull();
})->with([
    'no times' => [['--window' => 'dl-dv'], 'dies HH:MM-HH:MM'],
    'bad day' => [['--window' => 'dl-dx 10:00-13:00'], 'dl-dv,dg'],
    'bad time' => [['--window' => 'dl 25:00-26:00'], 'HH:MM'],
    'too short' => [['--window' => 'dl 10:00-10:05'], 'com a mínim 15 minuts'],
    'mixed with the separate options' => [['--window' => 'dl 10:00-13:00', '--window-start' => '09:00'], 'no es pot combinar'],
]);

it('turns the automatic updates on and off, audited, and keeps them off by default', function (): void {
    expect(Site::query()->sole()->auto_update)->toBeFalse();

    command('site:edit', ['name' => 'botiga-w', '--auto-update' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->auto_update)->toBeTrue();
    $changes = (array) (AuditLogEntry::query()->where('action', 'site.edit')->sole()->context['changes'] ?? []);
    expect($changes['auto_update'] ?? null)->toEqual(['from' => false, 'to' => true]);

    command('site:edit', ['name' => 'botiga-w', '--no-auto-update' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->auto_update)->toBeFalse();
});
