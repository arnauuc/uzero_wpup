<?php

declare(strict_types=1);

use App\Domain\Inventory\PendingUpdate;
use App\Domain\Planning\PackageVersionKey;
use App\Domain\Planning\PackageVersions;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanOptions;
use App\Domain\Planning\QuarantineBypass;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Vulnerabilities\KnownVulnerability;
use App\Domain\Vulnerabilities\VulnerabilitySource;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\SkipReason;
use App\Models\PackageVersion;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// ADR-0005 in the planner (UZEROWPUP-127): quarantine, its exceptions, majors and the overrides.

beforeEach(function (): void {
    config(['wpup.planning.quarantine_days' => 7]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function offer(string $type, string $slug, string $from, string $to, array $extra = []): array
{
    return $extra + ['type' => $type, 'slug' => $slug, 'current_version' => $from, 'new_version' => $to, 'requires_php' => null, 'requires_wp' => null, 'requires_wc' => null, 'requires_plugins' => [], 'licensed' => true, 'source' => 'wordpress.org'];
}

/** $source defaults to the one of a WordPress.org package with that slug (see PackageVersionKey). */
function seen(string $type, string $slug, string $version, int $daysAgo, ?int $releasedDaysAgo = null, ?string $source = null): void
{
    PackageVersion::query()->create([
        'component_type' => $type, 'slug' => $slug, 'version' => $version,
        'source' => $source ?? ($type === 'core' ? 'wordpress.org:wordpress' : "wordpress.org:{$slug}"),
        'first_seen_at' => now()->subDays($daysAgo), 'last_seen_at' => now()->subDays($daysAgo),
        'released_at' => $releasedDaysAgo === null ? null : now()->subDays($releasedDaysAgo),
    ]);
}

/**
 * @param  list<array<string, mixed>>  $offers
 */
function policyPlan(array $offers, PlanOptions $options = new PlanOptions): UpdatePlan
{
    $site = Site::factory()->create();
    $snapshot = SiteSnapshot::factory()->create([
        'site_id' => $site->id,
        'core_version' => '6.8.1',
        'plugins' => [['slug' => 'woocommerce', 'version' => '10.1.0', 'active' => true, 'title' => null, 'type' => 'woocommerce']],
        'environment' => ['prerequisites' => []],
        'pending_updates' => $offers,
    ]);

    return app(UpdatePlanner::class)->plan($snapshot, $site, null, '8.3.10', PhpVersionSource::HttpProbe, $options);
}

function policyChange(UpdatePlan $plan, string $slug): PlannedChange
{
    foreach ($plan->changes as $change) {
        if ($change->slug === $slug) {
            return $change;
        }
    }
    throw new LogicException("No change for {$slug}");
}

/**
 * @param  array<string, list<KnownVulnerability>>  $bySlug
 */
function knownVulnerabilities(array $bySlug): void
{
    app()->instance(VulnerabilitySource::class, new readonly class($bySlug) implements VulnerabilitySource
    {
        /** @param array<string, list<KnownVulnerability>> $bySlug */
        public function __construct(private array $bySlug) {}

        public function affecting(ComponentType $type, string $slug, string $installedVersion): array
        {
            return $this->bySlug[$slug] ?? [];
        }
    });
}

it('skips a version in quarantine with the days left', function (): void {
    seen('plugin', 'akismet', '5.4', 2);

    $change = policyChange(policyPlan([offer('plugin', 'akismet', '5.3', '5.4')]), 'akismet');

    expect($change->action)->toBe(PlanAction::Skip)
        ->and($change->reason)->toBe(SkipReason::Quarantine)
        ->and($change->quarantineDaysLeft)->toBe(5)
        ->and($change->detail)->toBe('La versió 5.4 existeix des del 04/11/2026: falten 5 dies de quarantena (de 7 dies).');
});

it('applies a version once the quarantine is over, and honours quarantine_days', function (): void {
    seen('plugin', 'akismet', '5.4', 7);
    seen('plugin', 'hello', '1.7', 3);

    $plan = policyPlan([offer('plugin', 'akismet', '5.3', '5.4'), offer('plugin', 'hello', '1.6', '1.7')]);
    expect(policyChange($plan, 'akismet')->action)->toBe(PlanAction::Update)
        ->and(policyChange($plan, 'akismet')->bypass)->toBeNull()
        ->and(policyChange($plan, 'hello')->reason)->toBe(SkipReason::Quarantine);

    config(['wpup.planning.quarantine_days' => 3]);
    expect(policyChange(policyPlan([offer('plugin', 'hello', '1.6', '1.7')]), 'hello')->action)->toBe(PlanAction::Update);
});

it('makes a version never seen wait the whole quarantine', function (): void {
    $change = policyChange(policyPlan([offer('plugin', 'akismet', '5.3', '5.4')]), 'akismet');

    expect($change->quarantineDaysLeft)->toBe(7)
        ->and($change->detail)->toContain("La versió 5.4 no s'havia vist fins ara: falten 7 dies");
});

it('counts from the WordPress.org date when it is earlier than the first sighting', function (): void {
    seen('theme', 'storefront', '4.7.0', 1, releasedDaysAgo: 9);
    seen('plugin', 'hello', '1.7', 1, releasedDaysAgo: 0);

    $plan = policyPlan([offer('theme', 'storefront', '4.6.0', '4.7.0'), offer('plugin', 'hello', '1.6', '1.7')]);

    expect(policyChange($plan, 'storefront')->action)->toBe(PlanAction::Update)
        // A later WordPress.org date (a readme edit) never extends it past the first sighting.
        ->and(policyChange($plan, 'hello')->quarantineDaysLeft)->toBe(6);
});

it('follows the corrective version: its own counter, not the first release', function (): void {
    seen('plugin', 'big', '2.1.0', 6);
    seen('plugin', 'big', '2.1.1', 1);

    $change = policyChange(policyPlan([offer('plugin', 'big', '2.0.3', '2.1.1')]), 'big');

    expect($change->reason)->toBe(SkipReason::Quarantine)->and($change->quarantineDaysLeft)->toBe(6);
});

it('never holds core maintenance releases, but holds a new core branch', function (): void {
    seen('core', 'wordpress', '6.8.3', 0);
    $minor = policyChange(policyPlan([offer('core', 'wordpress', '6.8.1', '6.8.3')]), 'wordpress');

    expect($minor->action)->toBe(PlanAction::Update)->and($minor->bypass)->toBe(QuarantineBypass::CoreMaintenance)->and($minor->detail)->toBeNull();

    seen('core', 'wordpress', '6.9.0', 0);
    expect(policyChange(policyPlan([offer('core', 'wordpress', '6.8.1', '6.9.0')]), 'wordpress')->reason)->toBe(SkipReason::Quarantine);
});

it('holds a WooCommerce monthly release in quarantine and names it to its extensions', function (): void {
    seen('woocommerce', 'woocommerce', '10.2.0', 1);
    seen('plugin', 'ext', '1.1', 30);

    $plan = policyPlan([offer('woocommerce', 'woocommerce', '10.1.0', '10.2.0'), offer('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '10.2'])]);

    expect(policyChange($plan, 'woocommerce')->reason)->toBe(SkipReason::Quarantine)
        ->and(policyChange($plan, 'ext')->detail)->toBe("Requereix WooCommerce 10.2 i el lloc tindria 10.1.0. L'actualització de WooCommerce a 10.2.0 s'omet: en quarantena.");
});

it('skips majors pending staging and marks the plan as needing staging', function (): void {
    seen('plugin', 'big', '3.0.0', 30);
    seen('plugin', 'akismet', '5.4', 30);

    $plan = policyPlan([offer('plugin', 'big', '2.9', '3.0.0'), offer('plugin', 'akismet', '5.3', '5.4')]);

    expect(policyChange($plan, 'big')->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and(policyChange($plan, 'big')->major)->toBeTrue()
        ->and(policyChange($plan, 'akismet')->major)->toBeFalse()
        ->and($plan->requiresStaging())->toBeTrue()
        ->and($plan->appliedMajors())->toBe([])
        ->and($plan->toArray()['requires_staging'])->toBeTrue();

    expect(policyPlan([offer('plugin', 'akismet', '5.3', '5.4')])->requiresStaging())->toBeFalse();
});

it('applies majors in place with --allow-major, flagged for review', function (): void {
    seen('plugin', 'big', '3.0.0', 30);

    $plan = policyPlan([offer('plugin', 'big', '2.9', '3.0.0')], new PlanOptions(allowMajor: true));
    $change = policyChange($plan, 'big');

    expect($change->action)->toBe(PlanAction::Update)
        ->and($change->major)->toBeTrue()
        ->and($change->detail)->toContain('--allow-major')
        ->and($plan->requiresStaging())->toBeTrue()
        ->and($plan->appliedMajors())->toBe([$change])
        ->and($plan->toArray()['overrides'])->toBe(['allow_major' => true, 'skip_quarantine' => false]);
});

it('keeps the quarantine for majors with --allow-major', function (): void {
    seen('plugin', 'big', '3.0.0', 1);

    expect(policyChange(policyPlan([offer('plugin', 'big', '2.9', '3.0.0')], new PlanOptions(allowMajor: true)), 'big')->reason)->toBe(SkipReason::Quarantine);
});

it('applies versions in quarantine with --skip-quarantine, marked', function (): void {
    seen('plugin', 'akismet', '5.4', 2);
    seen('plugin', 'hello', '1.7', 30);

    $plan = policyPlan([offer('plugin', 'akismet', '5.3', '5.4'), offer('plugin', 'hello', '1.6', '1.7')], new PlanOptions(skipQuarantine: true));

    expect(policyChange($plan, 'akismet')->action)->toBe(PlanAction::Update)
        ->and(policyChange($plan, 'akismet')->bypass)->toBe(QuarantineBypass::Override)
        ->and(policyChange($plan, 'akismet')->detail)->toBe("En quarantena (5 dies més), però s'aplica amb --skip-quarantine.")
        // Only the ones it actually let through are marked.
        ->and(policyChange($plan, 'hello')->bypass)->toBeNull();
});

it('applies the first version that fixes a known vulnerability without quarantine', function (): void {
    knownVulnerabilities([
        'forms' => [new KnownVulnerability('CVE-2026-0001', '1.2.1'), new KnownVulnerability('CVE-2026-0002', '1.3'), new KnownVulnerability('CVE-2026-0003', null)],
        'premium' => [new KnownVulnerability('WF-1', '4.1')],
        'later' => [new KnownVulnerability('WF-2', '2.5')],
        'old' => [new KnownVulnerability('WF-3', '0.9')],
    ]);

    $plan = policyPlan([
        offer('plugin', 'forms', '1.2', '1.5'),
        offer('plugin', 'premium', '4.0', '4.3', ['source' => 'transient']),
        offer('plugin', 'later', '2.0', '2.4'),
        offer('plugin', 'old', '1.0', '1.1'),
    ]);

    $forms = policyChange($plan, 'forms');
    expect($forms->action)->toBe(PlanAction::Update)
        ->and($forms->targetVersion)->toBe('1.3')
        ->and($forms->bypass)->toBe(QuarantineBypass::Vulnerability)
        ->and($forms->detail)->toBe("Excepció de seguretat: la versió instal·lada té vulnerabilitats conegudes (CVE-2026-0001, CVE-2026-0002); s'aplica 1.3, la primera que les corregeix, sense quarantena.")
        // A premium package only offers its latest version.
        ->and(policyChange($plan, 'premium')->targetVersion)->toBe('4.3')
        ->and(policyChange($plan, 'premium')->action)->toBe(PlanAction::Update)
        // Not fixed by the offered version, or already fixed in the installed one: the normal quarantine.
        ->and(policyChange($plan, 'later')->reason)->toBe(SkipReason::Quarantine)
        ->and(policyChange($plan, 'old')->reason)->toBe(SkipReason::Quarantine);
});

it('keeps the staging rules for a security fix that is a major', function (): void {
    knownVulnerabilities(['big' => [new KnownVulnerability('CVE-2026-0009', '3.0.1')]]);

    $change = policyChange(policyPlan([offer('plugin', 'big', '2.9', '3.1.0')]), 'big');

    expect($change->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and($change->targetVersion)->toBe('3.0.1')
        ->and($change->bypass)->toBe(QuarantineBypass::Vulnerability);
});

it('round-trips the policy fields and reads plans stored before them', function (): void {
    seen('plugin', 'akismet', '5.4', 2);
    $plan = policyPlan([offer('plugin', 'akismet', '5.3', '5.4'), offer('plugin', 'big', '2.9', '3.0')], new PlanOptions(true, true));

    expect(UpdatePlan::fromArray($plan->toArray())->toArray())->toBe($plan->toArray());

    $old = UpdatePlan::fromArray(['php' => ['version' => '8.2.1', 'source' => 'cli'], 'changes' => [
        ['type' => 'plugin', 'slug' => 'a', 'current_version' => '1', 'target_version' => '2', 'action' => 'skip', 'reason' => 'major_requires_staging', 'detail' => null],
    ]]);
    // No stored major flag: recomputed from the versions (1 → 2 is major).
    expect($old->changes[0]->major)->toBeTrue()->and($old->options->any())->toBeFalse();
    expect(fn () => UpdatePlan::fromArray(['php' => ['version' => '8.2.1', 'source' => 'cli'], 'changes' => [
        ['type' => 'plugin', 'slug' => 'a', 'current_version' => '1', 'target_version' => '2', 'action' => 'update', 'quarantine_bypass' => 'nope'],
    ]]))->toThrow(UnexpectedValueException::class);
});

it('records first sightings idempotently and never moves them forward', function (): void {
    $versions = app(PackageVersions::class);
    $update = static fn (?string $released): PendingUpdate => new PendingUpdate('plugin', 'akismet', 'akismet', '5.3', '5.4', null, null, null, null, 'wordpress.org', true, [], false, $released);
    $row = static fn (): PackageVersion => PackageVersion::query()->where('slug', 'akismet')->sole();

    $versions->record([$update(null), $update(null)], CarbonImmutable::now()->subDays(3));
    $versions->record([$update('2026-11-05T10:00:00+00:00')], CarbonImmutable::now());
    expect($row()->first_seen_at->utc()->toIso8601String())->toBe('2026-11-03T12:00:00+00:00')
        ->and($row()->released_at?->utc()->toIso8601String())->toBe('2026-11-05T10:00:00+00:00');

    // An earlier sighting or date wins; a later one never replaces it.
    $versions->record([$update('2026-11-01T08:00:00+00:00')], CarbonImmutable::now()->subDays(5));
    $versions->record([$update('2026-11-06T08:00:00+00:00')], CarbonImmutable::now());
    expect(PackageVersion::query()->count())->toBe(1)
        ->and($row()->first_seen_at->utc()->toIso8601String())->toBe('2026-11-01T12:00:00+00:00')
        ->and($row()->released_at?->utc()->toIso8601String())->toBe('2026-11-01T08:00:00+00:00')
        ->and($row()->last_seen_at->utc()->toIso8601String())->toBe('2026-11-06T12:00:00+00:00');
});

it('keeps a separate clock for unrelated packages that share a folder name', function (): void {
    // Two premium "forms" plugins from different vendors, and the WordPress.org one.
    seen('plugin', 'forms', '2.1', 30, source: 'host:vendor-a.test');
    seen('plugin', 'forms', '2.1', 1, source: 'host:vendor-b.test');

    $plan = policyPlan([offer('plugin', 'forms', '2.0', '2.1', ['source' => 'transient', 'package_host' => 'vendor-b.test'])]);
    expect(policyChange($plan, 'forms')->quarantineDaysLeft)->toBe(6);

    $plan = policyPlan([offer('plugin', 'forms', '2.0', '2.1', ['source' => 'transient', 'package_host' => 'vendor-a.test'])]);
    expect(policyChange($plan, 'forms')->action)->toBe(PlanAction::Update);

    // The WordPress.org one was never seen: it waits the whole period.
    expect(policyChange(policyPlan([offer('plugin', 'forms', '2.0', '2.1')]), 'forms')->quarantineDaysLeft)->toBe(7);
});

it('reads only the asked versions', function (): void {
    seen('plugin', 'akismet', '5.4', 30);
    seen('plugin', 'akismet', '5.3', 60);
    seen('plugin', 'akismet', '5.4', 60, source: 'host:other.test');

    $since = app(PackageVersions::class)->knownSince([new PackageVersionKey(ComponentType::Plugin, 'akismet', 'wordpress.org:akismet', '5.4')]);

    expect(array_keys($since))->toBe([(new PackageVersionKey(ComponentType::Plugin, 'akismet', 'wordpress.org:akismet', '5.4'))->id()]);
});

it('purges the versions no inventory has offered for the retention period', function (): void {
    config(['wpup.planning.package_versions_retention_days' => 30]);
    seen('plugin', 'old', '1.0', 31);
    seen('plugin', 'recent', '1.0', 29);

    expect(app(PackageVersions::class)->purge(CarbonImmutable::now()))->toBe(1)
        ->and(PackageVersion::query()->pluck('slug')->all())->toBe(['recent']);
});
