<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Site;
use Closure;
use InvalidArgumentException;

/**
 * The update steps of a plan (UZEROWPUP-54): one step per planned update, in
 * the plan's own order, which UpdatePlanner already fixes (core → WooCommerce
 * → plugins → themes). Skipped changes get no step. The same plan always gives
 * the same steps with the same names, as a resumed run needs. The rest of the
 * update run (backup, verification, update-db, cache…) is UZEROWPUP-55/60.
 *
 * Translations are left out for now: WP-CLI would download them on the site,
 * and nothing is ever downloaded by the site (AGENTS.md). How they get
 * updated is still to be decided.
 */
final readonly class UpdateSteps
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     * @param  int  $minimumTimeoutSeconds  the longest an install can take (the driver's
     *                                      applyUpdate() budget): a step timeout below it
     *                                      could fire mid-install
     *
     * @throws InvalidArgumentException when the step timeout is below that
     */
    public function __construct(
        private ArtifactStore $store,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private UpdateChanges $changes,
        private SecretRedactor $redactor,
        private int $componentTimeoutSeconds,
        int $minimumTimeoutSeconds,
    ) {
        if ($componentTimeoutSeconds < $minimumTimeoutSeconds) {
            throw new InvalidArgumentException("wpup.updates.component_timeout_seconds ({$componentTimeoutSeconds} s) ha de ser com a mínim el temps màxim d'una instal·lació ({$minimumTimeoutSeconds} s).");
        }
    }

    /**
     * @return list<ComponentUpdateStep>
     */
    public function for(UpdatePlan $plan): array
    {
        $steps = [];
        foreach ($plan->changes as $change) {
            if ($change->action === PlanAction::Update && $change->type !== ComponentType::Translation) {
                $steps[] = $this->step($change);
            }
        }

        return $steps;
    }

    private function step(PlannedChange $change): ComponentUpdateStep
    {
        return new ComponentUpdateStep($change, $this->store, $this->driverFor, $this->authorization, $this->changes, $this->redactor, $this->componentTimeoutSeconds);
    }
}
