<?php

declare(strict_types=1);

use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepOutcome;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedReadOnlyStep;

/**
 * @param  array<string, mixed>  $arguments
 * @return array{version: int, sites: list<array{site: string, core: ?string, woocommerce: ?string, pending_updates: ?int, unlicensed: ?int, blocked: bool, run: array{status: string}, php: array{version: ?string, source: ?string}, state: array{maintenance: bool, wc_db_update_needed: bool, outdated_templates: int}}>}
 */
function inventoryJson(array $arguments): array
{
    Artisan::call('inventory', $arguments + ['--json' => true]);

    /** @var array{version: int, sites: list<array{site: string, core: ?string, woocommerce: ?string, pending_updates: ?int, unlicensed: ?int, blocked: bool, run: array{status: string}, php: array{version: ?string, source: ?string}, state: array{maintenance: bool, wc_db_update_needed: bool, outdated_templates: int}}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $json;
}

function csvPath(?string $set = null): string
{
    static $path = '';
    $path = $set ?? $path;
    assert(is_string($path));

    return $path;
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    Site::factory()->create(['name' => 'botiga-a', 'url' => 'https://a.test', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'url' => 'https://blog.test', 'tags' => ['nit']]);
    Site::factory()->create(['name' => 'antic', 'tags' => ['woo'], 'active' => false]);

    csvPath(sys_get_temp_dir().'/wpup-inv-'.bin2hex(random_bytes(6)).'.csv');

    // The pipeline stores a snapshot like InventoryStep does, with data per site.
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (Run $run): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('inventory', static function ($context) use ($run): StepOutcome {
            $site = $run->site()->firstOrFail();
            if ($site->name === 'blog') {
                return StepOutcome::failed('No s\'ha pogut fer l\'inventari: connexió caiguda');
            }
            SiteSnapshot::factory()->create([
                'site_id' => $site->id,
                'run_id' => $run->id,
                'core_version' => '6.8.3',
                'php_version' => '8.2.20',
                'plugins' => [
                    ['slug' => 'woocommerce', 'version' => '9.8.1', 'active' => true, 'title' => null, 'type' => 'woocommerce'],
                    ['slug' => 'akismet', 'version' => '5.0', 'active' => true, 'title' => null, 'type' => 'plugin'],
                ],
                'pending_updates' => [
                    ['type' => 'plugin', 'slug' => 'akismet', 'licensed' => true],
                    ['type' => 'plugin', 'slug' => 'premium', 'licensed' => false],
                ],
                'environment' => [
                    'php' => ['warnings' => ['avís 1']],
                    'state' => ['maintenance' => true, 'woocommerce' => ['db_update_needed' => true, 'overridden_templates' => [['file' => 'a', 'outdated' => true], ['file' => 'b', 'outdated' => false]]]],
                    'prerequisites' => [['name' => 'php_minimum', 'passed' => false, 'reason' => 'PHP massa antic', 'value' => '7.2']],
                ],
            ]);

            return StepOutcome::succeeded('ok');
        }),
    ]));
});

afterEach(function (): void {
    if (file_exists(csvPath())) {
        unlink(csvPath());
    }
});

it('queues one inventory run per selected site and only prints the ids', function (): void {
    command('inventory', ['--tag' => ['woo']])
        ->expectsOutputToContain('encuada (inventory, botiga-a)')
        ->assertSuccessful();

    expect(Run::query()->where('type', RunType::Inventory)->count())->toBe(1);
});

it('selects by name and by --all, skipping inactive sites', function (): void {
    command('inventory', ['sites' => ['blog']])->expectsOutputToContain('(inventory, blog)')->assertSuccessful();
    command('inventory', ['--all' => true])->assertSuccessful();

    expect(Run::query()->whereHas('site', fn ($q) => $q->where('name', 'antic'))->exists())->toBeFalse()
        ->and(Run::query()->count())->toBe(3);
});

it('prints the summary of each site read from its own snapshot', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--wait' => true])
        ->expectsOutputToContain('6.8.3')
        ->expectsOutputToContain('9.8.1')
        ->expectsOutputToContain('8.2.20 (plesk_handler), 1 avisos')
        ->expectsOutputToContain('2 (1 sense llicència)')
        ->expectsOutputToContain('manteniment actiu; BD de WooCommerce pendent d\'actualitzar; 1 plantilles desfasades')
        ->assertSuccessful();
});

it('shows the block and its reason', function (): void {
    Site::query()->where('name', 'botiga-a')->update(['blocked_reason' => 'PHP 7.2 per sota del mínim']);

    command('inventory', ['sites' => ['botiga-a'], '--wait' => true])
        ->expectsOutputToContain('sí: PHP 7.2 per sota del mínim')
        ->assertSuccessful();
});

it('shows a failed run with its reason', function (): void {
    command('inventory', ['sites' => ['blog'], '--wait' => true])
        ->expectsOutputToContain('connexió caiguda')
        ->assertExitCode(1);
});

it('prints a versioned JSON document', function (): void {
    $json = inventoryJson(['sites' => ['botiga-a', 'blog'], '--wait' => true]);

    expect($json['version'])->toBe(1)
        ->and($json['sites'])->toHaveCount(2)
        ->and($json['sites'][1])->toMatchArray([
            'site' => 'botiga-a', 'core' => '6.8.3', 'woocommerce' => '9.8.1', 'pending_updates' => 2, 'unlicensed' => 1, 'blocked' => false,
        ])
        ->and($json['sites'][1]['php'])->toMatchArray(['version' => '8.2.20', 'source' => 'plesk_handler'])
        ->and($json['sites'][1]['state'])->toMatchArray(['maintenance' => true, 'wc_db_update_needed' => true, 'outdated_templates' => 1])
        ->and($json['sites'][0]['run']['status'])->toBe('failed')
        ->and($json['sites'][0]['core'])->toBeNull();
});

it('still prints only run ids as JSON when not waiting', function (): void {
    Artisan::call('inventory', ['sites' => ['botiga-a'], '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toHaveKey('runs')->and(is_array($json) && array_key_exists('sites', $json))->toBeFalse();
});

it('writes a CSV with one row per site', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => csvPath()])->assertSuccessful();

    $rows = array_map(static fn (string $l): array => str_getcsv($l, escape: ''), file(csvPath(), FILE_IGNORE_NEW_LINES) ?: []);

    expect($rows[0])->toBe(['site', 'url', 'core', 'woocommerce', 'php', 'php_source', 'db_server', 'pending_updates', 'unlicensed', 'blocked', 'blocked_reason'])
        ->and($rows[1])->toBe(['botiga-a', 'https://a.test', '6.8.3', '9.8.1', '8.2.20', 'plesk_handler', '11.4.2-MariaDB', '2', '1', 'no', '']);
});

it('protects the CSV against formula injection', function (): void {
    Site::query()->where('name', 'botiga-a')->update(['blocked_reason' => '=HYPERLINK("http://x")']);

    command('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => csvPath()])->assertSuccessful();

    expect(file_get_contents(csvPath()))->toContain("\"'=HYPERLINK(")
        ->and(str_contains((string) file_get_contents(csvPath()), ',"=HYPERLINK'))->toBeFalse();
});

it('refuses to overwrite an existing CSV, before queuing anything', function (): void {
    file_put_contents(csvPath(), 'keep');

    command('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => csvPath()])
        ->expectsOutputToContain('ja existeix')
        ->assertFailed();

    expect(file_get_contents(csvPath()))->toBe('keep')->and(Run::query()->count())->toBe(0);
});

it('requires --wait or --follow for --csv', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--csv' => csvPath()])
        ->expectsOutputToContain('--csv necessita --wait o --follow')
        ->assertFailed();

    expect(Run::query()->count())->toBe(0)->and(file_exists(csvPath()))->toBeFalse();
});

it('fails when the CSV directory is not writable', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => '/nonexistent-dir/x.csv'])
        ->expectsOutputToContain('No es pot escriure')
        ->assertFailed();
});

it('propagates --dry-run to the run flags and previews the block from the prerequisites', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--dry-run' => true, '--wait' => true])
        ->expectsOutputToContain('sí: PHP massa antic')
        ->assertSuccessful();

    expect(Run::query()->firstOrFail()->flags)->toMatchArray(['dry_run' => true])
        ->and(Site::query()->where('name', 'botiga-a')->value('blocked_reason'))->toBeNull();
});

it('refuses a deactivated user', function (): void {
    User::factory()->create(['unix_username' => 'gone1', 'role' => UserRole::Operator, 'active' => false]);
    actingAsOperator('gone1');

    command('inventory', ['sites' => ['botiga-a']])->assertFailed();

    expect(Run::query()->count())->toBe(0);
});
