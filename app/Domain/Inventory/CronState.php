<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * WP-Cron state of a site (UZEROWPUP-33). WP-Cron runs on traffic, so events
 * count as overdue only past a grace period.
 */
final readonly class CronState
{
    public function __construct(
        /** DISABLE_WP_CRON: a system cron is expected to call wp-cron.php. */
        public bool $disabled,
        public int $overdueEvents,
        public ?int $oldestOverdueSeconds,
    ) {}

    /**
     * @return array{disabled: bool, overdue_events: int, oldest_overdue_seconds: ?int}
     */
    public function toArray(): array
    {
        return ['disabled' => $this->disabled, 'overdue_events' => $this->overdueEvents, 'oldest_overdue_seconds' => $this->oldestOverdueSeconds];
    }
}
