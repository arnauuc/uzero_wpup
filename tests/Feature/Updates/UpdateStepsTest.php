<?php

declare(strict_types=1);

use App\Domain\Artifacts\Artifact;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\UpdateChanges;
use App\Domain\Updates\UpdateSteps;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStepStatus;
use App\Enums\SkipReason;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    // Packages come from the run's artifacts: nothing is ever downloaded here.
    Http::fake(['*' => Http::response('', 500)]);
    app(SecretRedactor::class)->reset();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

/**
 * The four component updates of the fake site (and a skip), in no particular order.
 *
 * @return list<PlannedChange>
 */
function fakeSiteChanges(): array
{
    return [
        new PlannedChange(ComponentType::Theme, 'storefront', '4.6.0', '4.6.1', PlanAction::Update),
        new PlannedChange(ComponentType::Plugin, 'classic-editor', '1.6.3', '1.6.4', PlanAction::Update),
        new PlannedChange(ComponentType::Plugin, 'hello', '1.6', '2.0', PlanAction::Skip, SkipReason::MajorRequiresStaging),
        new PlannedChange(ComponentType::Woocommerce, 'woocommerce', '10.1.0', '10.1.1', PlanAction::Update),
        new PlannedChange(ComponentType::Core, 'wordpress', '6.8.3', '6.8.4', PlanAction::Update),
    ];
}

/**
 * A run with a fresh inventory offering the planned updates, and their artifacts.
 *
 * @return array{Run, StepContext, array<string, Artifact>}
 */
function updateRun(): array
{
    $run = Run::factory()->create(['site_id' => Site::factory()]);
    $pending = [];
    $artifacts = [];
    foreach (fakeSiteChanges() as $change) {
        $pending[] = (new PendingUpdate($change->type->value, $change->slug, $change->slug, $change->currentVersion, $change->targetVersion, 'downloads.wordpress.org', null, null, null, PendingUpdate::SOURCE_WORDPRESS_ORG, true))->toArray();
        $artifacts[$change->slug] = updateArtifact($run, $change->type, $change->slug, $change->targetVersion);
    }
    $snapshot = SiteSnapshot::factory()->create(['site_id' => $run->site_id, 'run_id' => $run->id, 'pending_updates' => $pending]);

    return [$run, new StepContext($run, false, ['inventory' => ['snapshot_id' => $snapshot->id]]), $artifacts];
}

function updateArtifact(Run $run, ComponentType $type, string $slug, string $version): Artifact
{
    $store = app(ArtifactStore::class);
    $temporary = $store->temporaryFile($run->id);
    $bytes = "package {$slug} {$version}";
    file_put_contents($temporary, $bytes);
    $source = PackageSource::create($type, $slug, $version, "https://downloads.wordpress.org/plugin/{$slug}.{$version}.zip", app(PackageUrlGuard::class));

    return $store->commit($run->id, $source, $temporary, hash('sha256', $bytes), strlen($bytes));
}

/**
 * @return list<Step>
 */
function updateSteps(FakeDriver $driver, ?UpdatePlan $plan = null): array
{
    $builder = new UpdateSteps(
        app(PackageArtifacts::class), app(ArtifactStore::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class), app(WpCliReader::class), 60, 60, 60,
    );

    return $builder->for($plan ?? new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, fakeSiteChanges()));
}

/**
 * @return list<array{string, string, ?string, ?string, string}>
 */
function updateRows(Run $run): array
{
    return array_values($run->changes()->orderBy('id')->get()->map(static fn (RunChange $c): array => [
        $c->component_type->value, $c->slug, $c->version_before, $c->version_after, $c->result->value,
    ])->all());
}

it('builds one step per planned update in the fixed order, then the translations', function (): void {
    $names = array_map(static fn (Step $s): string => $s->name(), updateSteps(new FakeDriver));

    expect($names)->toBe(['update:core:wordpress', 'update:woocommerce:woocommerce', 'update:plugin:classic-editor', 'update:theme:storefront', 'translations']);
});

