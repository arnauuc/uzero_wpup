<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Audit\AuditActor;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;

/**
 * Queues the tool backup run for the workers (ADR-0011): `wpup tool:backup`
 * and the daily schedule (routes/console.php), which does not wait for it.
 */
final readonly class ToolBackupLauncher
{
    public function __construct(private RunLauncher $launcher) {}

    public function launch(AuditActor $actor, bool $dryRun = false): Run
    {
        return $this->launcher->launch(RunType::ToolBackup, [null], $actor, ['dry_run' => $dryRun])[0];
    }

    /**
     * The daily run, unless one is still queued or running (a stuck worker
     * must not pile up a copy per day behind it).
     */
    public function scheduled(): ?Run
    {
        $active = Run::query()->where('type', RunType::ToolBackup)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists();

        return $active ? null : $this->launch(AuditActor::system("còpia diària de l'eina"));
    }
}
