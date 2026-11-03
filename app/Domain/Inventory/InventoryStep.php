<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\AuditChannel;
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
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor  resolves the driver of the run's site;
     *                                                            credential reveals are audited as $actor
     */
    public function __construct(
        private SiteInventory $inventory,
        private Closure $driverFor,
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
        return "Llegirà les versions del nucli, els connectors, els temes, els drop-ins, els mu-plugins, PHP i la base de dades del lloc {$this->siteName($context)}.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $site = $context->run->site()->firstOrFail();

        try {
            // The worker acts on behalf of whoever launched the run, so any
            // credential it reveals is audited against that operator.
            $actor = new AuditActor($context->run->user_id, $context->run->actor, AuditChannel::System);
            $snapshot = $this->inventory->take($site, ($this->driverFor)($site, $actor), $context->run);
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

        return StepOutcome::succeeded($summary, '', ['snapshot_id' => $snapshot->id]);
    }

    private function siteName(StepContext $context): string
    {
        return $context->run->site()->first()->name ?? '(desconegut)';
    }
}
