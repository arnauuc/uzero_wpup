<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Rollback\RestoredState;
use App\Domain\Rollback\RevertedChanges;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

/*
 * Files rollback of an update run (UZEROWPUP-57) over FakeDriver: the code of
 * the run's own restore point comes back exactly, the versions are checked
 * and the applied changes are marked reverted.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    Http::fake(['*' => Http::response('', 500)]);
});

/** The site-local store and the rollback pipeline, both served by $driver. */
function filesRollbackPipeline(FakeDriver $driver): void
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));
    app()->bind(RollbackSteps::class, static fn (): RollbackSteps => new RollbackSteps(
        app(RestoreSelection::class), app(SiteRestore::class), app(RevertedChanges::class),
        static fn (Site $s, AuditActor $a): SiteDriver => $driver, app(RunAuthorization::class),
        app(AuditLogger::class), app(SecretRedactor::class), 60,
    ));
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => new Pipeline(app(RollbackSteps::class)->steps()));
}

/**
 * An update run with its inventory and verified restore point, after which
 * the "update" changes the site: classic-editor updated, a plugin added and
 * the theme removed.
 *
 * @return array{Site, Run}
 */
function filesRollbackUpdate(FakeDriver $driver, bool $withSnapshot = true): array
{
    $site = Site::factory()->create(['name' => 'botiga']);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $update = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'status' => RunStatus::Succeeded]);
    if ($withSnapshot) {
        app(SiteInventory::class)->take($site, $driver, $update, probeWeb: false);
    }
    app(RestorePointService::class)->create($site, $driver, $update, AuditActor::system('tests'));

    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', '1.7.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'new-dependency', '2.0.0'));
    unset($driver->components['theme:storefront']);
    foreach ([['classic-editor', '1.6.3', '1.7.0', ChangeResult::Updated], ['new-dependency', null, '2.0.0', ChangeResult::Updated], ['storefront', '4.6.0', null, ChangeResult::Failed]] as [$slug, $before, $after, $result]) {
        RunChange::factory()->create(['run_id' => $update->id, 'step' => RevertedChanges::UPDATE_STEP, 'slug' => $slug, 'version_before' => $before, 'version_after' => $after, 'result' => $result,
            'component_type' => $slug === 'storefront' ? ComponentType::Theme : ComponentType::Plugin]);
    }
    RunChange::factory()->create(['run_id' => $update->id, 'step' => 'plan', 'slug' => 'akismet', 'result' => ChangeResult::Skipped]);

    return [$site, $update];
}

function filesRollbackRun(Site $site, Run $update, string $channel = 'cli'): Run
{
    return Run::factory()->create(['type' => RunType::Rollback, 'site_id' => $site->id, 'parent_run_id' => $update->id, 'user_id' => $update->user_id,
        'actor' => $update->actor, 'channel' => AuditChannel::from($channel)]);
}

it('registers the rollback pipeline with the files rollback', function (): void {
    $pipeline = app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Rollback]));

    expect(array_map(fn ($s): string => $s->name(), $pipeline->steps))->toBe([FilesRollbackStep::NAME]);
});

it('puts back the code of the run: updated, added and removed components, and marks the applied changes reverted', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $driver->files['restore-0123456789abcdef.files.tar.gz'] = 'resta d\'una restauració interrompuda';
    $driver->maintenance = true;

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    $step = $run->steps()->where('name', FilesRollbackStep::NAME)->sole();
    expect($run->status)->toBe(RunStatus::Succeeded, (string) $step->error)
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($driver->components)->not->toHaveKey('plugin:new-dependency')
        ->and($driver->components)->toHaveKey('theme:storefront')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        // Kept on: the mirror removes .maintenance.
        ->and($driver->maintenance)->toBeTrue()
        ->and($driver->files)->not->toHaveKey('restore-0123456789abcdef.files.tar.gz')
        ->and($step->summary)->toContain("l'execució #{$update->id}");

    $change = static fn (string $slug): RunChange => $update->changes()->where('slug', $slug)->sole();
    expect($change('classic-editor')->result)->toBe(ChangeResult::Reverted)
        ->and($change('classic-editor')->reverted_at)->not->toBeNull()
        ->and($change('classic-editor')->version_after)->toBe('1.7.0')
        ->and($change('new-dependency')->result)->toBe(ChangeResult::Reverted)
        ->and($change('storefront')->result)->toBe(ChangeResult::Failed)
        ->and($change('akismet')->result)->toBe(ChangeResult::Skipped);

    $audit = AuditLogEntry::query()->where('action', 'rollback.files')->sole();
    $files = Backup::query()->where('run_id', $update->id)->where('kind', BackupKind::Files)->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context)->toMatchArray(['run_id' => $run->id, 'source_run_id' => $update->id, 'backup_id' => $files->id, 'restored' => true, 'reverted_changes' => 2]);
});

