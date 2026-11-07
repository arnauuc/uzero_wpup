<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use Carbon\CarbonImmutable;

/**
 * The aggregated summary of the scheduled updates of a period (ADR-0006 §4):
 * updated, skipped (with the reason), failed or rolled back, blocked for
 * automatic updates, deferred to their next window, still in progress, and
 * the majors that wait for staging. Each run keeps its own report.
 *
 * @phpstan-type Row array{site: string, reason: ?string, run_id: ?int}
 */
final readonly class DailySummary
{
    /**
     * @param  list<Row>  $updated
     * @param  list<Row>  $skipped
     * @param  list<Row>  $failed
     * @param  list<Row>  $blocked
     * @param  list<Row>  $deferred
     * @param  list<Row>  $inProgress
     * @param  array<string, list<string>>  $majors  by site
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $updated,
        public array $skipped,
        public array $failed,
        public array $blocked,
        public array $deferred,
        public array $inProgress,
        public array $majors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'blocked' => $this->blocked,
            'deferred' => $this->deferred,
            'in_progress' => $this->inProgress,
            'majors_waiting_staging' => $this->majors,
        ];
    }

    /**
     * Operator-facing text, in Catalan.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [sprintf('Resum de les actualitzacions programades del %s al %s', $this->from->format('d/m/Y H:i'), $this->to->format('d/m/Y H:i'))];
        foreach ([
            'Actualitzats' => $this->updated,
            'Omesos' => $this->skipped,
            'Han fallat o s\'han revertit' => $this->failed,
            'Bloquejats per a l\'actualització automàtica' => $this->blocked,
            'Pendents per a la seva finestra següent' => $this->deferred,
            'En curs' => $this->inProgress,
        ] as $title => $rows) {
            $lines[] = sprintf('%s: %d', $title, count($rows));
            foreach ($rows as $row) {
                $lines[] = '  - '.$row['site'].($row['run_id'] === null ? '' : " (#{$row['run_id']})").($row['reason'] === null ? '' : ": {$row['reason']}");
            }
        }
        $lines[] = sprintf('Majors que esperen staging: %d', array_sum(array_map(count(...), $this->majors)));
        foreach ($this->majors as $site => $changes) {
            $lines[] = "  - {$site}: ".implode(', ', $changes);
        }

        return $lines;
    }
}
