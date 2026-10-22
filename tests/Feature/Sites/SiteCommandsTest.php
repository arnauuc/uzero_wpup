<?php

declare(strict_types=1);

use App\Enums\UpdateStrategy;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\HostingAccount;
use App\Models\HostingServer;
use App\Models\Site;
use App\Models\User;

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function addParams(array $extra = []): array
{
    return $extra + [
        'name' => 'botiga-a',
        '--url' => 'https://botiga-a.test',
        '--wp-path' => '/var/www/botiga-a',
        '--server' => 'srv1',
        '--hostname' => 'srv1.example.test',
        '--account' => 'botiga_a',
        '--no-interaction' => true,
    ];
}

it('creates a site with server, account and profile, and audits it', function (): void {
    command('site:add', addParams([
        '--tag' => ['woo', 'nit'],
        '--strategy' => 'staging_first',
        '--window-days' => '1-5',
        '--window-start' => '02:00',
        '--verify-url' => ['/', '/cart', 'https://botiga-a.test/checkout'],
        '--mask' => ['.banner'],
        '--diff-threshold' => '1.5',
        '--freeze' => ['woocommerce-subscriptions'],
        '--notify' => ['email'],
        '--no-include-uploads' => true,
    ]))->expectsOutputToContain('donat d\'alta')->assertSuccessful();

    $site = Site::query()->where('name', 'botiga-a')->sole();
    expect($site->strategy)->toBe(UpdateStrategy::StagingFirst)
        ->and($site->tags)->toBe(['woo', 'nit'])
        ->and($site->maintenance_window)->toEqual(['days' => [1, 2, 3, 4, 5], 'start' => '02:00', 'end' => '06:00'])
        ->and($site->profile?->verify_urls)->toBe(['/', '/cart', 'https://botiga-a.test/checkout'])
        ->and($site->profile?->diff_threshold)->toBe('1.50')
        ->and(HostingServer::query()->sole()->hostname)->toBe('srv1.example.test')
        ->and(HostingServer::query()->sole()->pinned_host_key)->toBeNull()
        ->and(HostingAccount::query()->sole()->credential_ref)->toBeNull();

    $entry = AuditLogEntry::query()->where('action', 'site.add')->sole();
    expect($entry->actor)->toBe('admin1')->and($entry->object_id)->toBe((string) $site->id);
});

it('reuses an existing server and account', function (): void {
    $server = HostingServer::factory()->create(['name' => 'srv1']);
    HostingAccount::factory()->create(['hosting_server_id' => $server->id, 'system_user' => 'botiga_a']);

    command('site:add', addParams(['--hostname' => '']))->assertSuccessful();
    // hostname given for an existing server is rejected
    command('site:add', addParams(['name' => 'botiga-b']))
        ->expectsOutputToContain('ja existeix')->assertFailed();

    expect(HostingServer::query()->count())->toBe(1)->and(HostingAccount::query()->count())->toBe(1);
});

it('writes nothing in dry-run mode', function (): void {
    command('site:add', addParams(['--dry-run' => true]))
        ->expectsOutputToContain('[dry-run]')->assertSuccessful();

    expect(Site::query()->count())->toBe(0)->and(HostingServer::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'site.add')->exists())->toBeFalse();
});

it('validates fields and reports every error', function (array $params, string $message): void {
    /** @var array<string, mixed> $params */
    command('site:add', addParams($params))->expectsOutputToContain($message)->assertFailed();

    expect(Site::query()->count())->toBe(0);
})->with([
    'bad name' => [['name' => 'Botiga A'], 'nom del lloc'],
    'url scheme' => [['--url' => 'ftp://x.test'], 'http o https'],
    'url credentials' => [['--url' => 'https://user:pass@x.test'], 'sense usuari ni contrasenya'],
    'relative wp path' => [['--wp-path' => 'var/www'], 'camí absolut'],
    'relative php' => [['--php-binary' => 'php8.3'], 'camí absolut'],
    'bad strategy' => [['--strategy' => 'yolo'], 'in_place o staging_first'],
    'bad days' => [['--window-days' => '0,9'], 'format'],
    'bad time' => [['--window-start' => '25:00'], 'HH:MM'],
    'bad threshold' => [['--diff-threshold' => '101'], 'entre 0 i 100'],
    'foreign verify url' => [['--verify-url' => ['https://altre.test/']], 'mateix domini'],
    'bad tag' => [['--tag' => ['Mala Etiqueta']], 'etiqueta'],
    'bad channel' => [['--notify' => ['sms']], 'Canal'],
    'missing url' => [['--url' => ''], '--url'],
]);

