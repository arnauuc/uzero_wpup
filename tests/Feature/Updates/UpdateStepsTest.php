<?php

declare(strict_types=1);

use App\Domain\Artifacts\Artifact;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Maintenance\MaintenanceGuard;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Runs\Exceptions\StepTimedOut;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\ComponentUpdateStep;
use App\Domain\Updates\PostUpdateSettings;
use App\Domain\Updates\PostUpdateStep;
use App\Domain\Updates\UpdateChanges;
use App\Domain\Updates\UpdateSteps;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStepStatus;
use App\Enums\SkipReason;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
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
 * The fake site's updates (and a skip), in the plan's order.
 *
 * @return list<PlannedChange>
 */
function fakeSiteChanges(): array
{
    return [
        new PlannedChange(ComponentType::Core, 'wordpress', '6.8.3', '6.8.4', PlanAction::Update),
        new PlannedChange(ComponentType::Woocommerce, 'woocommerce', '10.1.0', '10.1.1', PlanAction::Update),
        new PlannedChange(ComponentType::Plugin, 'classic-editor', '1.6.3', '1.6.4', PlanAction::Update),
        new PlannedChange(ComponentType::Plugin, 'hello', '1.6', '2.0', PlanAction::Skip, SkipReason::MajorRequiresStaging),
        new PlannedChange(ComponentType::Theme, 'storefront', '4.6.0', '4.6.1', PlanAction::Update),
    ];
}

/**
 * A run holding the analysed artifact of every planned update.
 *
 * @return array{Run, StepContext, array<string, Artifact>}
 */
function updateRun(): array
{
    $run = Run::factory()->create(['site_id' => Site::factory()]);
    $artifacts = [];
    foreach (fakeSiteChanges() as $change) {
        $artifacts[$change->slug] = updateArtifact($run, $change->type, $change->slug, $change->targetVersion);
    }

    return [$run, new StepContext($run, false), $artifacts];
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

function updateBuilder(FakeDriver $driver, int $timeout = 60, int $minimum = 60): UpdateSteps
{
    return new UpdateSteps(
        app(ArtifactStore::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class),
        new PostUpdateSettings(6600, 1800, 120, 900, []), $timeout, $minimum,
    );
}

/**
 * @return list<ComponentUpdateStep|PostUpdateStep>
 */
function updateSteps(FakeDriver $driver): array
{
    return updateBuilder($driver)->for(new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, fakeSiteChanges()));
}

/** The classic-editor step. */
function classicStep(FakeDriver $driver): Step
{
    return updateSteps($driver)[2];
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

it('builds one step per planned update, in the plan order', function (): void {
    $names = array_map(static fn (Step $s): string => $s->name(), updateSteps(new FakeDriver));

    expect($names)->toBe(['update:core:wordpress', 'update:woocommerce:woocommerce', 'update:plugin:classic-editor', 'update:theme:storefront', 'post-update']);
});

it('refuses a step timeout shorter than the longest install', function (): void {
    expect(fn () => updateBuilder(new FakeDriver, 600, 3615))->toThrow(InvalidArgumentException::class, '3615');
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
        ->and($driver->files)->toBe([])
        // One light read before in the step, and one before and after in the driver.
        ->and($driver->stateReads)->toHaveCount(12);
});

it('does nothing when repeated: the installed version is checked first', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $step = classicStep($driver);

    $first = $step->run($context);
    $second = $step->run($context);

    expect($first->status)->toBe(RunStepStatus::Succeeded)
        ->and($second->status)->toBe(RunStepStatus::Succeeded)
        ->and($second->summary)->toContain('ja era a la versió 1.6.4')
        ->and($second->data['already_installed'])->toBeTrue()
        ->and($driver->uploadedPackages)->toHaveCount(1)
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.4', 'updated']]);
});

it('does not trust a target version this run never finished installing: it fails, to roll back', function (): void {
    // UZEROWPUP-62: the worker died during the install; the version header may be new and other files old.
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', '1.6.4'));

    $outcome = classicStep($driver)->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain("aquesta execució no n'havia acabat la instal·lació")
        ->and($driver->writeCalls)->toBe([])
        // From the plan's version: the files rollback puts that one back.
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.4', 'failed']]);
});

it('never acts on a site that changed since the plan', function (?string $installed): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    unset($driver->components['plugin:classic-editor']);
    if ($installed !== null) {
        $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', $installed));
    }

    $outcome = classicStep($driver)->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('cal tornar a planificar')
        ->and($driver->writeCalls)->toBe([])
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', $installed ?? '1.6.3', $installed, 'failed']]);
})->with(['newer' => '1.7.0', 'older' => '1.5.0', 'removed' => null]);

