<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\QuarantineBypass;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Planning\WebPhp;
use App\Domain\Updates\UpdateSteps;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduler's cheap, read-only look at a site (ADR-0006 §3): the plan of
 * its latest inventory, made again NOW and offline. The planner reads only the
 * tool's database: quarantine and known vulnerabilities (VulnerabilitySource)
 * as of now, the profile's frozen and excluded components, the site's block.
 * No static analysis, so no package is downloaded and the site is not reached.
 *
 * An inventory older than `snapshotMaxAgeHours` may miss new releases: the
 * outlook is then unknown and the site is not skipped (its run inventories it
 * and ends right after the plan if there is nothing to do).
 */
final readonly class UpdateOutlooks
{
    public function __construct(
        private UpdatePlanner $planner,
        private int $snapshotMaxAgeHours,
        private int $verifiedPhpMaxAgeSeconds,
    ) {}

    public function of(Site $site, CarbonImmutable $now): UpdateOutlook
    {
        $snapshot = SiteSnapshot::query()->where('site_id', $site->id)->latest('taken_at')->latest('id')->first();
        if ($snapshot === null) {
            return UpdateOutlook::unknown('Sense inventari previ.');
        }
        if ($snapshot->taken_at->lessThan($now->subHours($this->snapshotMaxAgeHours))) {
            return UpdateOutlook::unknown("L'últim inventari és de fa més de {$this->snapshotMaxAgeHours} h.");
        }

        try {
            [$php, $source] = WebPhp::of($snapshot, $site, $this->verifiedPhpMaxAgeSeconds);
            $plan = $this->planner->plan($snapshot, $site, $site->profile, $php, $source);
        } catch (Throwable $e) {
            // Never fatal: without an outlook the run decides.
            Log::warning('The scheduler could not plan a site from its latest inventory.', ['site' => $site->name, 'error' => $e->getMessage()]);

            return UpdateOutlook::unknown();
        }

        $applicable = UpdateSteps::applicable($plan);
        $security = array_filter($applicable, static fn (PlannedChange $c): bool => in_array($c->bypass, [QuarantineBypass::Vulnerability, QuarantineBypass::CoreMaintenance], true)) !== [];
        $majors = array_values(array_map(
            static fn (PlannedChange $c): string => "{$c->type->value}:{$c->slug} {$c->currentVersion}→{$c->targetVersion}",
            array_filter($plan->changes, static fn (PlannedChange $c): bool => $c->requiresStaging()),
        ));

        return new UpdateOutlook(
            $applicable === [],
            $security,
            $majors,
            $applicable === [] && $plan->blockedReason !== null ? "Lloc bloquejat: {$plan->blockedReason}" : $plan->summary(),
        );
    }
}
