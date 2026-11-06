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
    return $extra + ['type' => $type, 'slug' => $slug, 'current_version' => $from, 'new_version' => $to, 'requires_php' => null, 'requires_wp' => null, 'requires_wc' => null, 'requires_plugins' => [], 'licensed' => true];
}

/**
 * @param  array<mixed>  $updates
 * @param  array<string, mixed>  $site
 * @param  array<string, mixed>  $profile
 * @param  list<array<string, mixed>>  $prerequisites
 * @param  array<string, bool>  $plugins  other installed plugins: slug => active
 */
function planFor(array $updates, array $site = [], array $profile = [], string $php = '8.2.20', string $core = '6.8.1', string $woocommerce = '10.1.0', PhpVersionSource $source = PhpVersionSource::HttpProbe, bool $wcActive = true, array $prerequisites = [], array $plugins = []): UpdatePlan
{
    $siteModel = Site::factory()->create($site);
    $profileModel = $profile === [] ? null : SiteProfile::factory()->create(['site_id' => $siteModel->id] + $profile);
    $snapshot = SiteSnapshot::factory()->create([
        'site_id' => $siteModel->id,
        'core_version' => $core,
        'plugins' => [
            ['slug' => 'woocommerce', 'version' => $woocommerce, 'active' => $wcActive, 'title' => null, 'type' => 'woocommerce'],
            ...array_map(static fn (string $slug, bool $active): array => ['slug' => $slug, 'version' => '1.0', 'active' => $active, 'title' => null, 'type' => 'plugin'], array_keys($plugins), $plugins),
        ],
        'environment' => ['prerequisites' => $prerequisites],
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
    ['core', '6.9', '6.9.1', false],
    ['core', '7', '7.0.1', false],
    ['core', '6.9.4', '6.9-RC1', false],
    ['woocommerce', '10.9.0', '10.10.0', false],
    ['plugin', 'v2.1', '2.2', false],
    ['plugin', '2.1', 'v3.0', true],
]);

it('matches WooCommerce as a plugin, as woocommerce and by slug', function (string $entry): void {
    $plan = planFor([pending('woocommerce', 'woocommerce', '10.1.0', '10.2.0')], [], ['frozen_components' => [$entry]]);

    expect(changeOf($plan, 'woocommerce')->reason)->toBe(SkipReason::Frozen);
})->with(['woocommerce', 'plugin:woocommerce', 'woocommerce:woocommerce']);

it('does not match a plugin named like a WooCommerce type entry', function (): void {
    $plan = planFor([pending('plugin', 'akismet', '5.3', '5.4')], [], ['frozen_components' => ['woocommerce:akismet']]);

    expect(changeOf($plan, 'akismet')->action)->toBe(PlanAction::Update);
});

it('plans a single core row: the highest non-major offer, by version and not by string', function (): void {
    $plan = planFor([
        pending('core', 'wordpress', '6.8.1', '6.8.9'),
        pending('core', 'wordpress', '6.8.1', '6.8.10'),
        pending('core', 'wordpress', '6.8.1', '6.9.0'),
    ]);

    expect($plan->changes)->toHaveCount(1)
        ->and($plan->changes[0]->targetVersion)->toBe('6.8.10')
        ->and($plan->changes[0]->action)->toBe(PlanAction::Update);
});

it('plans the lowest major core offer, skipped as major, when all are major', function (): void {
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '7.0.0'), pending('core', 'wordpress', '6.8.1', '6.9.2'), pending('core', 'wordpress', '6.8.1', '6.10.0')]);

    expect($plan->changes)->toHaveCount(1)
        ->and($plan->changes[0]->targetVersion)->toBe('6.9.2')
        ->and($plan->changes[0]->reason)->toBe(SkipReason::MajorRequiresStaging);
});

it('treats an inactive WooCommerce as not installed for requires_wc', function (): void {
    $plan = planFor([pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '9.0'])], wcActive: false);

    expect(changeOf($plan, 'ext')->reason)->toBe(SkipReason::Dependency)->and(changeOf($plan, 'ext')->detail)->toContain('no està instal·lat');
});

it('skips everything when a prerequisite that needs no probe fails, as a real run would block', function (string $name): void {
    $plan = planFor([pending('plugin', 'akismet', '5.3', '5.4')], prerequisites: [['name' => $name, 'passed' => false, 'reason' => 'motiu de prova', 'value' => null]]);

    expect($plan->blockedReason)->toBe('es bloquejaria: motiu de prova')
        ->and(changeOf($plan, 'akismet')->reason)->toBe(SkipReason::SiteBlocked)
        ->and(changeOf($plan, 'akismet')->detail)->toBe('es bloquejaria: motiu de prova');
})->with(['backup_space', 'writable', 'wp_cli', 'php_minimum']);

