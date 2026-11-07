<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Scheduling\DailySummaryBuilder;
use Carbon\CarbonImmutable;

/**
 * `wpup summary`: the summary of the scheduled updates (ADR-0006 §4) of the
 * last hours or days, the one engine:daily-summary produces every morning.
 */
final class SummaryCommand extends WpupCommand
{
    protected $signature = 'summary
        {--since=24h : Període: hores (24h) o dies (7d) fins ara}
        '.self::JSON;

    protected $description = 'Resum de les actualitzacions programades (per defecte, de les últimes 24 hores)';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        $since = $this->stringOption('since') ?? '24h';
        if (preg_match('/^([1-9]\d{0,3})([hd])$/', $since, $m) !== 1) {
            $this->error('--since ha de ser un nombre d\'hores o de dies: «24h», «7d».');

            return self::FAILURE;
        }
        $to = CarbonImmutable::now();
        $from = $m[2] === 'h' ? $to->subHours((int) $m[1]) : $to->subDays((int) $m[1]);
        $summary = app(DailySummaryBuilder::class)->build($from, $to);

        if ($this->wantsJson()) {
            $this->emitJson($summary->toArray());

            return self::SUCCESS;
        }
        foreach ($summary->lines() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
