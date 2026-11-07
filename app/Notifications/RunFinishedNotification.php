<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Runs\RunInterruption;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunStep;

/**
 * A run reached its final state. A rollback is its own kind (the site was
 * put back: a warning); a failure is an error; a success with warnings (of
 * its steps or of the run) or interruptions is a warning.
 */
final class RunFinishedNotification extends WpupNotification
{
    private readonly RunStatus $status;

    private readonly string $title;

    /** @var list<string> */
    private readonly array $details;

    /** Warnings or interruptions: a success worth a look. */
    private readonly bool $noteworthy;

    public function __construct(Run $run)
    {
        parent::__construct($run->site_id, $run->id);
        $this->status = $run->status;
        $this->title = "Execució #{$run->id} ({$run->type->value}) de {$run->targetLabel()}: ".match ($run->status) {
            RunStatus::Succeeded => 'correcta',
            RunStatus::RolledBack => 'revertida',
            RunStatus::Failed => 'ha fallat',
            default => $run->status->value,
        };

        $details = [];
        $reason = $run->failureReason();
        if ($reason !== null) {
            $step = $run->result_summary['failed_step'] ?? null;
            $details[] = 'Motiu: '.$reason.(is_string($step) && $step !== '' ? " (pas «{$step}»)" : '');
        }
        foreach (self::warnings($run) as $warning) {
            $details[] = "Avís: {$warning}";
        }
        foreach (RunInterruption::of($run) as $interruption) {
            $details[] = $interruption->describe();
        }
        $this->noteworthy = $details !== [];
        $details[] = self::reportLine($run->id);
        $this->details = $details;
    }

    /**
     * The warnings of the run where they are recorded: those of its steps
     * (`result.warnings`: smoke check, PHP log, restore check, verification…)
     * and those added to the run afterwards (result_summary.warnings), once each.
     *
     * @return list<string>
     */
    private static function warnings(Run $run): array
    {
        $sources = $run->steps()->orderBy('sequence')->get()->map(static fn (RunStep $step): mixed => $step->result['warnings'] ?? null)->all();
        $sources[] = $run->result_summary['warnings'] ?? null;
        $warnings = [];
        foreach ($sources as $list) {
            foreach (is_array($list) ? $list : [] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[$warning] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($warnings));
    }

    public function kind(): NotificationKind
    {
        return $this->status === RunStatus::RolledBack ? NotificationKind::RunRolledBack : NotificationKind::RunFinished;
    }

    public function severity(): NotificationSeverity
    {
        return match ($this->status) {
            RunStatus::Succeeded => $this->noteworthy ? NotificationSeverity::Warning : NotificationSeverity::Info,
            RunStatus::RolledBack => NotificationSeverity::Warning,
            default => NotificationSeverity::Error,
        };
    }

    public function subject(): string
    {
        return $this->title;
    }

    public function lines(): array
    {
        return $this->details;
    }
}
