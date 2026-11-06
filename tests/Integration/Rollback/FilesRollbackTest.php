<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\UpdateChanges;
use App\Enums\AuditChannel;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Fixtures;

/*
 * Files rollback of an update (UZEROWPUP-57) end to end against a Docker
 * test site (make fixtures-up), never a real one: an "update" breaks the site
 * after its restore point, and the rollback leaves the code tree identical.
 * The store root is the test's own; afterwards, `snapshot.sh restore wp83`.
 */

const FILES_ROLLBACK_FIXTURE = 'wp83';

if (! Fixtures::up(FILES_ROLLBACK_FIXTURE)) {
    it('rolls back the files of an update that broke the site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/** SHA-256 of every path and file of the docroot except uploads, sorted: equal means an identical tree. */
function filesRollbackTree(Site $site): string
{
    $root = escapeshellarg($site->wp_path);

    return Fixtures::run(FILES_ROLLBACK_FIXTURE, "cd {$root} && { find . -path ./wp-content/uploads -prune -o -print | LC_ALL=C sort; "
        .'find . -path ./wp-content/uploads -prune -o -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum; } | sha256sum');
}

it('rolls back the files of an update that broke the site: identical tree and versions, changes reverted', function (): void {
    Redis::connection('locks')->command('flushdb');
    $fixture = FILES_ROLLBACK_FIXTURE;
    $storeRoot = 'restore-points/it-rollback-'.bin2hex(random_bytes(6));
    $site = Fixtures::site($fixture);
    $site->update(['url' => 'http://'.$fixture.':8083']);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($storeRoot), $s->url,
    ));
    // The `wpup rollback` pipeline is UZEROWPUP-61: here, only the steps this task provides.
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => new Pipeline(app(RollbackSteps::class)->steps()));
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $plugins = "{$site->wp_path}/wp-content/plugins";
    // Any other plugin of the fixture (a directory or a single file): the mirror must bring it back.
    $others = array_values(array_filter($driver->installedComponents()->components, static fn (ComponentVersion $c): bool => $c->type === ComponentType::Plugin && $c->slug !== 'classic-editor'));
    $removed = ($others[0] ?? null)->slug ?? throw new LogicException('The fixture has no plugin to remove.');

    $user = User::factory()->create(['role' => UserRole::Operator]);
    $update = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'status' => RunStatus::Failed]);

    try {
        $before = app(SiteInventory::class)->take($site, $driver, $update, probeWeb: false);
        app(RestorePointService::class)->create($site, $driver, $update, AuditActor::system('tests'));
        $tree = filesRollbackTree($site);
        $editor = $driver->installedComponents()->find(ComponentType::Plugin, 'classic-editor');
        expect($editor)->toBeInstanceOf(ComponentVersion::class);

        // The "update": classic-editor rewritten with a fatal error and a new version, a plugin added, another one removed.
        Fixtures::run($fixture, 'cd '.escapeshellarg($plugins).' && printf "<?php\n/* Plugin Name: Classic Editor\n * Version: 9.9.9 */\nthrow new Error(\"wpup-it rollback\");\n" > classic-editor/classic-editor.php'
            .' && mkdir -p wpup-it-added && printf "<?php\n/* Plugin Name: wpup-it-added\n * Version: 1.0.0 */\n" > wpup-it-added/wpup-it-added.php'
            .' && rm -rf '.escapeshellarg($removed).' '.escapeshellarg("{$removed}.php"));
        foreach ([['classic-editor', $editor?->version, '9.9.9'], ['wpup-it-added', null, '1.0.0'], [$removed, '1', null]] as [$slug, $from, $to]) {
            RunChange::factory()->create(['run_id' => $update->id, 'step' => UpdateChanges::STEP, 'component_type' => ComponentType::Plugin,
                'slug' => $slug, 'version_before' => $from, 'version_after' => $to, 'result' => ChangeResult::Updated]);
        }
        expect(filesRollbackTree($site))->not->toBe($tree);

        $run = app(RunEngine::class)->execute(Run::factory()->create(['type' => RunType::Rollback, 'site_id' => $site->id, 'parent_run_id' => $update->id,
            'user_id' => $user->id, 'actor' => $user->name, 'channel' => AuditChannel::Cli]));

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and(filesRollbackTree($site))->toBe($tree)
            ->and($driver->installedComponents()->find(ComponentType::Plugin, 'classic-editor')?->version)->toBe($editor?->version)
            ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-it-added'))->toBeNull()
            ->and($driver->installedComponents()->find(ComponentType::Plugin, $removed))->not->toBeNull()
            ->and($driver->wp(['core', 'version'])->stdout)->toBe($before->core_version."\n")
            ->and($update->changes()->where('result', ChangeResult::Reverted)->count())->toBe(3);
    } finally {
        Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($storeRoot).' '.escapeshellarg("{$plugins}/wpup-it-added"));
    }
});
