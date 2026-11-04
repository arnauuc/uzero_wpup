<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Sites\BlockOutcome;
use App\Domain\Sites\BlockResult;
use App\Domain\Sites\SiteBlocking;
use App\Models\Site;
use Closure;

/**
 * First step of every run: reads the site and stores a fresh snapshot. It is
 * read-only for the site, so it also runs under --dry-run. It is also where the
 * site is blocked or released from its prerequisites (UZEROWPUP-34): that is
 * state of the tool, not of the site, and a dry run only reports it.
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
        private ?SiteBlocking $blocking = null,
        /** Never writes to the site nor changes its block, with or without --dry-run (plan runs, UZEROWPUP-36). */
        private bool $readOnlySite = false,
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
            .($this->leavesSiteAlone($context) ? '.' : ", i confirmarà el PHP del web amb un probe temporal que s'esborra en acabar.");
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
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut fer l'inventari: {$e->getMessage()}");
        }

        try {
            // A dry run changes nothing on the site, not even a temporary probe.
            $result = $this->inventory->takeWithPrerequisites($site, $driver, $context->run, probeWeb: ! $this->leavesSiteAlone($context));
        } catch (ConnectionFailed $e) {
            // Transient: the site is not blocked for a network failure.
            return StepOutcome::failed("No s'ha pogut fer l'inventari: {$e->getMessage()}");
        } catch (DriverException $e) {
            // WP-CLI does not work on the site: it cannot be updated either.
            if ($this->blocking !== null && ! $this->leavesSiteAlone($context)) {
                $this->blocking->blockForWpCli($site, $actor);
            }

            return StepOutcome::failed("No s'ha pogut fer l'inventari: {$e->getMessage()}");
        }
        $snapshot = $result->snapshot;

        $summary = sprintf(
            'WordPress %s, PHP %s, %d connectors, %d temes',
            $snapshot->core_version,
            $snapshot->php_version,
            count($snapshot->plugins),
            count($snapshot->themes),
        );

        $summary .= $this->stateSummary($snapshot->environment['state'] ?? null);
        // A read-only inventory neither blocks nor releases the site: the plan reads its current block.
        $block = $this->readOnlySite ? null : $this->applyBlock($site, $result->prerequisites, $actor, $context->dryRun);
        $summary .= $this->blockSummary($block, $context->dryRun);

        // The block outcome is stored so reports show what the inventory decided (a dry run included).
        return StepOutcome::succeeded($summary, '', [
            'snapshot_id' => $snapshot->id,
            ...($block === null ? [] : ['block' => ['outcome' => $block->outcome->value, 'reason' => $block->reason]]),
        ]);
    }

    /**
     * A dry run, and a read-only inventory, change nothing on the site, not even a temporary probe.
     */
    private function leavesSiteAlone(StepContext $context): bool
    {
        return $context->dryRun || $this->readOnlySite;
    }

    /**
     * Blocks or releases the site from the stored prerequisites (UZEROWPUP-34).
     *
     * @param  list<PrerequisiteCheck>|null  $checks  null when the inventory evaluated no prerequisites
     */
    private function applyBlock(Site $site, ?array $checks, AuditActor $actor, bool $dryRun): ?BlockResult
    {
        if ($this->blocking === null || $checks === null) {
            return null;
        }

        return $this->blocking->apply($site, $checks, $actor, $dryRun);
    }

    /**
     * Says what happened to the block; empty when there is nothing to say.
     */
    private function blockSummary(?BlockResult $result, bool $dryRun): string
    {
        if ($result === null) {
            return '';
        }

        $summary = match ($result->outcome) {
            BlockOutcome::Blocked => "; bloquejat: {$result->reason}",
            BlockOutcome::WouldBlock => "; es bloquejaria: {$result->reason}",
            BlockOutcome::Unblocked => '; desbloquejat',
            BlockOutcome::WouldUnblock => '; es desbloquejaria',
            BlockOutcome::Unchanged => $result->reason === null ? '' : "; bloquejat: {$result->reason}",
        };

        // No HTTP probe in a dry run: the PHP checks did not take part in the prediction.
        return $dryRun ? $summary.'; en dry-run no es verifica el PHP del web' : $summary;
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

        $warnings = is_array($state['warnings'] ?? null) ? count($state['warnings']) : 0;
        if ($warnings > 0) {
            $notes[] = sprintf("%d avisos de l'estat previ", $warnings);
        }

        return $notes === [] ? '' : '; '.implode('; ', $notes);
    }

    private function siteName(StepContext $context): string
    {
        return $context->run->site->name ?? '(desconegut)';
    }
}
