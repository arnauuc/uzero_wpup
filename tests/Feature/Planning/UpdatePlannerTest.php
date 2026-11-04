<?php

declare(strict_types=1);

use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Planning\VersionChange;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\SkipReason;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pending(string $type, string $slug, string $from, string $to, array $extra = []): array
{
    return $extra + ['type' => $type, 'slug' => $slug, 'current_version' => $from, 'new_version' => $to, 'requires_php' => null, 'requires_wp' => null, 'requires_wc' => null, 'licensed' => true];
}

/**
 * @param  array<mixed>  $updates
 * @param  array<string, mixed>  $site
 * @param  array<string, mixed>  $profile
 */
function planFor(array $updates, array $site = [], array $profile = [], string $php = '8.2.20', string $core = '6.8.1', string $woocommerce = '10.1.0', PhpVersionSource $source = PhpVersionSource::HttpProbe): UpdatePlan
{
    $siteModel = Site::factory()->create($site);
    $profileModel = $profile === [] ? null : SiteProfile::factory()->create(['site_id' => $siteModel->id] + $profile);
    $snapshot = SiteSnapshot::factory()->create([
        'site_id' => $siteModel->id,
        'core_version' => $core,
        'plugins' => [['slug' => 'woocommerce', 'version' => $woocommerce, 'active' => true, 'title' => null, 'type' => 'woocommerce']],
        'pending_updates' => $updates,
    ]);

    return app(UpdatePlanner::class)->plan($snapshot, $siteModel, $profileModel, $php, $source);
}

function changeOf(UpdatePlan $plan, string $slug): PlannedChange
{
    foreach ($plan->changes as $change) {
        if ($change->slug === $slug) {
            return $change;
        }
    }
    throw new LogicException("No change for {$slug}");
}

it('updates a compatible minor update', function (): void {
    $change = changeOf(planFor([pending('plugin', 'akismet', '5.3', '5.4')]), 'akismet');

    expect($change->action)->toBe(PlanAction::Update)
        ->and($change->reason)->toBeNull()
        ->and($change->currentVersion)->toBe('5.3')
        ->and($change->targetVersion)->toBe('5.4');
});

it('skips every update of a blocked site with the block reason', function (): void {
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.8.2'), pending('plugin', 'akismet', '5.3', '5.4')], ['blocked_reason' => 'PHP massa antic']);

    expect($plan->blockedReason)->toBe('PHP massa antic')
        ->and($plan->updateCount())->toBe(0);
    foreach ($plan->changes as $change) {
        expect($change->reason)->toBe(SkipReason::SiteBlocked)->and($change->detail)->toBe('PHP massa antic');
    }
});

it('skips excluded and frozen components, by slug or type:slug', function (): void {
    $plan = planFor(
        [pending('plugin', 'akismet', '5.3', '5.4'), pending('plugin', 'hello', '1.0', '1.1'), pending('theme', 'storefront', '4.6', '4.7'), pending('plugin', 'other', '1.0', '1.1')],
        [],
        ['frozen_components' => ['akismet', 'theme:storefront'], 'excluded_components' => ['plugin:hello']],
    );

    expect(changeOf($plan, 'akismet')->reason)->toBe(SkipReason::Frozen)
        ->and(changeOf($plan, 'storefront')->reason)->toBe(SkipReason::Frozen)
        ->and(changeOf($plan, 'hello')->reason)->toBe(SkipReason::Excluded)
        ->and(changeOf($plan, 'other')->action)->toBe(PlanAction::Update);
});

it('does not freeze a plugin because a theme has the same slug', function (): void {
    $plan = planFor([pending('plugin', 'storefront', '1.0', '1.1')], [], ['frozen_components' => ['theme:storefront']]);

    expect(changeOf($plan, 'storefront')->action)->toBe(PlanAction::Update);
});

it('lets excluded win over frozen', function (): void {
    $plan = planFor([pending('plugin', 'akismet', '5.3', '5.4')], [], ['frozen_components' => ['akismet'], 'excluded_components' => ['akismet']]);

    expect(changeOf($plan, 'akismet')->reason)->toBe(SkipReason::Excluded);
});

it('skips an unlicensed premium update', function (): void {
    $change = changeOf(planFor([pending('plugin', 'premium', '2.0', '2.1', ['licensed' => false])]), 'premium');

    expect($change->reason)->toBe(SkipReason::NoLicense);
});

it('skips an update that needs a newer PHP than the site has', function (): void {
    $plan = planFor([pending('plugin', 'modern', '1.0', '1.1', ['requires_php' => '8.3']), pending('plugin', 'fine', '1.0', '1.1', ['requires_php' => '8.2'])]);

    expect(changeOf($plan, 'modern')->reason)->toBe(SkipReason::PhpIncompatible)
        ->and(changeOf($plan, 'modern')->detail)->toContain('8.3')->toContain('8.2.20')
        ->and(changeOf($plan, 'fine')->action)->toBe(PlanAction::Update);
});

