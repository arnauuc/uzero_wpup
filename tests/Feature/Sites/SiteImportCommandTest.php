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

/** Writes $content to a temporary file with $extension and returns its path. */
function importFile(string $content, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpup-import-');
    if ($path === false) {
        throw new RuntimeException('No temp file.');
    }
    rename($path, $path .= ".{$extension}");
    file_put_contents($path, $content);

    return $path;
}

function exampleFile(string $name): string
{
    return dirname(__DIR__, 3).'/docs/examples/'.$name;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function importParams(string $file, array $extra = []): array
{
    return ['file' => $file, '--no-interaction' => true] + $extra;
}

function importAuditCount(): int
{
    return AuditLogEntry::query()->whereIn('action', ['site.add', 'site.edit', 'site.import'])->count();
}

it('imports the documented examples, YAML and CSV alike', function (string $file): void {
    command('site:import', importParams(exampleFile($file)))->expectsOutputToContain('Importació acabada')->assertSuccessful();

    $botiga = Site::query()->where('name', 'botiga-exemple')->sole();
    expect(Site::query()->count())->toBe(3)
        ->and($botiga->strategy)->toBe(UpdateStrategy::StagingFirst)
        ->and($botiga->tags)->toBe(['woo', 'nit'])
        ->and($botiga->auto_update)->toBeTrue()
        ->and($botiga->maintenance_window)->toEqual(['days' => [1, 2, 3, 4, 5], 'start' => '01:00', 'end' => '05:00'])
        ->and($botiga->profile?->verify_urls)->toBe(['/', '/cart', '/shop'])
        ->and($botiga->profile?->diff_threshold)->toBe('1.50')
        ->and(HostingServer::query()->count())->toBe(2)
        ->and(HostingAccount::query()->count())->toBe(3)
        ->and(HostingServer::query()->whereNotNull('pinned_host_key')->exists())->toBeFalse()
        ->and(HostingAccount::query()->whereNotNull('credential_ref')->exists())->toBeFalse()
        ->and(Site::query()->where('name', 'web-agent-exemple')->sole()->hostingAccount?->driver->value)->toBe('agent');

    $summary = AuditLogEntry::query()->where('action', 'site.import')->sole();
    expect($summary->context['created'] ?? null)->toBe(3)
        ->and(AuditLogEntry::query()->where('action', 'site.add')->count())->toBe(3);
})->with(['llocs.yaml', 'llocs.csv']);

it('writes nothing in dry-run mode and lists the actions', function (): void {
    command('site:import', importParams(exampleFile('llocs.yaml'), ['--dry-run' => true]))
        ->expectsOutputToContain('[dry-run]')->assertSuccessful();

    expect(Site::query()->count())->toBe(0)->and(HostingServer::query()->count())->toBe(0)->and(importAuditCount())->toBe(0);
});

it('emits JSON with the action of every site', function (): void {
    command('site:import', importParams(exampleFile('llocs.yaml'), ['--dry-run' => true, '--json' => true]))
        ->expectsOutputToContain('"action": "create"')->assertSuccessful();
});

it('writes nothing and lists every error when one row is invalid', function (): void {
    $csv = <<<'CSV'
    name,url,wp_path,server,hostname,account
    uno,https://uno.example.test,/var/www/uno,srv,srv.example.test,uno
    dos,https://dos.example.test,/var/www/dos,srv,,dos
    tres,ftp://tres.example.test,var/www/tres,srv,,tres
    uno,https://uno2.example.test,/var/www/uno2,srv,,uno2
    CSV;

    command('site:import', importParams(importFile($csv, 'csv')))
        ->expectsOutputToContain('Lloc 3 («tres»): La URL ha de ser http o https')
        ->expectsOutputToContain('Lloc 3 («tres»): --wp-path ha de ser un camí absolut')
        ->expectsOutputToContain('Lloc 4 («uno»): el nom ja apareix al lloc 1')
        ->expectsOutputToContain('No s\'ha escrit res.')
        ->assertFailed();

    expect(Site::query()->count())->toBe(0)->and(HostingServer::query()->count())->toBe(0)->and(importAuditCount())->toBe(0);
});

it('rejects inconsistent server definitions within the file', function (): void {
    $yaml = <<<'YAML'
    - { name: a, url: 'https://a.example.test', wp_path: /a, server: { name: srv, hostname: h1.example.test }, account: { system_user: a } }
    - { name: b, url: 'https://b.example.test', wp_path: /b, server: { name: srv, hostname: h2.example.test }, account: { system_user: b } }
    YAML;

    command('site:import', importParams(importFile($yaml, 'yaml')))
        ->expectsOutputToContain('Lloc 2 («b»): el servidor «srv» ja està definit')->assertFailed();
    expect(Site::query()->count())->toBe(0);
});

it('is idempotent: a re-import changes and audits nothing', function (): void {
    command('site:import', importParams(exampleFile('llocs.yaml')))->assertSuccessful();
    $audit = importAuditCount();

    command('site:import', importParams(exampleFile('llocs.yaml')))->expectsOutputToContain('sense canvis')->assertSuccessful();
    command('site:import', importParams(exampleFile('llocs.csv')))->expectsOutputToContain('sense canvis')->assertSuccessful();

    expect(Site::query()->count())->toBe(3)->and(HostingServer::query()->count())->toBe(2)->and(importAuditCount())->toBe($audit);
});

it('updates existing sites on re-import and leaves absent fields alone', function (): void {
    command('site:import', importParams(exampleFile('llocs.yaml')))->assertSuccessful();

    $yaml = <<<'YAML'
    - name: botiga-exemple
      tags: [b2b]
      mask: []
      window: { end: "04:00" }
    - name: blog-exemple
      strategy: staging_first
    YAML;
    command('site:import', importParams(importFile($yaml, 'yaml')))->expectsOutputToContain('2 actualitzats')->assertSuccessful();

    $botiga = Site::query()->where('name', 'botiga-exemple')->sole();
    expect($botiga->tags)->toBe(['b2b'])
        ->and($botiga->profile?->mask_selectors)->toBe([])
        ->and($botiga->maintenance_window)->toEqual(['days' => [1, 2, 3, 4, 5], 'start' => '01:00', 'end' => '04:00'])
        ->and($botiga->profile?->verify_urls)->toBe(['/', '/cart', '/shop'])
        ->and($botiga->url)->toBe('https://botiga-exemple.example.test')
        ->and(Site::query()->where('name', 'blog-exemple')->sole()->strategy)->toBe(UpdateStrategy::StagingFirst)
        ->and(Site::query()->count())->toBe(3)
        ->and(AuditLogEntry::query()->where('action', 'site.edit')->count())->toBe(2);
});

it('rejects secret-looking and unknown columns or keys', function (string $content, string $extension, string $message): void {
    command('site:import', importParams(importFile($content, $extension)))
        ->expectsOutputToContain($message)->assertFailed();

    expect(Site::query()->count())->toBe(0);
})->with([
    'csv password' => ["name,url,wp_path,server,account,password\nx,https://x.example.test,/x,s,x,hunter2\n", 'csv', 'sembla una credencial'],
    'csv key' => ["name,pinned_host_key\nx,SHA256:abc\n", 'csv', 'sembla una credencial'],
    'yaml token' => ["- name: x\n  api_token: abc\n", 'yaml', 'sembla una credencial'],
    'yaml nested secret' => ["- name: x\n  account: { system_user: x, private_key: abc }\n", 'yaml', 'sembla una credencial'],
    'unknown column' => ["name,color\nx,blau\n", 'csv', 'no és coneguda'],
    'unknown yaml key' => ["- name: x\n  colour: blau\n", 'yaml', 'no és coneguda'],
]);

it('never echoes the value of a rejected secret', function (): void {
    command('site:import', importParams(importFile("name,password\nx,hunter2-secret\n", 'csv')))
        ->doesntExpectOutputToContain('hunter2-secret')->assertFailed();
});

it('refuses unreadable files and unknown formats', function (): void {
    command('site:import', importParams('/nope/llocs.csv'))->expectsOutputToContain('No es pot llegir')->assertFailed();
    command('site:import', importParams(importFile('x', 'txt')))->expectsOutputToContain('--format')->assertFailed();
});

it('denies the import to operators', function (): void {
    actingAsOperator('oper1');

    command('site:import', importParams(exampleFile('llocs.yaml')))->expectsOutputToContain('No tens permís')->assertFailed();
    expect(Site::query()->count())->toBe(0);
});
