<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Enums\RunStatus;
use App\Enums\ScheduledWindowStatus;
use App\Models\ScheduledWindow;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Builds the DailySummary of a period from what the scheduler recorded
 * (scheduled_windows changed in it) and the sites blocked for automatic
 * updates in it. Reads only.
 */
final readonly class DailySummaryBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): DailySummary
    {
        $windows = ScheduledWindow::query()->with('site')
            ->where('updated_at', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('updated_at', '<=', $to->format('Y-m-d H:i:sP'))
            ->orderBy('updated_at')->orderBy('id')->get();

        $rows = ['updated' => [], 'skipped' => [], 'failed' => [], 'deferred' => [], 'in_progress' => []];
        $majors = [];
        foreach ($windows as $window) {
            $site = $window->site->name ?? "#{$window->site_id}";
            $details = $window->details;
            $nothingToDo = ($details['nothing_to_do'] ?? false) === true;
            $updated = is_int($details['updated'] ?? null) ? $details['updated'] : null;
            [$group, $reason] = match ($window->status) {
                ScheduledWindowStatus::Succeeded => $nothingToDo
                    ? ['skipped', 'res a aplicar (comprovat per l\'execució)']
                    : ['updated', $updated === null ? null : "{$updated} components"],
                ScheduledWindowStatus::Failed => ['failed', (($details['run_status'] ?? null) === RunStatus::RolledBack->value ? 'revertit' : 'ha fallat').($window->reason === null ? '' : ": {$window->reason}")],
                ScheduledWindowStatus::Skipped, ScheduledWindowStatus::Cancelled => ['skipped', $window->reason],
                ScheduledWindowStatus::Deferred => ['deferred', $window->reason],
                ScheduledWindowStatus::Pending, ScheduledWindowStatus::Launched => ['in_progress', $window->status->label()],
            };
            $rows[$group][] = ['site' => $site, 'reason' => $reason, 'run_id' => $window->run_id];

            $pending = [...self::strings($details['majors'] ?? null), ...self::strings(is_array($details['outlook'] ?? null) ? ($details['outlook']['majors'] ?? null) : null)];
            if ($pending !== []) {
                $majors[$site] = array_values(array_unique([...($majors[$site] ?? []), ...$pending]));
            }
        }

        $blocked = array_values(Site::query()
            ->where('auto_update_blocked_at', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('auto_update_blocked_at', '<=', $to->format('Y-m-d H:i:sP'))
            ->orderBy('name')->get()
            ->map(static fn (Site $s): array => ['site' => $s->name, 'reason' => $s->auto_update_blocked_reason, 'run_id' => null])
            ->all());

        return new DailySummary($from, $to, $rows['updated'], $rows['skipped'], $rows['failed'], $blocked, $rows['deferred'], $rows['in_progress'], $majors);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