it('is idempotent: running the step again restores and checks again, and marks nothing twice', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $step = app(RollbackSteps::class)->files();
    $context = new StepContext(filesRollbackRun($site, $update), false);

    $first = $step->run($context);
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'new-dependency', '2.0.0'));
    $second = $step->run($context);

    expect($first->data['reverted_changes'] ?? null)->toBe(2)
        ->and($second->data['reverted_changes'] ?? null)->toBe(0)
        ->and($driver->components)->not->toHaveKey('plugin:new-dependency')
        ->and($update->changes()->where('result', ChangeResult::Reverted)->count())->toBe(2);
});

it('rolls back the update run itself, as a scheduled run of the system', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [, $update] = filesRollbackUpdate($driver, withSnapshot: false);
    $update->update(['user_id' => null, 'actor' => 'scheduler', 'channel' => AuditChannel::System, 'status' => RunStatus::RollingBack]);

    $outcome = app(RollbackSteps::class)->files()->run(new StepContext($update->refresh(), false));

    expect($outcome->summary)->toContain('versions comprovades')
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($update->changes()->where('result', ChangeResult::Reverted)->count())->toBe(2);
});

it('fails loudly, without marking anything, when the versions are not those from before', function (): void {
    $driver = new class extends FakeDriver
    {
        public function restoreFiles(RemoteFile $archive): void
        {
            parent::restoreFiles($archive);
            // Something the archive does not cover changed it again.
            $this->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', '1.7.0'));
        }
    };
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    $step = $run->steps()->where('name', FilesRollbackStep::NAME)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($step->error)->toContain('plugin:classic-editor: abans 1.6.3, ara 1.7.0')->toContain("Els fitxers ja s'han restaurat")
        ->and($update->changes()->where('result', ChangeResult::Reverted)->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'rollback.files')->sole()->result)->toBe(AuditResult::Failure);
});

it('refuses before touching the site when the run has no verified copy of the files', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    Backup::query()->where('run_id', $update->id)->update(['verified_at' => null]);
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->sole()->error)->toContain('no té cap còpia dels fitxers utilitzable')->toContain("No s'ha restaurat res")
        ->and($driver->writeCalls)->toBe([])
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.7.0');
});

it('refuses when the site-local copy no longer holds its bytes and there is no other one', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $row = Backup::query()->where('run_id', $update->id)->where('kind', BackupKind::Files)->sole();
    $driver->files["restore-points/{$row->path}"] .= 'alterat';
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->sole()->error)->toContain('SHA-256')
        ->and($driver->writeCalls)->not->toContain('restoreFiles');
});

it('refuses a rollback whose parent is not an update of the same site', function (string $case): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $rollback = filesRollbackRun($site, $update);
    match ($case) {
        'no parent' => $rollback->update(['parent_run_id' => null]),
        'not an update' => $update->update(['type' => RunType::Backup]),
        default => $rollback->update(['site_id' => Site::factory()->create()->id]),
    };

    $run = app(RunEngine::class)->execute($rollback->refresh());

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.7.0');
})->with(['no parent', 'not an update', 'other site']);

it('compares with the run inventory: anything missing, added or changed is a difference', function (): void {
    $before = new SiteSnapshot(['core_version' => '6.8.3', 'plugins' => [['slug' => 'woocommerce', 'version' => '10.1.0', 'type' => 'woocommerce'], ['slug' => 'a', 'version' => '1.0']],
        'themes' => [['slug' => 't', 'version' => '2.0']], 'dropins' => [], 'mu_plugins' => [['slug' => 'm', 'version' => '1']]]);
    $now = new InstalledComponents('6.8.3', '8.3', '10.11', [
        new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0'),
        new ComponentVersion(ComponentType::Plugin, 'a', '1.1'),
        new ComponentVersion(ComponentType::Plugin, 'b', '1.0'),
        new ComponentVersion(ComponentType::MuPlugin, 'm', '1'),
    ]);

    expect(RestoredState::differences($now, $before, []))->toBe([
        'plugin:a: abans 1.0, ara 1.1.',
        'plugin:b: abans no hi era, ara 1.0.',
        'theme:t: abans 2.0, ara no hi és.',
    ]);
});
