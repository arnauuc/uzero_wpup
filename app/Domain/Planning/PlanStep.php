<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\ChangeResult;
use App\Enums\PhpVersionSource;
use App\Models\Run;
use App\Models\SiteSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Second step of a plan run: plans from the snapshot the inventory step just
 * took. It touches neither the site nor the tool's state; it only records, in
 * the run's own run_changes, each skipped component with its reason
 * (UZEROWPUP-48).
 */
final readonly class PlanStep implements ReadOnlyStep
{
    public const string NAME = 'plan';

    public function __construct(private UpdatePlanner $planner, private int $timeoutSeconds, private int $verifiedPhpMaxAgeSeconds) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Calcularà què s'actualitzaria i què s'ometria al lloc ".($context->run->site->name ?? '(desconegut)').', sense tocar-lo.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $site = $context->run->site;
        $snapshotId = $context->resultOf(InventoryStep::NAME)['snapshot_id'] ?? null;
        $snapshot = is_int($snapshotId) ? SiteSnapshot::query()->find($snapshotId) : null;
        if ($site === null || $snapshot === null) {
            return StepOutcome::failed('No hi ha cap inventari fresc per planificar.');
        }

        // The plan never probes the web, so the PHP may be the CLI's. The latest probe
        // verified PHP stands in for it only if it is recent and the CLI agrees on the minor.
        $php = $snapshot->php_version;
        $source = $snapshot->php_source;
        if ($source === PhpVersionSource::Cli) {
            $verified = SiteSnapshot::latestWebVerifiedPhp($site, $this->verifiedPhpMaxAgeSeconds);
            if ($verified !== null && PhpRuntime::isVersion($php) && PhpRuntime::isVersion($verified->php_version)
                && PhpRuntime::minorOf($verified->php_version) === PhpRuntime::minorOf($php)) {
                $php = $verified->php_version;
                $source = $verified->php_source;
            }
        }

        $plan = $this->planner->plan($snapshot, $site->refresh(), $site->profile, $php, $source);
        $this->recordSkips($context->run, $plan);

        return StepOutcome::succeeded($plan->summary(), '', $plan->toArray());
    }

    /**
     * One run_changes row per skipped component. version_after is the target that
     * was not applied. A retried step replaces its own rows.
     */
    private function recordSkips(Run $run, UpdatePlan $plan): void
    {
        DB::transaction(static function () use ($run, $plan): void {
            $run->changes()->where('result', ChangeResult::Skipped->value)->delete();
            foreach ($plan->changes as $change) {
                if ($change->action !== PlanAction::Skip) {
                    continue;
                }
                $run->changes()->create([
                    'component_type' => $change->type,
                    'slug' => $change->slug,
                    'version_before' => $change->currentVersion,
                    'version_after' => $change->targetVersion,
                    'result' => ChangeResult::Skipped,
                    'reason' => $change->reason,
                    'reason_detail' => $change->detail,
                    'is_major' => VersionChange::isMajor($change->type, $change->currentVersion, $change->targetVersion),
                ]);
            }
        });
    }
}
