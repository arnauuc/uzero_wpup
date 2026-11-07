<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\WpupCommand;
use App\Domain\Notifications\Notifier;
use App\Domain\Scheduling\DailySummaryBuilder;
use App\Notifications\DailySummaryNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduler-only daily summary of the scheduled updates (ADR-0006 §4), not
 * reachable through the `wpup` binary: it goes out as a notification
 * (UZEROWPUP-79). Operators also read it with `wpup summary`. It changes nothing
 * but the delivery records.
 */
final class EngineDailySummary extends Command
{
    protected $signature = 'engine:daily-summary
        {--json : Sortida en JSON, per a scripts}';

    protected $description = 'Resum diari de les actualitzacions programades (ADR-0006)';

    public function handle(DailySummaryBuilder $builder, Notifier $notifier): int
    {
        $to = CarbonImmutable::now();
        $summary = $builder->build($to->subHours(config()->integer('wpup.scheduler.daily_summary.hours')), $to);

        $notifier->notify(new DailySummaryNotification($summary));
        if ($this->option('json') === true) {
            $this->line((string) json_encode($summary->toArray(), WpupCommand::JSON_FLAGS));

            return self::SUCCESS;
        }
        foreach ($summary->lines() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
