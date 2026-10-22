<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Step state machine (UZEROWPUP-21). Running → Pending only happens when an
 * interrupted run is recovered: the step is idempotent and runs again.
 */
enum RunStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Running, self::Skipped, self::Failed],
            self::Running => [self::Succeeded, self::Failed, self::Skipped, self::Pending],
            self::Succeeded, self::Failed, self::Skipped => [],
        }, true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }
}
