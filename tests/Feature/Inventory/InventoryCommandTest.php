<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryReport;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepOutcome;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedReadOnlyStep;

/**
 * @param  array<string, mixed>  $arguments
 * @return array{version: int, sites: list<array{site: string, core: ?string, woocommerce: ?string, pending_updates: ?int, unlicensed: ?int, blocked: bool, status: string, failure: ?string, blocked_reason: ?string, php: array{version: ?string, source: ?string}, state: array{maintenance: bool, wc_db_update_needed: bool, outdated_templates: int}, external_backup_warnings: list<string>}>}
 */
function inventoryJson(array $arguments): array
{
    Artisan::call('inventory', $arguments + ['--json' => true]);

    /** @var array{version: int, sites: list<array{site: string, core: ?string, woocommerce: ?string, pending_updates: ?int, unlicensed: ?int, blocked: bool, status: string, failure: ?string, blocked_reason: ?string, php: array{version: ?string, source: ?string}, state: array{maintenance: bool, wc_db_update_needed: bool, outdated_templates: int}, external_backup_warnings: list<string>}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $json;
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    Site::factory()->create(['name' => 'botiga-a', 'url' => 'https://a.test', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'url' => 'https://blog.test', 'tags' => ['nit']]);
    Site::factory()->create(['name' => 'mixt', 'url' => 'https://mixt.test']);
    Site::factory()->create(['name' => 'antic', 'tags' => ['woo'], 'active' => false]);

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

            if ($site->name === 'mixt') {
                return StepOutcome::failed('pas fallit després del snapshot');
            }

            return StepOutcome::succeeded('ok', '', ['block' => ['outcome' => $context->dryRun ? 'would_block' : 'unchanged', 'reason' => $context->dryRun ? 'PHP massa antic' : null]]);
        }),
    ]));
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
        ->and(Run::query()->count())->toBe(4);
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
        ->and($json['sites'][0]['status'])->toBe('failed')
        ->and($json['sites'][0]['core'])->toBeNull();
});

it('still prints only run ids as JSON when not waiting', function (): void {
    Artisan::call('inventory', ['sites' => ['botiga-a'], '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toHaveKey('runs')->and(is_array($json) && array_key_exists('sites', $json))->toBeFalse();
});

it('writes the CSV to stdout, one row per site, with status and failure', function (): void {
    Artisan::call('inventory', ['sites' => ['botiga-a', 'blog'], '--wait' => true, '--csv' => true]);
    $rows = array_map(static fn (string $l): array => str_getcsv($l, escape: ''), explode("\n", trim(Artisan::output())));

    expect($rows[0])->toBe(['site', 'url', 'core', 'woocommerce', 'php', 'php_source', 'db_server', 'pending_updates', 'unlicensed', 'blocked', 'blocked_reason', 'status', 'failure'])
        ->and($rows[1])->toBe(['blog', 'https://blog.test', '', '', '', '', '', '', '', 'no', '', 'failed', "No s'ha pogut fer l'inventari: connexió caiguda"])
        ->and($rows[2])->toBe(['botiga-a', 'https://a.test', '6.8.3', '9.8.1', '8.2.20', 'plesk_handler', '11.4.2-MariaDB', '2', '1', 'no', '', 'succeeded', '']);
});

it('protects the CSV against formula injection and strips line breaks', function (): void {
    Site::query()->where('name', 'botiga-a')->update(['blocked_reason' => "=HYPERLINK(\"http://x\")\nsegona\rlínia"]);

    Artisan::call('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => true]);
    $output = Artisan::output();

    expect($output)->toContain("\"'=HYPERLINK(")
        ->and(str_contains($output, ',"=HYPERLINK'))->toBeFalse()
        ->and($output)->toContain('segona línia')
        ->and(substr_count($output, "\n"))->toBe(2);
});

it('rejects --csv with --json, with --follow or without --wait, before queuing', function (): void {
    command('inventory', ['sites' => ['botiga-a'], '--wait' => true, '--csv' => true, '--json' => true])->expectsOutputToContain('incompatibles')->assertFailed();
    command('inventory', ['sites' => ['botiga-a'], '--follow' => true, '--csv' => true])->expectsOutputToContain('--follow')->assertFailed();
    command('inventory', ['sites' => ['botiga-a'], '--csv' => true])->expectsOutputToContain('necessita --wait')->assertFailed();

    expect(Run::query()->count())->toBe(0);
});

it('does not accept a path for --csv', function (): void {
    expect(Artisan::all()['inventory']->getDefinition()->getOption('csv')->acceptValue())->toBeFalse();
});

it('shows the failure of a run that failed after taking the snapshot', function (): void {
    command('inventory', ['sites' => ['mixt'], '--wait' => true])
        ->expectsOutputToContain('pas fallit després del snapshot')
        ->expectsOutputToContain('6.8.3')
        ->assertExitCode(1);
});

it('reports the real block of the site, an operator one included, in JSON and CSV', function (): void {
    Site::query()->where('name', 'blog')->update(['blocked_reason' => 'bloqueig manual', 'blocked_by' => 'operator']);

    $json = inventoryJson(['sites' => ['blog'], '--wait' => true]);

    expect($json['sites'][0])->toMatchArray(['blocked' => true, 'blocked_reason' => 'bloqueig manual', 'status' => 'failed']);
});

it('reads the block of a dry run from what the inventory said, else from the site', function (): void {
    Site::query()->where('name', 'blog')->update(['blocked_reason' => 'bloqueig manual']);
    $kept = inventoryJson(['sites' => ['blog'], '--wait' => true, '--dry-run' => true])['sites'][0];

    Site::query()->where('name', 'botiga-a')->update(['blocked_reason' => 'antic']);
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (Run $run): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('ok', '', ['block' => ['outcome' => 'would_unblock', 'reason' => null]])),
    ]));
    $unblock = inventoryJson(['sites' => ['botiga-a'], '--wait' => true, '--dry-run' => true])['sites'][0];

    expect($kept['blocked'])->toBeTrue()
        ->and($unblock['blocked'])->toBeFalse();
});

it('treats a skipped run as neutral and survives a run without site', function (): void {
    $skipped = Run::factory()->create(['type' => RunType::Inventory, 'status' => RunStatus::Skipped, 'result_summary' => ['reason' => 'ja inventariat']]);
    $orphan = Run::factory()->create(['type' => RunType::Inventory, 'site_id' => null, 'status' => RunStatus::Failed]);

    $rows = app(InventoryReport::class)->rows([$skipped, $orphan]);

    expect($rows[0]->runStatus)->toBe(RunStatus::Skipped)
        ->and($rows[0]->failure)->toBe('ja inventariat')
        ->and($rows[1]->site)->toBe('(sense lloc)')
        ->and($rows[1]->blocked)->toBeFalse();
});

it('loads sites and snapshots in a constant number of queries', function (): void {
    $runs = [];
    foreach (['botiga-a', 'blog', 'mixt'] as $name) {
        $runs[] = Run::factory()->create(['type' => RunType::Inventory, 'site_id' => Site::query()->where('name', $name)->value('id'), 'status' => RunStatus::Succeeded]);
    }
    DB::enableQueryLog();
    app(InventoryReport::class)->rows($runs);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);
});

it('requires a valid report version', function (): void {
    config(['wpup.inventory.report_version' => 'x']);

    expect(fn () => app(InventoryReport::class)->document([]))->toThrow(UnexpectedValueException::class);
});

it('propagates --dry-run to the run flags and previews the block the inventory predicted', function (): void {
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

it('reports external backup warnings per site from the profile and the detection', function (): void {
    $site = Site::query()->where('name', 'botiga-a')->firstOrFail();
    $site->profile()->create(['external_backup' => ['type' => 'plesk']]);

    $json = inventoryJson(['sites' => ['botiga-a', 'mixt'], '--wait' => true]);
    $warnings = array_column($json['sites'], 'external_backup_warnings', 'site');

    expect($warnings['botiga-a'])->toBe([])
        ->and($warnings['mixt'])->toHaveCount(1)->and($warnings['mixt'][0])->toContain('Sense còpia externa coneguda');
});
