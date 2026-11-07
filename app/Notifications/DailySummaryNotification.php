<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Scheduling\DailySummary;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;

/**
 * The aggregated summary of the scheduled updates (ADR-0006 §4): a warning
 * when a site failed, rolled back or was blocked for automatic updates.
 */
final class DailySummaryNotification extends WpupNotification
{
    /** @var list<string> */
    private readonly array $summary;

    private readonly bool $problems;

    public function __construct(DailySummary $summary)
    {
        parent::__construct();
        $this->summary = $summary->lines();
        $this->problems = $summary->failed !== [] || $summary->blocked !== [];
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::DailySummary;
    }

    public function severity(): NotificationSeverity
    {
        return $this->problems ? NotificationSeverity::Warning : NotificationSeverity::Info;
    }

    public function subject(): string
    {
        return $this->summary[0] ?? 'Resum de les actualitzacions programades';
    }

    public function lines(): array
    {
        return array_slice($this->summary, 1);
    }
}
