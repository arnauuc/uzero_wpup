<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Runs\InterruptionDecision;
use App\Domain\Runs\RunInterruption;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Models\Run;

/**
 * A run lost its worker and the engine picked it up again (UZEROWPUP-62): a
 * warning, or an error when the engine cannot go on with it.
 */
final class RunInterruptedNotification extends WpupNotification
{
    private readonly string $title;

    private readonly string $description;

    private readonly bool $fails;

    public function __construct(Run $run, RunInterruption $interruption)
    {
        parent::__construct($run->site_id, $run->id);
        $this->title = "Execució #{$run->id} ({$run->type->value}) de {$run->targetLabel()}: interrompuda";
        $this->description = $interruption->describe();
        $this->fails = $interruption->decision === InterruptionDecision::Fail;
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::RunInterrupted;
    }

    public function severity(): NotificationSeverity
    {
        return $this->fails ? NotificationSeverity::Error : NotificationSeverity::Warning;
    }

    public function subject(): string
    {
        return $this->title;
    }

    public function lines(): array
    {
        return [$this->description, self::reportLine((int) $this->runId)];
    }
}
