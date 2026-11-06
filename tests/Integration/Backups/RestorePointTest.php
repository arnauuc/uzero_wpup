<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupTargetType;
use App\Enums\RunType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\Fixtures;

/*
 * Full restore points (UZEROWPUP-43) against the Docker test sites (make
 * fixtures-up). Never real sites. Each test stores under its own root of the
 * site-local store, removed at the end.
 */

const RESTORE_POINT_TEST_ROOT = 'restore-points/it-rp-';

/** HTTP port of each fixture (docker/fixtures/compose.yaml). */
const RESTORE_POINT_HTTP_PORTS = ['wp83' => 8083, 'wp74' => 8081];

foreach (['wp83', 'wp74'] as $fixture) {
    if (! Fixtures::up($fixture)) {
        it("makes a verified restore point on {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');

        continue;
    }

    it("makes a verified restore point on {$fixture}, whose checksums match sha256sum on the site", function () use ($fixture): void {
        $root = RESTORE_POINT_TEST_ROOT.bin2hex(random_bytes(6));
        $site = Fixtures::site($fixture);
        // Reachable from the app container: the store's canary check requests it.
        $site->update(['url' => "http://{$fixture}:".RESTORE_POINT_HTTP_PORTS[$fixture]]);
        app(SiteLocalDestination::class)->attach($site);
        $external = sys_get_temp_dir().'/wpup-it-restore-point-'.bin2hex(random_bytes(6));
        mkdir($external, 0700, true);
        $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp]);
        $site->backupTargets()->attach($record->id);
        app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($external)), app(SecretRedactor::class), 1_048_576));
        $run = Run::factory()->create(['type' => RunType::Backup, 'site_id' => $site->id]);
        $actor = AuditActor::system('tests');
        // The site-local destination of the registry, with this test's own store root.
        app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
            app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($root), $s->url,
        ));
        $service = app(RestorePointService::class);

        try {
            $point = $service->create($site, app(DriverFactory::class)->forSite($site, $actor), $run, $actor);

            expect($point->warnings)->toBe([])
                ->and($point->backups)->toHaveCount(4)
                ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
            foreach ($point->backups as $backup) {
                expect($backup->verified_at)->not->toBeNull();
                $actual = $backup->backup_target_id === $record->id
                    ? hash_file('sha256', "{$external}/{$backup->path}")
                    : explode(' ', Fixtures::run($fixture, 'sha256sum "$HOME/.wpup/"'.escapeshellarg("{$root}/{$backup->path}")))[0];
                expect($actual)->toBe($backup->sha256);
            }
            // Dump and archive of this run moved into the store: nothing of it left in the work
            // directory (files of other, interrupted runs are not this test's to judge).
            expect(Fixtures::run($fixture, 'ls "$HOME/.wpup" | grep -cE "^(db|files)-run'.$run->id.'-" || true'))->toBe("0\n");
        } finally {
            Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($root)." \"\$HOME/.wpup/db-run{$run->id}-\"* \"\$HOME/.wpup/files-run{$run->id}-\"*");
            File::deleteDirectory($external);
        }
    });
}
