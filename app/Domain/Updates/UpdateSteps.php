<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Site;
use Closure;

/**
 * The update steps of a plan (UZEROWPUP-54), in the fixed order: core →
 * WooCommerce → plugins → themes, one step per planned update, then the
 * translations. Skipped changes get no step. The same plan always gives the
 * same steps with the same names, as a resumed run needs. The rest of the
 * update run (backup, verification, update-db, cache…) is UZEROWPUP-55/60.
 */
final readonly class UpdateSteps
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private PackageArtifacts $artifacts,
        private ArtifactStore $store,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private UpdateChanges $changes,
        private SecretRedactor $redactor,
        private WpCliReader $reader,
        private int $componentTimeoutSeconds,
        private int $translationsTimeoutSeconds,
        private int $translationsCommandTimeoutSeconds,
    ) {}

    /**
     * @return list<ComponentUpdateStep|TranslationsStep>
     */
    public function for(UpdatePlan $plan): array
    {
        // Translations are not code packages: TranslationsStep updates all of them.
        $updates = array_values(array_filter($plan->changes, static fn (PlannedChange $c): bool => $c->action === PlanAction::Update && $c->type !== ComponentType::Translation));
        // Stable: the plan's order within each type is kept.
        usort($updates, static fn (PlannedChange $a, PlannedChange $b): int => array_search($a->type, UpdatePlanner::ORDER, true) <=> array_search($b->type, UpdatePlanner::ORDER, true));

        $steps = array_map(fn (PlannedChange $change): ComponentUpdateStep => new ComponentUpdateStep(
            $change, $this->artifacts, $this->store, $this->driverFor, $this->authorization, $this->changes, $this->redactor, $this->componentTimeoutSeconds,
        ), $updates);
        $steps[] = new TranslationsStep($this->driverFor, $this->authorization, $this->changes, $this->reader, $this->translationsTimeoutSeconds, $this->translationsCommandTimeoutSeconds);

        return $steps;
    }
}
