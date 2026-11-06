<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Fixtures;

/*
 * Database rollback of an update (UZEROWPUP-59, AGENTS.md rule 4) end to end
 * against the WooCommerce test site (make fixtures-up), never a real one. The
 * store root is the test's own; the order, the option and the maintenance
 * file the test writes are removed or put back. Afterwards,
 * `snapshot.sh restore wp83`.
 */

const DATABASE_ROLLBACK_FIXTURE = 'wp83';

if (! Fixtures::up(DATABASE_ROLLBACK_FIXTURE)) {
    it('rolls back the database of an update only when nothing was written')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/**
 * An update run of the fixture with its verified restore point; afterwards
 * the "update" changes an option of the database.
 *
 * @return array{Site, SiteDriver, Run, string, string}
 */
function databaseRollbackFixture(): array
{
    Redis::connection('locks')->command('flushdb');
    config(['wpup.backups.watermark.margin_seconds' => 0]);
    $storeRoot = 'restore-points/it-db-rollback-'.bin2hex(random_bytes(6));
    $site = Fixtures::site(DATABASE_ROLLBACK_FIXTURE);
    $site->update(['url' => 'http://'.DATABASE_ROLLBACK_FIXTURE.':8083']);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($storeRoot), $s->url,
    ));
    // The `wpup rollback` pipeline is UZEROWPUP-61: here, only this step.
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => new Pipeline([app(DatabaseRollbackStep::class)]));
    Fixtures::run(DATABASE_ROLLBACK_FIXTURE, 'rm -f '.escapeshellarg("{$site->wp_path}/.maintenance"));
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $blogname = databaseRollbackOption($driver);

    $user = User::factory()->create(['role' => UserRole::Operator]);
    $update = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'status' => RunStatus::Failed]);
    sleep(1); // dates have a resolution of one second
    app(RestorePointService::class)->create($site, $driver, $update, AuditActor::system('tests'));
    $driver->wp(['option', 'update', 'blogname', 'canviat per l\'actualització', '--skip-plugins', '--skip-themes']);

    return [$site, $driver, $update, $storeRoot, $blogname];
}

function databaseRollbackOption(SiteDriver $driver): string
{
    return trim($driver->wp(['option', 'get', 'blogname', '--skip-plugins', '--skip-themes'])->stdout);
}

function databaseRollbackExecute(Site $site, Run $update): Run
{
    return app(RunEngine::class)->execute(Run::factory()->create(['type' => RunType::Rollback, 'site_id' => $site->id, 'parent_run_id' => $update->id,
        'user_id' => $update->user_id, 'actor' => $update->actor, 'channel' => AuditChannel::Cli]));
}

function databaseRollbackCleanup(Site $site, SiteDriver $driver, string $storeRoot, string $blogname): void
{
    Fixtures::run(DATABASE_ROLLBACK_FIXTURE, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($storeRoot).' '.escapeshellarg("{$site->wp_path}/.maintenance"));
    if (databaseRollbackOption($driver) !== $blogname) {
        $driver->wp(['option', 'update', 'blogname', $blogname, '--skip-plugins', '--skip-themes']);
    }
}

it('rolls back the database of an update when nothing was written to the orders or users', function (): void {
    [$site, $driver, $update, $storeRoot, $blogname] = databaseRollbackFixture();

    try {
        $run = databaseRollbackExecute($site, $update);

        $step = $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($step->result['restored'] ?? null)->toBeTrue((string) $step->output)
            ->and(databaseRollbackOption($driver))->toBe($blogname)
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse()
            ->and(Fixtures::run(DATABASE_ROLLBACK_FIXTURE, 'ls "$HOME/.wpup" | grep -cE "^restore-[0-9a-f]{16}" || true'))->toBe("0\n");
    } finally {
        databaseRollbackCleanup($site, $driver, $storeRoot, $blogname);
    }
});

it('never restores the database of a shop with an order made after the copy, and tells which', function (): void {
    [$site, $driver, $update, $storeRoot, $blogname] = databaseRollbackFixture();
    $order = null;

    try {
        $created = $driver->wp(['eval', '$o = wc_create_order(); $o->set_total(12.5); $o->set_status("processing"); $o->save(); echo $o->get_id();']);
        $order = (int) trim($created->stdout);
        expect($order)->toBeGreaterThan(0, $created->stderr);

        $run = databaseRollbackExecute($site, $update);

        $step = $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($step->result['restored'] ?? null)->toBeFalse()
            ->and($step->summary)->toStartWith('BD no restaurada')
            ->and($step->output)->toContain("#{$order} (nova, comanda, processing")
            ->and(data_get($step->result, 'new_writes.orders.*.id'))->toContain($order)
            // The database was not touched: the update's change and the order are still there.
            ->and(databaseRollbackOption($driver))->toBe('canviat per l\'actualització')
            ->and(trim($driver->wp(['eval', "echo wc_get_order({$order}) ? 'yes' : 'no';"])->stdout))->toBe('yes')
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
    } finally {
        if ($order !== null && $order > 0) {
            $driver->wp(['eval', "\$o = wc_get_order({$order}); if (\$o) { \$o->delete(true); } echo 'ok';"]);
        }
        databaseRollbackCleanup($site, $driver, $storeRoot, $blogname);
    }
});