it('ignores php_verified, and php_minimum when the PHP is not web-verified', function (): void {
    $failing = static fn (string $name): array => [['name' => $name, 'passed' => false, 'reason' => 'x', 'value' => null]];

    expect(planFor([pending('plugin', 'a', '1.0', '1.1')], prerequisites: $failing('php_verified'))->blockedReason)->toBeNull();

    $site = Site::factory()->create();
    $snapshot = SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_source' => PhpVersionSource::Cli, 'pending_updates' => [], 'plugins' => [], 'environment' => ['prerequisites' => $failing('php_minimum')]]);
    expect(app(UpdatePlanner::class)->plan($snapshot, $site, null, '8.2.0', PhpVersionSource::Cli)->blockedReason)->toBeNull();
});

it('keeps an existing block over the one a run would apply', function (): void {
    $plan = planFor([pending('plugin', 'a', '1.0', '1.1')], ['blocked_reason' => 'Bloqueig manual'], prerequisites: [['name' => 'writable', 'passed' => false, 'reason' => 'x', 'value' => null]]);

    expect($plan->blockedReason)->toBe('Bloqueig manual');
});

it('refuses unknown values when reading a stored plan', function (array $mutation): void {
    $data = planFor([pending('plugin', 'a', '1.0', '1.1')])->toArray();
    $data = array_replace_recursive($data, $mutation);

    expect(fn () => UpdatePlan::fromArray($data))->toThrow(UnexpectedValueException::class);
})->with([
    'php source' => [['php' => ['source' => 'nope']]],
    'type' => [['changes' => [['type' => 'nope']]]],
    'action' => [['changes' => [['action' => 'nope']]]],
    'reason' => [['changes' => [['reason' => 'nope']]]],
]);

it('compares requirements as WordPress versions, not as strings', function (): void {
    // version_compare() says 6.8 < 6.8.0 and a string comparison says 6.10 < 6.9.
    $plan = planFor([
        pending('plugin', 'same', '1.0', '1.1', ['requires_wp' => '6.8.0']),
        pending('plugin', 'php', '1.0', '1.1', ['requires_php' => '8.2.20.0']),
    ], core: '6.8');
    expect(changeOf($plan, 'same')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'php')->action)->toBe(PlanAction::Update);

    $plan = planFor([pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9'])], core: '6.10.1');
    expect(changeOf($plan, 'p')->action)->toBe(PlanAction::Update);

    // A release candidate does not meet the release.
    $plan = planFor([pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9'])], core: '6.9-RC2');
    expect(changeOf($plan, 'p')->reason)->toBe(SkipReason::WpIncompatible);
});

it('never takes an unreadable requirement or site version as met', function (string $key, string $value, SkipReason $reason): void {
    $change = changeOf(planFor([pending('plugin', 'p', '1.0', '1.1', [$key => $value])]), 'p');

    expect($change->reason)->toBe($reason)
        ->and($change->detail)->toContain("«{$value}»")->toContain('no és una versió llegible');
})->with([
    'php' => ['requires_php', '>= 7.4', SkipReason::PhpIncompatible],
    'wordpress' => ['requires_wp', '6.x', SkipReason::WpIncompatible],
    'woocommerce' => ['requires_wc', 'latest', SkipReason::Dependency],
]);

it('skips a requirement when the site version itself cannot be read', function (): void {
    $change = changeOf(planFor([pending('plugin', 'p', '1.0', '1.1', ['requires_php' => '7.4'])], php: 'unknown'), 'p');

    expect($change->reason)->toBe(SkipReason::PhpIncompatible)
        ->and($change->detail)->toContain('«unknown»')->toContain('no es pot llegir');
});

it('names the skipped WooCommerce update an extension depended on', function (): void {
    // WooCommerce 11.0 is a major: skipped before F7, so the extension that needs it is skipped too.
    $plan = planFor([
        pending('woocommerce', 'woocommerce', '10.9.0', '11.0.0'),
        pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '11.0']),
        pending('plugin', 'older', '1.0', '1.1', ['requires_wc' => '10.5']),
    ], woocommerce: '10.9.0');

    expect(changeOf($plan, 'woocommerce')->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and(changeOf($plan, 'ext')->reason)->toBe(SkipReason::Dependency)
        ->and(changeOf($plan, 'ext')->detail)->toBe("Requereix WooCommerce 11.0 i el lloc tindria 10.9.0. L'actualització de WooCommerce a 11.0.0 s'omet: major pendent: cal staging (F7).")
        ->and(changeOf($plan, 'older')->action)->toBe(PlanAction::Update);
});

it('names the skipped core update a plugin depended on, whatever the reason', function (): void {
    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.8.3', ['requires_php' => '8.3']), pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.8.2'])]);

    expect(changeOf($plan, 'wordpress')->reason)->toBe(SkipReason::PhpIncompatible)
        ->and(changeOf($plan, 'p')->detail)->toContain("L'actualització de WordPress a 6.8.3 s'omet: PHP incompatible.");
});

