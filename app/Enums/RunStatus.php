<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Run state machine (UZEROWPUP-21). Only RunEngine and RunRecovery change it,
 * always through a validated transition.
 */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case RollingBack = 'rolling_back';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Queued => [self::Running, self::Cancelled, self::Skipped],
            self::Running => [self::Succeeded, self::Failed, self::RollingBack, self::Interrupted],
            self::RollingBack => [self::RolledBack, self::Failed, self::Interrupted],
            self::Interrupted => [self::Running, self::RollingBack, self::Failed],
            self::Succeeded, self::Failed, self::RolledBack, self::Cancelled, self::Skipped => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::RolledBack, self::Cancelled, self::Skipped], true);
    }

    /**
     * States in which a worker must be holding the run's claim.
     */
    public function isActive(): bool
    {
        return $this === self::Running || $this === self::RollingBack;
    }
}
