<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Audit\AuditActor;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use Illuminate\Support\Facades\Log;

/**
 * Queues the tool backup run for the workers (ADR-0011): `wpup tool:backup`
 * and the daily schedule (routes/console.php), which does not wait for it.
 * One at a time: while a tool backup run has not finished (queued, running,
 * rolling back, interrupted…), no other is queued.
 */
final readonly class ToolBackupLauncher
{
    public function __construct(private RunLauncher $launcher) {}

    /**
     * @throws ToolBackupException when a tool backup run has not finished yet
     */
    public function launch(AuditActor $actor, bool $dryRun = false): Run
    {
        $active = $this->active();
        if ($active !== null) {
            throw new ToolBackupException("Ja n'hi ha una en curs: l'execució #{$active->id} de la còpia de l'eina encara no ha acabat ({$active->status->value}).");
        }

        return $this->launcher->launch(RunType::ToolBackup, [null], $actor, ['dry_run' => $dryRun])[0];
    }

    /**
     * The daily run. A deliberate skip (one still unfinished, so a stuck
     * worker does not pile up a copy per day behind it) is logged, not an error.
     */
    public function scheduled(): ?Run
    {
        try {
            return $this->launch(AuditActor::system("còpia diària de l'eina"));
        } catch (ToolBackupException $e) {
            Log::info("No s'encua la còpia diària de l'eina: {$e->getMessage()}");

            return null;
        }
    }

    private function active(): ?Run
    {
        $terminal = array_values(array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isTerminal()));

        return Run::query()->where('type', RunType::ToolBackup)->whereNotIn('status', $terminal)->orderBy('id')->first();
    }
}