it('skips an update that needs a newer WordPress than the one the site will have', function (): void {
    $plan = planFor([pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9'])]);
    expect(changeOf($plan, 'p')->reason)->toBe(SkipReason::WpIncompatible);

    // The core patch update is planned first, so 6.8.2 is what the plugin sees.
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.8.2'), pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.8.2'])]);
    expect(changeOf($plan, 'p')->action)->toBe(PlanAction::Update);
});

it('does not count a skipped core as the target for WordPress requirements', function (): void {
    // 6.8.1 → 6.9.0 is a major change: skipped before F7, so the plugin cannot rely on it.
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.9.0'), pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9'])]);

    expect(changeOf($plan, 'wordpress')->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and(changeOf($plan, 'p')->reason)->toBe(SkipReason::WpIncompatible);
});

it('checks WooCommerce requirements against the version the site will have', function (): void {
    $plan = planFor([
        pending('woocommerce', 'woocommerce', '10.1.0', '10.2.0'),
        pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '10.2']),
        pending('plugin', 'newer', '1.0', '1.1', ['requires_wc' => '10.3']),
    ]);

    expect(changeOf($plan, 'ext')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'newer')->reason)->toBe(SkipReason::Dependency);
});

it('skips a WooCommerce extension when WooCommerce is not installed', function (): void {
    $siteModel = Site::factory()->create();
    $snapshot = SiteSnapshot::factory()->create(['site_id' => $siteModel->id, 'plugins' => [], 'pending_updates' => [pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '9.0'])]]);

    $plan = app(UpdatePlanner::class)->plan($snapshot, $siteModel, null, '8.2.20', PhpVersionSource::HttpProbe);

    expect($plan->changes[0]->reason)->toBe(SkipReason::Dependency)->and($plan->changes[0]->detail)->toContain('no està instal·lat');
});

it('skips major updates (ADR-0005) but not minors', function (): void {
    $plan = planFor([
        pending('core', 'wordpress', '6.8.1', '6.8.3'),
        pending('woocommerce', 'woocommerce', '10.1.0', '10.4.0'),
        pending('plugin', 'big', '2.9', '3.0'),
        pending('theme', 'storefront', '4.6.0', '5.0.0'),
    ]);

    expect(changeOf($plan, 'wordpress')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'woocommerce')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'big')->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and(changeOf($plan, 'storefront')->reason)->toBe(SkipReason::MajorRequiresStaging);
});

it('applies the first matching rule only', function (): void {
    // Frozen and PHP-incompatible and major: reported as frozen.
    $plan = planFor([pending('plugin', 'p', '1.0', '2.0', ['requires_php' => '9.0'])], [], ['frozen_components' => ['p']]);

    expect(changeOf($plan, 'p')->reason)->toBe(SkipReason::Frozen);
});

it('orders core, WooCommerce, plugins, themes', function (): void {
    $plan = planFor([
        pending('theme', 'storefront', '4.6.0', '4.7.0'),
        pending('plugin', 'zeta', '1.0', '1.1'),
        pending('plugin', 'alpha', '1.0', '1.1'),
        pending('woocommerce', 'woocommerce', '10.1.0', '10.2.0'),
        pending('core', 'wordpress', '6.8.1', '6.8.2'),
    ]);

    expect(array_map(static fn (PlannedChange $c): string => $c->slug, $plan->changes))->toBe(['wordpress', 'woocommerce', 'alpha', 'zeta', 'storefront']);
});

it('ignores malformed pending entries and plans nothing without updates', function (): void {
    $plan = planFor(['junk', ['type' => 'plugin'], ['type' => 'nope', 'slug' => 'x', 'current_version' => '1', 'new_version' => '2']]);

    expect($plan->changes)->toBe([])->and($plan->summary())->toBe("0 s'actualitzarien, 0 s'ometrien");
});

it('reports whether the PHP was confirmed by the web', function (): void {
    expect(planFor([], source: PhpVersionSource::Cli)->phpVerified)->toBeFalse()
        ->and(planFor([], source: PhpVersionSource::HttpProbe)->phpVerified)->toBeTrue();
});

it('round-trips through its array form', function (): void {
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.8.2'), pending('plugin', 'p', '1.0', '1.1', ['licensed' => false])], ['blocked_reason' => null]);

    expect(UpdatePlan::fromArray($plan->toArray())->toArray())->toBe($plan->toArray());
    expect(fn () => UpdatePlan::fromArray(['php' => []]))->toThrow(UnexpectedValueException::class);
});

it('classifies major versions per component type', function (string $type, string $from, string $to, bool $major): void {
    expect(VersionChange::isMajor(ComponentType::from($type), $from, $to))->toBe($major);
})->with([
    ['core', '6.8.1', '6.8.2', false],
    ['core', '6.8.1', '6.9.0', true],
    ['core', '6.9', '7.0', true],
    ['woocommerce', '9.9.1', '10.0.0', true],
    ['woocommerce', '10.1.0', '10.2.0', false],
    ['plugin', '2.9.1', '3.0.0', true],
    ['plugin', '2.1', '2.9', false],
    ['theme', '1.0', 'dev-main', true],
]);