it('does not blame a skipped update that would not have met the requirement either', function (): void {
    $plan = planFor([pending('woocommerce', 'woocommerce', '10.1.0', '10.2.0', ['requires_php' => '9.0']), pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '10.5'])]);

    expect(changeOf($plan, 'ext')->detail)->toBe('Requereix WooCommerce 10.5 i el lloc tindria 10.1.0.');
});

it('skips an update whose required plugins are not installed and active', function (): void {
    $plan = planFor([
        pending('plugin', 'needs-active', '1.0', '1.1', ['requires_plugins' => ['helper']]),
        pending('plugin', 'needs-inactive', '1.0', '1.1', ['requires_plugins' => ['sleeper']]),
        pending('plugin', 'needs-missing', '1.0', '1.1', ['requires_plugins' => ['helper', 'ghost']]),
        pending('plugin', 'needs-wc', '1.0', '1.1', ['requires_plugins' => ['woocommerce']]),
    ], plugins: ['helper' => true, 'sleeper' => false]);

    expect(changeOf($plan, 'needs-active')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'needs-wc')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'needs-inactive')->reason)->toBe(SkipReason::Dependency)
        ->and(changeOf($plan, 'needs-inactive')->detail)->toBe('Requereix el plugin «sleeper», que està instal·lat però no està actiu.')
        ->and(changeOf($plan, 'needs-missing')->detail)->toBe('Requereix el plugin «ghost», que no està instal·lat.');
});

it('keeps a required plugin as met even when its own update is skipped', function (): void {
    // Requires Plugins has no versions, and a skipped update leaves the plugin installed.
    $plan = planFor([
        pending('plugin', 'helper', '1.0', '2.0'),
        pending('plugin', 'addon', '1.0', '1.1', ['requires_plugins' => ['helper']]),
    ], plugins: ['helper' => true, 'addon' => true]);

    expect(changeOf($plan, 'helper')->reason)->toBe(SkipReason::MajorRequiresStaging)
        ->and(changeOf($plan, 'addon')->action)->toBe(PlanAction::Update);
});

it('needs an active WooCommerce for a Requires Plugins on it', function (): void {
    $plan = planFor([pending('plugin', 'ext', '1.0', '1.1', ['requires_plugins' => ['woocommerce']])], wcActive: false);

    expect(changeOf($plan, 'ext')->detail)->toBe('Requereix el plugin «woocommerce», que està instal·lat però no està actiu.');
});

it('never takes unknown Requires Plugins as met', function (mixed $value): void {
    $change = changeOf(planFor([pending('plugin', 'p', '1.0', '1.1', ['requires_plugins' => $value])]), 'p');

    expect($change->reason)->toBe(SkipReason::Dependency)
        ->and($change->detail)->toContain("No s'han pogut llegir les dependències");
})->with([
    'null' => [null],
    'not a list' => ['woocommerce'],
]);

it('notes on the update when Requires Plugins was inferred from the installed version', function (): void {
    $plan = planFor([
        pending('plugin', 'premium', '1.0', '1.1', ['requires_plugins_inferred' => true]),
        pending('plugin', 'known', '1.0', '1.1'),
    ]);

    expect(changeOf($plan, 'premium')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'premium')->detail)->toBe(UpdatePlanner::INFERRED_PLUGINS)
        ->and(changeOf($plan, 'known')->detail)->toBeNull();
});

it('does not let an update of an inactive WooCommerce meet requires_wc', function (): void {
    $plan = planFor([
        pending('woocommerce', 'woocommerce', '10.1.0', '10.2.0'),
        pending('plugin', 'ext', '1.0', '1.1', ['requires_wc' => '10.2']),
    ], wcActive: false);

    expect(changeOf($plan, 'woocommerce')->action)->toBe(PlanAction::Update)
        ->and(changeOf($plan, 'ext')->reason)->toBe(SkipReason::Dependency)
        ->and(changeOf($plan, 'ext')->detail)->toContain('no està actiu');
});

it('names a higher core offer that was not planned when it would meet requires_wp', function (): void {
    // 6.8.3 is planned; 6.9.1 would meet the plugin but is a major pending staging.
    $plan = planFor([
        pending('core', 'wordpress', '6.8.1', '6.8.3'),
        pending('core', 'wordpress', '6.8.1', '6.9.1'),
        pending('core', 'wordpress', '6.8.1', '6.9.0'),
        pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9']),
    ]);

    expect(changeOf($plan, 'wordpress')->targetVersion)->toBe('6.8.3')
        ->and(changeOf($plan, 'p')->detail)->toBe('Requereix WordPress 6.9 i el lloc tindria 6.8.3. WordPress 6.9.0 és pendent: major pendent: cal staging (F7).');

    $plan = planFor([pending('core', 'wordpress', '6.8.1', '6.8.3'), pending('plugin', 'p', '1.0', '1.1', ['requires_wp' => '6.9'])]);
    expect(changeOf($plan, 'p')->detail)->toBe('Requereix WordPress 6.9 i el lloc tindria 6.8.3.');
});
