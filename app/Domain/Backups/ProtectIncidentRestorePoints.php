<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Runs\Events\RunFinished;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Models\Backup;
use App\Models\Run;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-0009: the restore point of a reverted update, or of a run with an
 * incident, stays protected until an operator reviews the run (`run:ack`).
 * "Incident" is read conservatively: any run that ends rolled back or failed
 * and left a restore point (a failed run may have changed the site before
 * failing, or its rollback may have failed).
 *
 * RestorePointRetention also holds those points while the run is not reviewed,
 * so a failure here is logged and does not fail the run's end.
 */
final readonly class ProtectIncidentRestorePoints
{
    public function __construct(
        private AuditLogger $audit,
        private SecretRedactor $redactor,
    ) {}

    public static function isIncident(Run $run): bool
    {
        return in_array($run->status, [RunStatus::RolledBack, RunStatus::Failed], true);
    }

    public function handle(RunFinished $event): void
    {
        $run = $event->run;
        if (! self::isIncident($run) || $run->reviewed_at !== null) {
            return;
        }

        try {
            $protected = Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')->where('protected', false)->update(['protected' => true]);
            if ($protected > 0) {
                $this->audit->record(AuditActor::system(), 'backups.protected', AuditResult::Success, 'run', $run->id, [
                    'status' => $run->status->value,
                    'backups' => $protected,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Could not protect the restore point of a run that ended with an incident.', [
                'run_id' => $run->id, 'exception' => $e::class, 'reason' => $this->redactor->redact($e->getMessage()),
            ]);
        }
    }
}