it('fails without uploading when the run holds no analysed artifact', function (): void {
    $run = Run::factory()->create(['site_id' => Site::factory()]);
    $driver = new FakeDriver;

    $outcome = classicStep($driver)->run(new StepContext($run, false));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('cap artefacte analitzat per a plugin «classic-editor» 1.6.4 en aquesta execució')
        ->and($driver->writeCalls)->toBe([]);
});

it('fails when the site ends up with another version than the target', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $driver->installsVersion = '1.6.3-beta';

    $outcome = classicStep($driver)->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('classic-editor')
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.3-beta', 'failed']])
        ->and($driver->files)->toBe([]);
});

it('verifies the artifact right before the upload and refuses changed bytes', function (): void {
    [$run, $context, $artifacts] = updateRun();
    file_put_contents($artifacts['classic-editor']->path, 'tampered after the analysis');
    $driver = new FakeDriver;

    $outcome = classicStep($driver)->run($context);

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($driver->writeCalls)->toBe([])
        ->and($driver->uploadedPackages)->toBe([])
        ->and($driver->installedState(ComponentType::Plugin, 'classic-editor')?->version)->toBe('1.6.3')
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', '1.6.3', 'failed']]);
});

it('removes the upload when the installer fails, and keeps secrets out of the record', function (): void {
    [$run, $context] = updateRun();
    app(SecretRedactor::class)->register('licence-s3cr3t-0001');
    $driver = new FakeDriver;
    $driver->installFailure = new RemoteCommandFailed('Error: could not unzip with key licence-s3cr3t-0001');

    $outcome = classicStep($driver)->run($context);
    $row = $run->changes()->sole();

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($driver->uploadedPackages)->toHaveCount(1)
        ->and($driver->files)->toBe([])
        ->and($outcome->summary)->not->toContain('licence-s3cr3t-0001')
        ->and($row->reason_detail)->toContain('could not unzip')
        ->and($row->reason_detail)->not->toContain('licence-s3cr3t-0001');
});

it('records the change as failed on a timeout or an unexpected error, and lets it through', function (): void {
    [$run, $context] = updateRun();
    $driver = new FakeDriver;
    $driver->installFailure = new StepTimedOut('step timed out');

    expect(fn () => classicStep($driver)->run($context))->toThrow(StepTimedOut::class)
        ->and(updateRows($run))->toBe([['plugin', 'classic-editor', '1.6.3', null, 'failed']])
        ->and($driver->files)->toBe([]);
});

function maintenanceUpdateGuard(FakeDriver $driver, int $commandTimeout = 60): MaintenanceGuard
{
    return new MaintenanceGuard(
        static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), app(SecretRedactor::class), true, $commandTimeout,
    );
}

it('re-asserts the maintenance file before each update, also after a core update deleted it', function (): void {
    $driver = new FakeDriver;
    $driver->maintenance = true;
    [$run, $context] = updateRun();
    $guard = maintenanceUpdateGuard($driver);
    $guard->remember($run, false);
    $steps = (new UpdateSteps(
        app(ArtifactStore::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class),
        new PostUpdateSettings(6600, 1800, 120, 900, []), 120, 60, $guard,
    ))->for(new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, fakeSiteChanges()));

    // core, WooCommerce, classic-editor, storefront, post-update
    $steps[0]->run($context);
    expect($driver->maintenance)->toBeFalse(); // WordPress deleted .maintenance
    $steps[1]->run($context);
    expect($driver->maintenance)->toBeTrue();

    $driver->maintenanceExpired = true; // older than 10 minutes: WP-CLI says inactive
    $steps[2]->run($context);
    expect($driver->maintenanceExpired)->toBeFalse()->and($driver->maintenance)->toBeTrue();

    $driver->maintenance = false;
    $steps[4]->run($context);
    expect($driver->maintenance)->toBeTrue();
});

it('never writes a maintenance file the run does not own', function (): void {
    $driver = new FakeDriver;
    [$run, $context] = updateRun();
    $steps = (new UpdateSteps(
        app(ArtifactStore::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class),
        new PostUpdateSettings(6600, 1800, 120, 900, []), 120, 60, maintenanceUpdateGuard($driver),
    ))->for(new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, fakeSiteChanges()));

    $steps[2]->run($context);

    expect($driver->maintenance)->toBeFalse();
});

it('counts the maintenance refresh in the step budgets', function (): void {
    $driver = new FakeDriver;
    $build = static fn (int $component, int $post) => new UpdateSteps(
        app(ArtifactStore::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class),
        new PostUpdateSettings($post, 1800, 120, 900, [], 60), $component, 60, maintenanceUpdateGuard($driver),
    );

    expect(fn () => $build(100, 5100))->toThrow(InvalidArgumentException::class, 'refresc')
        ->and(fn () => $build(120, 5099))->toThrow(InvalidArgumentException::class)
        ->and($build(120, 5100))->toBeInstanceOf(UpdateSteps::class);
});
