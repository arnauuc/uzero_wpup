<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\BackupKind;
use App\Enums\ComponentType;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use Closure;

/**
 * First check of a restore run (UZEROWPUP-46), before anything is written,
 * so it also runs under --dry-run: the chosen copies are still of the site,
 * live and verified, their destination still holds their bytes, and a shop's
 * database is not restored unless the operator accepted losing its new data.
 *
 * AGENTS.md rule 4: a shop's database is never restored automatically when
 * it may have new orders or users since the copy. This is a manual restore,
 * so it may go ahead, but only with the explicit --accept-data-loss. The
 * order and user watermark of the copy (backups.order_watermark,
 * UZEROWPUP-58) cannot be compared yet: until it can, a shop (WooCommerce
 * installed, active or not) always counts as possibly losing data.
 */
final readonly class RestoreCheckStep implements ReadOnlyStep
{
    public const string NAME = 'restore-check';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
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
        return 'Comprovarà que les còpies triades són del lloc, vives i verificades, i el seu SHA-256 a la destinació.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = self::actor($this->authorization, $context->run);
        if (is_string($actor)) {
            return StepOutcome::failed($actor);
        }
        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per restaurar.");
        }

        try {
            $request = RestoreRequest::fromRun($context->run);
            $rows = $this->selection->rows($site, $request);
            foreach ($rows as $row) {
                $this->restore->verify($site, $row, $actor);
            }
            $shop = $request->restoresDatabase() && $this->isShop(($this->driverFor)($site, $actor));
            if ($shop && ! $request->acceptDataLoss) {
                throw new RestoreRefused(self::dataLossMessage($rows[BackupKind::Database->value]).' Cal --accept-data-loss per restaurar-la igualment.');
            }
        } catch (RestoreRefused $e) {
            return StepOutcome::failed($e->getMessage());
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut comprovar el lloc: {$e->getMessage()}");
        }

        $summary = sprintf('Còpies verificades a la destinació: %s', implode(', ', array_map(
            static fn (Backup $row): string => "#{$row->id} ".($row->kind === BackupKind::Database ? 'BD' : 'fitxers').' ('.($row->backupTarget->name ?? '?').')',
            $rows,
        )));
        $warning = $shop ? self::dataLossMessage($rows[BackupKind::Database->value]).' L\'operador ho ha acceptat (--accept-data-loss).' : '';

        return StepOutcome::succeeded($summary.($warning === '' ? '' : ". {$warning}"), $warning, [
            'directory' => $request->directory,
            'backup_ids' => array_values(array_map(static fn (Backup $row): int => $row->id, $rows)),
            'shop' => $shop,
            'accepted_data_loss' => $shop,
        ]);
    }

    /**
     * Who the restore acts for, or why it must not run: a restore is always
     * an operator's decision, never a scheduler's.
     */
    public static function actor(RunAuthorization $authorization, Run $run): AuditActor|string
    {
        if ($run->user_id === null) {
            return "Una restauració l'ha de llançar un operador: no s'ha executat.";
        }
        if ($run->site === null) {
            return "L'execució no té cap lloc: no hi ha res per restaurar.";
        }

        return $authorization->actorFor($run, Ability::RunOperations) ?? RunAuthorization::deniedMessage($run);
    }

    public static function dataLossMessage(Backup $database): string
    {
        return "El lloc és una botiga (WooCommerce): restaurar-ne la base de dades esborra les comandes, els clients i els usuaris entrats des de la còpia ({$database->created_at->format(BackupListing::dateFormat())}), i encara no es pot saber quins són.";
    }

    /**
     * @throws DriverException
     */
    private function isShop(SiteDriver $driver): bool
    {
        return $driver->installedComponents()->find(ComponentType::Woocommerce, 'woocommerce') !== null;
    }
}
