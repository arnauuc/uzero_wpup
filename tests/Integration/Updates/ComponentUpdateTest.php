<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Runs\StepContext;
use App\Domain\Updates\UpdateSteps;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStepStatus;
use App\Models\Run;
use Illuminate\Support\Facades\Http;
use Tests\Contracts\SiteDriverContract;
use Tests\Support\Fixtures;

/*
 * The update step against a Docker test site (make fixtures-up), never a real
 * one: a tiny test plugin built here is installed from the run's artifact.
 * Afterwards the docroot is mirrored back from an archive taken first.
 */

if (! Fixtures::up('wp83')) {
    it('updates a component from its artifact on a test site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    // The package comes from the run's artifact: the tool downloads nothing either.
    Http::fake(['*' => Http::response('', 500)]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

it('updates an active plugin from its artifact on wp83, keeping it active, idempotently', function (): void {
    $site = Fixtures::site('wp83');
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
    $old = SiteDriverContract::contractPluginZip('1.0.0');
    $new = SiteDriverContract::contractPluginZip('1.1.0');

    try {
        $driver->applyUpdate(SiteDriverContract::package($old, '1.0.0'));
        $driver->wp(['plugin', 'activate', 'wpup-contract-plugin']);

        $run = Run::factory()->create(['site_id' => $site->id]);
        $store = app(ArtifactStore::class);
        $temporary = $store->temporaryFile($run->id);
        copy($new, $temporary);
        $source = PackageSource::create(ComponentType::Plugin, 'wpup-contract-plugin', '1.1.0', 'https://updates.example.com/wpup-contract-plugin.zip', app(PackageUrlGuard::class));
        $store->commit($run->id, $source, $temporary, (string) hash_file('sha256', $new), (int) filesize($new));
        $change = new PlannedChange(ComponentType::Plugin, 'wpup-contract-plugin', '1.0.0', '1.1.0', PlanAction::Update);
        $step = app(UpdateSteps::class)->for(new UpdatePlan('8.3', PhpVersionSource::PleskHandler, true, null, [$change]))[0];
        $context = new StepContext($run, false);

        $first = $step->run($context);
        $second = $step->run($context);
        $plugin = $driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin');
        $row = $run->changes()->sole();

        expect($first->status)->toBe(RunStepStatus::Succeeded)
            ->and($second->summary)->toContain('ja era a la versió 1.1.0')
            ->and($plugin?->version)->toBe('1.1.0')
            ->and($plugin?->active)->toBeTrue()
            ->and([$row->step, $row->version_before, $row->version_after, $row->result])->toBe(['update', '1.0.0', '1.1.0', ChangeResult::Updated])
            ->and(SiteDriverContract::packageTemporaries($driver))->toBe([]);
    } finally {
        $driver->wp(['plugin', 'deactivate', 'wpup-contract-plugin']);
        $driver->restoreFiles($archive);
        $driver->remove($archive->path);
        @unlink($old);
        @unlink($new);
    }
});
