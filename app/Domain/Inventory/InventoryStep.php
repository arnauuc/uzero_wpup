<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Models\Site;
use Closure;

/**
 * First step of every run: reads the site and stores a fresh snapshot. It is
 * read-only, so it also runs under --dry-run.
 */
final readonly class InventoryStep implements ReadOnlyStep
{
    public const string NAME = 'inventory';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor  resolves the driver of the run's site
     *                                                            (DriverFactory::forSite); credential
     *                                                            reveals are audited as $actor
     */
    public function __construct(
        private SiteInventory $inventory,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private int $timeoutSeconds,
    ) {}

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
        return "Llegirà les versions del nucli, els connectors, els temes, els drop-ins, els mu-plugins, PHP i la base de dades del lloc {$this->siteName($context)}"
            .($context->dryRun ? '.' : ", i confirmarà el PHP del web amb un probe temporal que s'esborra en acabar.");
    }

    public function run(StepContext $context): StepOutcome
    {
        // Connecting reveals the site's credential: the worker checks again that
        // whoever launched the run may still do it (ADR-0011), and audits as them.
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per inventariar.");
        }

        try {
            // A dry run changes nothing on the site, not even a temporary probe.
            $snapshot = $this->inventory->take($site, ($this->driverFor)($site, $actor), $context->run, probeWeb: ! $context->dryRun);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut fer l'inventari: {$e->getMessage()}");
        }

        $summary = sprintf(
            'WordPress %s, PHP %s, %d connectors, %d temes',
            $snapshot->core_version,
            $snapshot->php_version,
            count($snapshot->plugins),
            count($snapshot->themes),
        );

        $summary .= $this->stateSummary($snapshot->environment['state'] ?? null);

        return StepOutcome::succeeded($summary, '', ['snapshot_id' => $snapshot->id]);
    }

    /**
     * Only what matters before updating (UZEROWPUP-33); empty when all is normal.
     */
    private function stateSummary(mixed $state): string
    {
        if (! is_array($state)) {
            return '';
        }

        $notes = [];
        if (($state['maintenance'] ?? false) === true) {
            $notes[] = 'manteniment actiu';
        }
        $woocommerce = is_array($state['woocommerce'] ?? null) ? $state['woocommerce'] : [];
        if (($woocommerce['db_update_needed'] ?? false) === true) {
            $notes[] = 'BD de WooCommerce pendent d\'actualitzar';
        }
        $templates = is_array($woocommerce['overridden_templates'] ?? null) ? $woocommerce['overridden_templates'] : [];
        if ($templates !== []) {
            $outdated = count(array_filter($templates, static fn (mixed $t): bool => is_array($t) && ($t['outdated'] ?? false) === true));
            $notes[] = sprintf('%d plantilles sobreescrites (%d desfasades)', count($templates), $outdated);
        }
        $cron = is_array($state['cron'] ?? null) ? $state['cron'] : [];
        $overdue = is_int($cron['overdue_events'] ?? null) ? $cron['overdue_events'] : 0;
        if ($overdue > 0) {
            $notes[] = sprintf('%d esdeveniments de cron endarrerits', $overdue);
        }

        return $notes === [] ? '' : '; '.implode('; ', $notes);
    }

    private function siteName(StepContext $context): string
    {
        return $context->run->site->name ?? '(desconegut)';
    }
}
