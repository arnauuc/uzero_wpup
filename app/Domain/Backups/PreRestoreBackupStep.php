<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupKind;
use App\Enums\RunType;
use App\Models\Site;
use Closure;

/**
 * The verified restore point of the current state that a restore makes first
 * (UZEROWPUP-46, AGENTS.md rule 3), under the run's key ("site-{id}/run{id}").
 * Its code archive leaves out only what the restored archive left out
 * (RestoreCheckStep), so it covers everything the restore may overwrite or
 * delete (uploads included when the restored archive has them).
 *
 * A manual rollback (UZEROWPUP-61) makes it the same way, after its own
 * check step ($checkStep, which says what the archive left out).
 *
 * If it cannot be made, nothing is restored, unless the operator asked for
 * --without-pre-backup (interactive only, for a site too broken to back up):
 * then the run goes on without it, and says so.
 */
final readonly class PreRestoreBackupStep implements Step
{
    public const string NAME = BackupStep::NAME;

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestorePointService $restorePoints,
        private RestoreMaintenanceStep $maintenance,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
        private string $checkStep = RestoreCheckStep::NAME,
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
        return "Farà un punt de restauració verificat de l'estat actual del lloc (BD i tot el que la restauració pot sobreescriure o esborrar) abans de restaurar; si no el pot fer, no restaurarà res.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = RestoreCheckStep::actor($this->authorization, $context->run);
        $site = $context->run->site;
        if (is_string($actor) || $site === null) {
            return StepOutcome::failed(is_string($actor) ? $actor : "L'execució no té cap lloc.");
        }

        $excludes = $context->resultOf($this->checkStep)['pre_restore_excludes'] ?? null;
        $excludes = is_array($excludes) ? array_values(array_filter($excludes, is_string(...))) : null;
        // Validated by the restore's check step; a manual rollback never goes without it (UZEROWPUP-61).
        $withoutPreBackup = $context->run->type === RunType::Restore && ($context->run->flags[RestoreRequest::WITHOUT_PRE_BACKUP_FLAG] ?? false) === true;

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut connectar amb el lloc: no es restaura res. {$this->redactor->redact($e->getMessage())}");
        }

        try {
            $point = $this->restorePoints->create($site, $driver, $context->run, $actor, $excludes);
        } catch (BackupFailed $e) {
            $reason = $this->redactor->redact($e->getMessage());
            if (! $withoutPreBackup) {
                $note = $this->maintenance->restoreState($driver, $context, on: false);

                return StepOutcome::failed(trim("No s'ha pogut fer el punt de restauració de l'estat actual: no es restaura res. {$reason} ".($note ?? '')));
            }

            return StepOutcome::succeeded(
                "Sense punt de l'estat actual (--without-pre-backup): {$reason}",
                $reason,
                ['directory' => null, 'skipped' => true],
            );
        }

        $summary = sprintf(
            "Punt de l'estat actual verificat%s: BD %s, codi %s",
            $point->reused ? ' (ja fet)' : '',
            ByteSize::human($point->local(BackupKind::Database)->size_bytes ?? 0),
            ByteSize::human($point->local(BackupKind::Files)->size_bytes ?? 0),
        );

        return StepOutcome::succeeded($summary, implode("\n", $point->warnings), $point->summary());
    }
}