it('installs every component from its artifact and records each change with its versions', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;

    foreach (updateSteps($driver) as $step) {
        expect($step->run($context)->status)->toBe(RunStepStatus::Succeeded);
    }

    $installed = $driver->installedComponents();
    expect($installed->coreVersion)->toBe('6.8.4')
        ->and($installed->find(ComponentType::Woocommerce, 'woocommerce')?->version)->toBe('10.1.1')
        ->and($installed->find(ComponentType::Plugin, 'classic-editor')?->version)->toBe('1.6.4')
        ->and($installed->find(ComponentType::Theme, 'storefront')?->version)->toBe('4.6.1')
        ->and(updateRows($run))->toBe([
            ['core', 'wordpress', '6.8.3', '6.8.4', 'updated'],
            ['woocommerce', 'woocommerce', '10.1.0', '10.1.1', 'updated'],
            ['plugin', 'classic-editor', '1.6.3', '1.6.4', 'updated'],
            ['theme', 'storefront', '4.6.0', '4.6.1', 'updated'],
        ])
        // Every upload is gone (and Http is faked: nothing was downloaded).
        ->and($driver->uploadedPackages)->toHaveCount(4)
        ->and($driver->files)->toBe([]);
});

it('does nothing when repeated: the installed version is checked first', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $step = updateSteps($driver)[2];

    $first = $step->run($context);
    $second = $step->run($context);

    expect($first->status)->toBe(RunStepStatus::Succeeded)
        ->and($second->status)->toBe(RunStepStatus::Succeeded)
        ->and($second->summary)->toContain('ja era a la versió 1.6.4')
        ->and($second->data['already_installed'])->toBeTrue()
        ->and($driver->uploadedPackages)->toHaveCount(1)
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.4', 'updated']]);
});

it('fails when the site ends up with another version than the target', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $driver->installsVersion = '1.6.3-beta';

    $outcome = updateSteps($driver)[2]->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('classic-editor')
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.3-beta', 'failed']])
        ->and($driver->files)->toBe([]);
});

it('verifies the artifact right before the upload and refuses changed bytes', function (): void {
    [$run, $context, $artifacts] = updateRun();
    file_put_contents($artifacts['classic-editor']->path, 'tampered after the analysis');
    $driver = new FakeDriver;

    $outcome = updateSteps($driver)[2]->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($driver->writeCalls)->toBe([])
        ->and($driver->uploadedPackages)->toBe([])
        ->and($driver->installedComponents()->find(ComponentType::Plugin, 'classic-editor')?->version)->toBe('1.6.3')
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.3', 'failed']]);
});

it('removes the upload when the installer fails, and keeps secrets out of the record', function (): void {
    [$run, $context] = updateRun();
    app(SecretRedactor::class)->register('licence-s3cr3t-0001');
    $driver = new FakeDriver;
    $driver->installFailure = new RemoteCommandFailed('Error: could not unzip with key licence-s3cr3t-0001');

    $outcome = updateSteps($driver)[2]->run($context);
    $row = $run->changes()->sole();

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($driver->uploadedPackages)->toHaveCount(1)
        ->and($driver->files)->toBe([])
        ->and($outcome->summary)->not->toContain('licence-s3cr3t-0001')
        ->and($row->reason_detail)->toContain('could not unzip')
        ->and($row->reason_detail)->not->toContain('licence-s3cr3t-0001');
});

it('fails without touching the site when the fresh inventory does not offer the update', function (): void {
    [$run] = updateRun();
    $driver = new FakeDriver;

    $outcome = updateSteps($driver)[2]->run(new StepContext($run, false, []));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain("No hi ha l'actualització")
        ->and($driver->writeCalls)->toBe([]);
});

it('updates the translations, recording each one, and fails if one stays pending', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $driver->translationUpdates = [
        ['kind' => 'core', 'slug' => 'wordpress', 'language' => 'ca'],
        ['kind' => 'plugin', 'slug' => 'woocommerce', 'language' => 'ca'],
    ];
    $steps = updateSteps($driver);
    $translations = $steps[count($steps) - 1];

    $done = $translations->run($context);
    $again = $translations->run($context);

    expect($done->status)->toBe(RunStepStatus::Succeeded)
        ->and($done->summary)->toBe('2 traduccions actualitzades.')
        ->and($again->summary)->toBe('Cap traducció pendent.')
        ->and(updateRows($run))->toBe([
            ['translation', 'core:ca', null, null, 'updated'],
            ['translation', 'plugin:woocommerce:ca', null, null, 'updated'],
        ]);

    $driver->translationUpdates = [['kind' => 'theme', 'slug' => 'storefront', 'language' => 'ca']];
    $driver->translationsStuck = true;
    $stuck = $translations->run($context);

    expect($stuck->status)->toBe(RunStepStatus::Failed)
        ->and($stuck->summary)->toContain('theme:storefront:ca')
        ->and($run->changes()->where('slug', 'theme:storefront:ca')->value('result'))->toBe(ChangeResult::Failed);
});