it('refuses a duplicate name', function (): void {
    command('site:add', addParams())->assertSuccessful();
    command('site:add', addParams())->expectsOutputToContain('Ja existeix')->assertFailed();
});

it('asks for missing values when interactive', function (): void {
    command('site:add', ['name' => 'botiga-i', '--server' => 'srv9', '--account' => 'u9', '--hostname' => 'h9.test'])
        ->expectsChoice('Estratègia', 'in_place', ['in_place', 'staging_first'])
        ->expectsQuestion('URL del lloc (https://…)', 'https://botiga-i.test')
        ->expectsQuestion('Camí de WordPress al servidor', '/var/www/i')
        ->assertSuccessful();

    expect(Site::query()->where('name', 'botiga-i')->value('url'))->toBe('https://botiga-i.test');
});

it('denies site management to operators', function (): void {
    actingAsOperator('oper1');

    command('site:add', addParams())->expectsOutputToContain('No tens permís')->assertFailed();
    expect(Site::query()->count())->toBe(0);
});

it('edits only the given fields, replaces lists, clears and audits before/after', function (): void {
    command('site:add', addParams(['--tag' => ['woo'], '--mask' => ['.a'], '--php-binary' => '/usr/bin/php8.3']))->assertSuccessful();

    command('site:edit', [
        'name' => 'botiga-a', '--tag' => ['nit', 'b2b'], '--strategy' => 'staging_first',
        '--clear' => ['php-binary'], '--diff-threshold' => '2', '--deactivate' => true, '--no-interaction' => true,
    ])->expectsOutputToContain('actualitzat')->assertSuccessful();

    $site = Site::query()->where('name', 'botiga-a')->sole();
    expect($site->tags)->toBe(['nit', 'b2b'])->and($site->php_binary)->toBeNull()
        ->and($site->active)->toBeFalse()->and($site->strategy)->toBe(UpdateStrategy::StagingFirst)
        ->and($site->profile?->mask_selectors)->toBe(['.a'])
        ->and($site->profile?->diff_threshold)->toBe('2.00')
        ->and($site->url)->toBe('https://botiga-a.test');

    $entry = AuditLogEntry::query()->where('action', 'site.edit')->sole();
    $changes = (array) ($entry->context['changes'] ?? []);
    expect($changes['tags'] ?? null)->toEqual(['from' => ['woo'], 'to' => ['nit', 'b2b']])
        ->and($changes)->not->toHaveKey('mask_selectors');
});

it('keeps unrelated window fields when editing one', function (): void {
    command('site:add', addParams(['--window-days' => '6,7', '--window-start' => '03:00']))->assertSuccessful();
    command('site:edit', ['name' => 'botiga-a', '--window-end' => '05:00', '--no-interaction' => true])->assertSuccessful();

    expect(Site::query()->sole()->maintenance_window)->toEqual(['days' => [6, 7], 'start' => '03:00', 'end' => '05:00']);

    command('site:edit', ['name' => 'botiga-a', '--clear' => ['window'], '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->sole()->maintenance_window)->toBeNull();
});

it('does not write on edit dry-run, no-op or invalid input', function (): void {
    command('site:add', addParams())->assertSuccessful();

    command('site:edit', ['name' => 'botiga-a', '--tag' => ['x'], '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('[dry-run]')->assertSuccessful();
    command('site:edit', ['name' => 'botiga-a', '--url' => 'https://botiga-a.test', '--no-interaction' => true])
        ->expectsOutputToContain('no hi ha canvis')->assertSuccessful();
    command('site:edit', ['name' => 'botiga-a', '--no-interaction' => true])
        ->expectsOutputToContain('cap canvi')->assertFailed();
    command('site:edit', ['name' => 'botiga-a', '--tag' => ['x'], '--clear' => ['tag'], '--no-interaction' => true])
        ->expectsOutputToContain('alhora')->assertFailed();
    command('site:edit', ['name' => 'nope', '--tag' => ['x'], '--no-interaction' => true])
        ->expectsOutputToContain('no existeix')->assertFailed();

    expect(Site::query()->sole()->tags)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'site.edit')->exists())->toBeFalse();
});

it('emits JSON without secrets-like fields', function (): void {
    command('site:add', addParams(['--json' => true]))->expectsOutputToContain('"name": "botiga-a"')->assertSuccessful();
});
