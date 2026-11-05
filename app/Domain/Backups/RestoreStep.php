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
 * Last step of a restore run (UZEROWPUP-46): puts the chosen copies back on
 * the site, files first, then the database (SiteRestore checks each SHA-256
 * at its destination and again on the site right before), then checks that
 * WordPress still answers and flushes its object cache.
 *
 * It only runs once the run made a verified restore point of the current
 * state (BackupStep, AGENTS.md rule 3). Nothing is undone automatically when
 * something fails: the outcome says what was restored and how to go back to
 * that point by hand. Every attempt is audited (`backup.restore`).
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
            'Restaurarà %s del lloc %s des del punt «%s» (còpies %s), després de tornar-ne a comprovar el SHA-256, i buidarà la memòria cau.',
            implode(' i ', array_map(self::label(...), $request->kinds())),
            $context->run->site->name ?? '?',
            $request->directory,
            implode(', ', array_map(static fn (int $id): string => "#{$id}", $request->backupIds)),
        );
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = RestoreCheckStep::actor($this->authorization, $context->run);
        $site = $context->run->site;
        if (is_string($actor) || $site === null) {
            return StepOutcome::failed(is_string($actor) ? $actor : "L'execució no té cap lloc.");
        }

        $preRestore = $context->resultOf(BackupStep::NAME)['directory'] ?? null;
        if (! is_string($preRestore) || ! $this->gate->isVerified($context->run)) {
            return StepOutcome::failed("No hi ha un punt de restauració verificat de l'estat actual del lloc: no es restaura res.");
        }
        $back = "Per tornar a l'estat d'abans de restaurar: wpup backup:restore {$site->name} --point=".basename($preRestore).' --files --db';

        $restored = [];
        $audit = ['run_id' => $context->run->id, 'restore_point' => null, 'backup_ids' => [], 'kinds' => [], 'pre_restore_point' => $preRestore,
            'accepted_data_loss' => ($context->resultOf(RestoreCheckStep::NAME)['accepted_data_loss'] ?? false) === true];

        try {
            $request = RestoreRequest::fromRun($context->run);
            $audit = ['restore_point' => $request->directory, 'backup_ids' => array_values($request->backupIds),
                'kinds' => array_map(static fn (BackupKind $k): string => $k->value, $request->kinds())] + $audit;
            if ($request->restoresDatabase() && ($context->resultOf(RestoreCheckStep::NAME)['shop'] ?? true) === true && ! $request->acceptDataLoss) {
                throw new RestoreRefused('La base de dades és d\'una botiga i no s\'ha acceptat perdre\'n les dades noves (--accept-data-loss).');
            }
            $driver = ($this->driverFor)($site, $actor);
            foreach ($this->selection->rows($site, $request) as $row) {
                $this->restore->restore($site, $driver, $row, $actor);
                $restored[] = $row->kind;
            }
            $warnings = $this->afterRestore($driver);
        } catch (RunException $e) {
            // The run itself was stopped (timeout, claim lost): audited, never swallowed.
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'reason' => 'run stopped']);

            throw $e;
        } catch (RestoreRefused|DriverException|PostRestoreCheckFailed $e) {
            $reason = $this->redactor->redact($e->getMessage());
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'reason' => $reason]);
            $done = $restored === [] ? ' No s\'ha restaurat res.' : ' Ja s\'han restaurat '.implode(' i ', array_map(self::label(...), $restored)).'.';

            return StepOutcome::failed("{$reason}{$done} {$back}");
        } catch (Throwable $e) {
            $this->record($actor, $site, AuditResult::Failure, $audit + ['restored' => self::values($restored), 'exception' => $e::class]);

            throw $e;
        }

        $this->record($actor, $site, AuditResult::Success, $audit + ['restored' => self::values($restored)]);

        return StepOutcome::succeeded(
            'Restaurat: '.implode(' i ', array_map(self::label(...), $restored))." des de «{$request->directory}». Punt de l'estat d'abans: «{$preRestore}».",
            implode("\n", [...$warnings, $back]),
            ['restore_point' => $request->directory, 'pre_restore_point' => $preRestore, 'restored' => self::values($restored)],
        );
    }

    /**
     * WordPress must still answer through WP-CLI; the object cache is flushed
     * (a failure there is only a warning).
     *
     * @return list<string> warnings
     *
     * @throws PostRestoreCheckFailed
     */
    private function afterRestore(SiteDriver $driver): array
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
            $flush = $driver->wp(['cache', 'flush']);
            $flushed = $flush->successful();
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
