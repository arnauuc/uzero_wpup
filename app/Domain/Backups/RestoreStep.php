<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Last step of a restore run (UZEROWPUP-46). Order of the run: inventory
 * (advisory), check of the copies, maintenance mode on, point of the current
 * state, and then this step: leftovers of an earlier restore removed, the
 * copies chosen by RestoreCheckStep put back (files first, maintenance mode
 * on again, then the database; SiteRestore says which checksum is checked
 * where), WP-CLI checked, object cache flushed, and the maintenance mode left
 * as it was before the run, whatever happens.
 *
 * It needs the verified point of the current state (AGENTS.md rule 3) unless
 * the operator asked for --without-pre-backup and it could not be made.
 * Nothing is undone automatically when something fails: the outcome says what
 * was restored and how to go back by hand. Every attempt is audited
 * (`backup.restore`).
 */
final readonly class RestoreStep implements Step
{
    public const string NAME = 'restore';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
        private RestorePointGate $gate,
        private RestoreMaintenanceStep $maintenance,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private AuditLogger $audit,
        private SecretRedactor $redactor,
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
        try {
            $request = RestoreRequest::fromRun($context->run);
        } catch (RestoreRefused $e) {
            return $e->getMessage();
        }

        return sprintf(
            'Restaurarà %s del lloc %s des del punt «%s» i buidarà la memòria cau.',
            implode(' i ', array_map(self::label(...), $request->kinds())),
            $context->run->site->name ?? '?',
            $request->directory,
        );
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = RestoreCheckStep::actor($this->authorization, $context->run);
        $site = $context->run->site;
        if (is_string($actor) || $site === null) {
            return StepOutcome::failed(is_string($actor) ? $actor : "L'execució no té cap lloc.");
        }

        $check = $context->resultOf(RestoreCheckStep::NAME);
        $pre = $context->resultOf(PreRestoreBackupStep::NAME);
        $preRestore = is_string($pre['directory'] ?? null) ? $pre['directory'] : null;
        $shop = ($check['shop'] ?? true) === true;
        $restored = [];
        $warnings = [];
        $audit = ['run_id' => $context->run->id, 'restore_point' => null, 'backup_ids' => [], 'kinds' => [], 'pre_restore_point' => $preRestore,
            'shop' => $shop, 'accepted_data_loss' => ($check['accepted_data_loss'] ?? false) === true,
            'data_loss_confirmed' => false, 'without_pre_backup' => false];

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut connectar amb el lloc: no s'ha restaurat res. {$this->redactor->redact($e->getMessage())}");
        }
        $maintenanceReset = false;

        try {
            $request = RestoreRequest::fromRun($context->run);
            $audit = ['data_loss_confirmed' => $request->dataLossConfirmed, 'without_pre_backup' => $request->withoutPreBackup, 'restore_point' => $request->directory,
                'kinds' => array_map(static fn (BackupKind $k): string => $k->value, $request->kinds())] + $audit;
            if ($preRestore === null ? ! ($request->withoutPreBackup && ($pre['skipped'] ?? false) === true) : ! $this->gate->isVerified($context->run)) {
                throw new RestoreRefused("No hi ha un punt de restauració verificat de l'estat actual del lloc.");
            }
            if ($request->restoresDatabase() && $shop && ! ($request->acceptDataLoss && $request->dataLossConfirmed)) {
                throw new RestoreRefused('La base de dades és (o pot ser) d\'una botiga i no s\'ha acceptat i confirmat perdre\'n les dades noves.');
            }
            $chosen = [];
            foreach (is_array($check['chosen'] ?? null) ? $check['chosen'] : [] as $kind => $id) {
                if (is_string($kind) && is_int($id)) {
                    $chosen[$kind] = $id;
                }
            }
            $rows = $this->selection->rows($site, $request, $chosen);
            $audit['backup_ids'] = array_values(array_map(static fn ($row): int => $row->id, $rows));
            $this->restore->purgeTemporaries($driver);
            foreach ($rows as $row) {
                $this->restore->restore($site, $driver, $row, $actor);
                $restored[] = $row->kind;
                if ($row->kind === BackupKind::Files) {
                    $warnings[] = $this->maintenance->restoreState($driver, $context, on: true);
                }
            }
            $warnings = [...$warnings, ...self::checkAfterRestore($driver)];
        } catch (RunException $e) {
            // The run itself was stopped (timeout, claim lost): audited, never swallowed.
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'reason' => 'run stopped']);

            throw $e;
        } catch (RestoreRefused|DriverException|PostRestoreCheckFailed $e) {
            $reason = $this->redactor->redact($e->getMessage());
            $warnings[] = $this->maintenance->restoreState($driver, $context, on: false);
            $maintenanceReset = true;
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'reason' => $reason]);
            $done = $restored === [] ? ' No s\'ha restaurat res.' : ' Ja s\'han restaurat '.implode(' i ', array_map(self::label(...), $restored)).'. '.$this->back($site, $preRestore, $shop);
            $notes = array_values(array_filter($warnings));

            return StepOutcome::failed(trim("{$reason}{$done}".($notes === [] ? '' : ' '.implode(' ', $notes))));
        } catch (Throwable $e) {
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'exception' => $e::class]);

            throw $e;
        } finally {
            // Whatever happened: the maintenance mode as it was before the run.
            if (! $maintenanceReset) {
                $warnings[] = $this->maintenance->restoreState($driver, $context, on: false);
            }
        }

        $this->record($actor, $site, AuditResult::Success, $audit + ['restored' => self::values($restored)]);
        $warnings = array_values(array_filter($warnings));

        return StepOutcome::succeeded(
            'Restaurat: '.implode(' i ', array_map(self::label(...), $restored))." des de «{$request->directory}». "
                .($preRestore === null ? "Sense punt de l'estat d'abans (--without-pre-backup)." : "Punt de l'estat d'abans: «{$preRestore}».")
                .($warnings === [] ? '' : sprintf(' %d avisos.', count($warnings))),
            implode("\n", [...$warnings, $this->back($site, $preRestore, $shop)]),
            ['restore_point' => $request->directory, 'pre_restore_point' => $preRestore, 'restored' => self::values($restored)],
        );
    }

    /**
     * How to go back to the state before the restore, when there is a point of it.
     */
    private function back(Site $site, ?string $preRestore, bool $shop): string
    {
        if ($preRestore === null) {
            return "No hi ha cap punt de l'estat d'abans (--without-pre-backup).";
        }

        return "Per tornar a l'estat d'abans: wpup backup:restore {$site->name} --point=".basename($preRestore).' --files --db'
            .($shop ? ' --accept-data-loss (i confirmar-ho escrivint el nom del lloc)' : '').'.';
    }

    /**
     * WordPress must still answer through WP-CLI; the object cache is flushed
     * (a failure there is only a warning). Also after a database rollback
     * (UZEROWPUP-59).
     *
     * @return list<string> warnings
     *
     * @throws PostRestoreCheckFailed
     */
    public static function checkAfterRestore(SiteDriver $driver): array
    {
        try {
            $version = $driver->wp(['core', 'version']);
        } catch (DriverException $e) {
            throw PostRestoreCheckFailed::because($e->getMessage());
        }
        if (! $version->successful() || trim($version->stdout) === '') {
            throw PostRestoreCheckFailed::because('`wp core version` ha fallat: '.trim($version->stderr));
        }

        try {
            $flushed = $driver->wp(['cache', 'flush'])->successful();
        } catch (DriverException) {
            $flushed = false;
        }

        return $flushed ? [] : ["No s'ha pogut buidar la memòria cau d'objectes (wp cache flush): fes-ho a mà si el lloc en fa servir."];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(AuditActor $actor, Site $site, AuditResult $result, array $context): void
    {
        try {
            $this->audit->record($actor, 'backup.restore', $result, 'site', $site->id, $context);
        } catch (Throwable $e) {
            if ($result === AuditResult::Success) {
                throw $e;
            }
            // The failure itself is what the operator must see.
            Log::error('Could not audit a failed restore.', ['site_id' => $site->id, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }

    /**
     * @param  list<BackupKind>  $kinds
     * @return list<string>
     */
    private static function values(array $kinds): array
    {
        return array_map(static fn (BackupKind $k): string => $k->value, $kinds);
    }

    private static function label(BackupKind $kind): string
    {
        return $kind === BackupKind::Database ? 'la base de dades' : 'els fitxers';
    }
}
