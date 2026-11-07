<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Rollback\RestoredState;
use App\Domain\Rollback\RevertedChanges;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\UpdateChanges;
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
        app(RestoreSelection::class), app(SiteRestore::class), app(RevertedChanges::class), app(NewWritesDetector::class),
        static fn (Site $s, AuditActor $a): SiteDriver => $driver, app(RunAuthorization::class),
        app(AuditLogger::class), app(SecretRedactor::class), 60, 60,
    ));
    // Only the files: the database rollback has its own tests (DatabaseRollbackTest).
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => new Pipeline([app(RollbackSteps::class)->files()]));
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
        RunChange::factory()->create(['run_id' => $update->id, 'step' => UpdateChanges::STEP, 'slug' => $slug, 'version_before' => $before, 'version_after' => $after, 'result' => $result,
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

it('gives the rollback steps for 60 and 61 to compose, without registering a rollback pipeline itself', function (): void {
    expect(array_map(fn ($s): string => $s->name(), app(RollbackSteps::class)->steps()))->toBe([FilesRollbackStep::NAME, DatabaseRollbackStep::NAME])
        ->and(fn () => app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Rollback])))->toThrow(RunException::class);
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
        // Kept on: the mirror never touches .maintenance.
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
        ->and($audit->context)->toMatchArray(['run_id' => $run->id, 'source_run_id' => $update->id, 'backup_id' => $files->id, 'restored' => 'yes', 'reverted_changes' => 2]);
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
        ->and($run->steps()->sole()->error)->toContain('SHA-256')->toContain("No s'ha restaurat res")
        ->and($driver->writeCalls)->not->toContain('restoreFiles')
        ->and(AuditLogEntry::query()->where('action', 'rollback.files')->sole()->context)->toMatchArray(['restored' => 'no']);
});

it('says the site may be half restored when the mirror fails, and switches the maintenance mode on again', function (): void {
    $driver = new class extends FakeDriver
    {
        public function restoreFiles(RemoteFile $archive): void
        {
            $this->maintenance = false;

            throw new RemoteCommandFailed('rsync: connexió perduda');
        }
    };
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $driver->maintenance = true;

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    expect($run->steps()->sole()->error)->not->toContain("No s'ha restaurat res");
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->sole()->error)->toContain('a mitges')
        ->and($driver->maintenance)->toBeTrue()
        ->and($update->changes()->where('result', ChangeResult::Reverted)->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'rollback.files')->sole()->context)->toMatchArray(['restored' => 'unknown']);
});

it('switches the maintenance mode on after the mirror when its state cannot be told', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $driver->failingCommands = ['maintenance-mode is-active'];

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->maintenance)->toBeTrue()
        ->and($run->steps()->sole()->output)->toContain("No s'ha pogut saber si el mode manteniment era actiu");
});

it('leaves the maintenance mode off when it was off', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);

    expect(app(RunEngine::class)->execute(filesRollbackRun($site, $update))->status)->toBe(RunStatus::Succeeded)
        ->and($driver->maintenance)->toBeFalse();
});

it('refuses to undo an update when a later update of the site is still applied', function (): void {
    $driver = new FakeDriver;
    filesRollbackPipeline($driver);
    [$site, $update] = filesRollbackUpdate($driver);
    $later = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'status' => RunStatus::Succeeded]);
    RunChange::factory()->create(['run_id' => $later->id, 'step' => UpdateChanges::STEP, 'result' => ChangeResult::Updated]);
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(filesRollbackRun($site, $update));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->sole()->error)->toContain("l'actualització #{$later->id}")->toContain("Reverteix primer la #{$later->id}")
        ->and($driver->writeCalls)->toBe([]);

    // Once the later one is undone, the older one can be.
    $later->changes()->update(['result' => ChangeResult::Reverted->value]);
    Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'status' => RunStatus::RolledBack]);
    Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'status' => RunStatus::Cancelled]);

    expect(app(RunEngine::class)->execute(filesRollbackRun($site, $update))->status)->toBe(RunStatus::Succeeded);
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

it('checks the components the update touched and the plugins and themes that appeared or disappeared, nothing else', function (): void {
    $before = new SiteSnapshot(['core_version' => '6.8.3', 'plugins' => [['slug' => 'woocommerce', 'version' => '10.1.0', 'type' => 'woocommerce'], ['slug' => 'a', 'version' => '1.0']],
        'themes' => [['slug' => 't', 'version' => '2.0']], 'dropins' => [], 'mu_plugins' => [['slug' => 'm', 'version' => '1']]]);
    $now = new InstalledComponents('6.8.3', '8.3', '10.11', [
        new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0'),
        new ComponentVersion(ComponentType::Plugin, 'a', '1.1'),
        new ComponentVersion(ComponentType::Plugin, 'b', '1.0'),
        new ComponentVersion(ComponentType::MuPlugin, 'm', '2'),
        new ComponentVersion(ComponentType::Dropin, 'object-cache.php', '1'),
    ]);
    $changes = [new RunChange(['component_type' => ComponentType::Plugin, 'slug' => 'a', 'version_before' => '0.9', 'result' => ChangeResult::Updated])];

    // a: the inventory's version wins; m and the drop-in were not touched by the update.
    expect(RestoredState::differences($now, $before, $changes))->toBe([
        'plugin:a: abans 1.0, ara 1.1.',
        'plugin:b: abans no hi era, ara 1.0.',
        'theme:t: abans 2.0, ara no hi és.',
    ])
        ->and(RestoredState::differences($now, null, $changes))->toBe(['plugin:a: abans 0.9, ara 1.1.'])
        ->and(RestoredState::differences($now, $before, []))->toBe(['plugin:b: abans no hi era, ara 1.0.', 'theme:t: abans 2.0, ara no hi és.']);
});
