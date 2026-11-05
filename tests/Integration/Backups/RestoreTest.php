<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\BackupKind;
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
 * `backup:restore` (UZEROWPUP-46) end to end against a Docker test site (make
 * fixtures-up), never a real one: restore point, changes to a plugin file and
 * to the database, restore of both, and the site is as it was. The test uses
 * its own root of the site-local store, removed at the end, and leaves the
 * site as the fixture had it.
 */

const RESTORE_TEST_FIXTURE = 'wp83';

if (! Fixtures::up(RESTORE_TEST_FIXTURE)) {
    it('restores a site to a restore point')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

it('restores a site to a restore point: same plugin file, same database, and a verified point of the state before', function (): void {
    Redis::connection('locks')->command('flushdb');
    $fixture = RESTORE_TEST_FIXTURE;
    $root = 'restore-points/it-restore-'.bin2hex(random_bytes(6));
    $site = Fixtures::site($fixture);
    $site->update(['url' => "http://{$fixture}:8083"]);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($root), $s->url,
    ));
    $actor = AuditActor::system('tests');
    $driver = app(DriverFactory::class)->forSite($site, $actor);
    $plugin = escapeshellarg("{$site->wp_path}/wp-content/plugins/classic-editor/classic-editor.php");
    $extra = escapeshellarg("{$site->wp_path}/wp-content/plugins/classic-editor/wpup-it-extra.php");
    $sha = static fn (): string => explode(' ', Fixtures::run($fixture, "sha256sum {$plugin}"))[0];
    $blogname = static fn (): string => trim($driver->wp(['option', 'get', 'blogname'])->stdout);
    [$originalSha, $originalName] = [$sha(), $blogname()];

    // Outside the docroot: the restore mirrors it with --delete.
    Fixtures::run($fixture, "cp -p {$plugin} \"\$HOME/wpup-it-restore.bak\"");

    try {
        $point = app(RestorePointService::class)->create($site, $driver, null, $actor);

        Fixtures::run($fixture, "printf '\\n// changed by the restore test\\n' >> {$plugin} && printf '<?php\\n' > {$extra}");
        $driver->wp(['option', 'update', 'blogname', 'canviat pel test de restauració']);
        expect($sha())->not->toBe($originalSha)->and($blogname())->not->toBe($originalName);

        $user = User::factory()->create(['role' => UserRole::Operator]);
        $request = app(RestoreSelection::class)->select($site, $point->directory, [], [BackupKind::Files, BackupKind::Database], acceptDataLoss: true);
        $run = app(RunEngine::class)->execute(Run::factory()->create([
            'type' => RunType::Restore, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name, 'channel' => AuditChannel::Cli,
            'flags' => $request->flags(),
        ]));

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($sha())->toBe($originalSha)
            ->and(Fixtures::run($fixture, "test -e {$extra} && echo present || echo gone"))->toBe("gone\n")
            ->and($blogname())->toBe($originalName)
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            // Nothing of the restore left in the work directory, outside the store.
            ->and(Fixtures::run($fixture, 'ls "$HOME/.wpup" | grep -cE "^restore-[0-9a-f]{16}" || true'))->toBe("0\n");
    } finally {
        // Whatever happened, the fixture keeps its state (the database is restored by the run or untouched).
        Fixtures::run($fixture, "mv -f \"\$HOME/wpup-it-restore.bak\" {$plugin}; rm -f {$extra}");
        if ($blogname() !== $originalName) {
            $driver->wp(['option', 'update', 'blogname', $originalName]);
        }
        Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($root));
    }
});
