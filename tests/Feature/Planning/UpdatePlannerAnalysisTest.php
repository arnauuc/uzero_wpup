<?php

declare(strict_types=1);

use App\Domain\Inventory\PendingUpdate;
use App\Domain\Planning\ChangeAnalysis;
use App\Domain\Planning\ChangeAnalyzer;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Planning\UpdatePlanner;
use App\Enums\PhpVersionSource;
use App\Enums\SkipReason;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;

beforeEach(fn () => config(['wpup.planning.quarantine_days' => 0]));

/**
 * @param  list<array<string, mixed>>  $updates
 * @param  array<string, mixed>  $profile
 */
function analysedPlan(array $updates, ChangeAnalyzer $analyzer, array $profile = []): UpdatePlan
{
    $site = Site::factory()->create();
    $profileModel = $profile === [] ? null : SiteProfile::factory()->create(['site_id' => $site->id] + $profile);
    $snapshot = SiteSnapshot::factory()->create([
        'site_id' => $site->id,
        'core_version' => '6.8.1',
        'plugins' => [],
        'environment' => ['prerequisites' => []],
        'pending_updates' => $updates,
    ]);

    return app(UpdatePlanner::class)->plan($snapshot, $site, $profileModel, '8.2.20', PhpVersionSource::HttpProbe, $analyzer);
}

/**
 * @return array<string, mixed>
 */
function analysedPending(string $slug, string $type = 'plugin'): array
{
    return ['type' => $type, 'slug' => $slug, 'current_version' => '1.0', 'new_version' => '1.1', 'requires_php' => null, 'requires_wp' => null, 'requires_wc' => null, 'requires_plugins' => [], 'licensed' => true];
}

/** An analyzer that answers by slug and records what it was asked. */
final class RecordingAnalyzer implements ChangeAnalyzer
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, ChangeAnalysis> $answers */
    public function __construct(private array $answers) {}

    public function analyze(PlannedChange $change, ?PendingUpdate $update): ?ChangeAnalysis
    {
        $this->asked[] = $change->slug;

        return $this->answers[$change->slug] ?? null;
    }
}

it('skips an update whose analysis found errors, keeps the others and stores warnings', function (): void {
    $analyzer = new RecordingAnalyzer([
        'bad' => new ChangeAnalysis(SkipReason::StaticAnalysis, 'PHPCompatibility: 2 errors per a PHP 8.2 (a.php:3).', ['a.php:9 avís']),
        'warned' => new ChangeAnalysis(warnings: ['b.php:1 avís']),
    ]);

    $plan = analysedPlan([analysedPending('bad'), analysedPending('warned'), analysedPending('fine')], $analyzer);
    $byslug = [];
    foreach ($plan->changes as $change) {
        $byslug[$change->slug] = $change;
    }

    expect($byslug['bad']->action)->toBe(PlanAction::Skip)
        ->and($byslug['bad']->reason)->toBe(SkipReason::StaticAnalysis)
        ->and($byslug['bad']->detail)->toBe('PHPCompatibility: 2 errors per a PHP 8.2 (a.php:3).')
        ->and($byslug['warned']->action)->toBe(PlanAction::Update)
        ->and($byslug['warned']->warnings)->toBe(['b.php:1 avís'])
        ->and($byslug['fine']->action)->toBe(PlanAction::Update)
        ->and($byslug['fine']->warnings)->toBe([]);

    // The warnings survive being stored in the run and read back by the report.
    expect(UpdatePlan::fromArray($plan->toArray())->toArray())->toBe($plan->toArray());
    expect($plan->toArray()['changes'][0])->toHaveKey('warnings')->and($plan->toArray()['changes'][1])->not->toHaveKey('warnings');
});

it('analyses only what is still going to be applied', function (): void {
    $analyzer = new RecordingAnalyzer([]);

    analysedPlan([analysedPending('frozen-one'), analysedPending('free')], $analyzer, ['frozen_components' => ['frozen-one']]);

    expect($analyzer->asked)->toBe(['free']);
});

it('does not analyse anything on a blocked site', function (): void {
    $analyzer = new RecordingAnalyzer([]);
    $site = Site::factory()->create(['blocked_reason' => 'bloquejat']);
    $snapshot = SiteSnapshot::factory()->create(['site_id' => $site->id, 'core_version' => '6.8.1', 'plugins' => [], 'environment' => ['prerequisites' => []], 'pending_updates' => [analysedPending('a')]]);

    app(UpdatePlanner::class)->plan($snapshot, $site, null, '8.2.20', PhpVersionSource::HttpProbe, $analyzer);

    expect($analyzer->asked)->toBe([]);
});

it('hands the analyzer the pending update the planner parsed', function (): void {
    $analyzer = new class implements ChangeAnalyzer
    {
        public ?PendingUpdate $seen = null;

        public function analyze(PlannedChange $change, ?PendingUpdate $update): ?ChangeAnalysis
        {
            $this->seen = $update;

            return null;
        }
    };

    analysedPlan([analysedPending('a')], $analyzer);

    expect($analyzer->seen?->slug)->toBe('a')->and($analyzer->seen?->newVersion)->toBe('1.1');
});

it('plans as before without an analyzer', function (): void {
    $site = Site::factory()->create();
    $snapshot = SiteSnapshot::factory()->create(['site_id' => $site->id, 'core_version' => '6.8.1', 'plugins' => [], 'environment' => ['prerequisites' => []], 'pending_updates' => [analysedPending('a')]]);

    $plan = app(UpdatePlanner::class)->plan($snapshot, $site, null, '8.2.20', PhpVersionSource::HttpProbe);

    expect($plan->updateCount())->toBe(1);
});
