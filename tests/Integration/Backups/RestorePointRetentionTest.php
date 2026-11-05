<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Fixtures;

/*
 * Retention of restore points (UZEROWPUP-44) against a Docker test site (make
 * fixtures-up), never a real one: the purged objects really leave the
 * site-local store. The test uses its own store root, removed at the end.
 */

if (! Fixtures::up('wp83')) {
    it('purges restore points from the site-local store of a test site')->skip('Llocs de prova aturats: make fixtures-up');
} else {
    it('purges the oldest restore point from the site-local store of a test site', function (): void {
        Redis::connection('locks')->command('flushdb');
        $root = 'restore-points/it-ret-'.bin2hex(random_bytes(6));
        $site = Fixtures::site('wp83');
        // Reachable from the app container: the store's canary check requests it.
        $site->update(['url' => 'http://wp83:8083']);
        app(SiteLocalDestination::class)->attach($site);
        $actor = AuditActor::system('tests');
        app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
            app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($root), $s->url,
        ));
        $service = app(RestorePointService::class);
        $stored = static fn (string $directory): string => Fixtures::run('wp83', 'ls "$HOME/.wpup/"'.escapeshellarg("{$root}/{$directory}").' 2>/dev/null | wc -l || true');

        try {
            $directories = [];
            foreach ([1, 2, 3] as $i) {
                $run = Run::factory()->create(['type' => RunType::Backup, 'site_id' => $site->id, 'status' => RunStatus::Running]);
                $directories[] = $service->create($site, app(DriverFactory::class)->forSite($site, $actor), $run, $actor)->directory;
                $run->update(['status' => RunStatus::Succeeded]);
                // Distinct creation times, oldest first.
                Backup::query()->where('run_id', $run->id)->update(['created_at' => now()->subHours(10 - $i)]);
            }

            // Making the third point purged the first one at once, on the site too.
            expect(trim($stored($directories[0])))->toBe('0')
                ->and(trim($stored($directories[1])))->toBe('2')
                ->and(trim($stored($directories[2])))->toBe('2')
                ->and(Backup::query()->where('site_id', $site->id)->whereNull('deleted_at')->count())->toBe(4);

            // Expired: the scheduled purge removes the rest from the site.
            Backup::query()->where('site_id', $site->id)->whereNull('deleted_at')->update(['expires_at' => now()->subMinute()]);
            $retention = app(RestorePointRetention::class);
            $result = $retention->prune($retention->plan([$site->id]), $actor, 'tests');

            expect($result->failed())->toBeFalse()
                ->and($result->purged)->toHaveCount(2)
                ->and(trim($stored($directories[1])))->toBe('0')
                ->and(trim($stored($directories[2])))->toBe('0');
        } finally {
            Fixtures::run('wp83', 'rm -rf "$HOME/.wpup/"'.escapeshellarg($root));
        }
    });
}
