<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * Which of the tool's backups to keep (ADR-0009 §3: 30 daily and 12 monthly,
 * the monthly ones being the first of each month). Pure: it only reads the
 * time in each key.
 *
 * - Daily: the latest copy of each of the `daily` most recent days that have
 *   one. A manual run the same day replaces that day's copy instead of
 *   pushing out an older day, and a gap (the VM was off) never shortens the
 *   history.
 * - Monthly: the first copy of each of the `monthly` most recent months that
 *   have one.
 * - Keys that are not ToolBackupKey keys are ignored: never deleted.
 */
final readonly class ToolBackupRetention
{
    public function __construct(
        private int $daily,
        private int $monthly,
    ) {}

    /**
     * @param  list<string>  $keys  every key under ToolBackupKey::PREFIX
     */
    public function plan(array $keys): ToolRetentionPlan
    {
        /** @var array<string, string> $times key => sortable time */
        $times = [];
        foreach ($keys as $key) {
            $at = ToolBackupKey::timeOf($key);
            if ($at !== null) {
                $times[$key] = $at->format('Y-m-d H:i:s');
            }
        }
        // Oldest first; equal times (impossible for distinct keys) by key.
        uksort($times, static fn (string $a, string $b): int => [$times[$a], $a] <=> [$times[$b], $b]);

        $latestOfDay = [];
        $firstOfMonth = [];
        foreach ($times as $key => $time) {
            $latestOfDay[substr($time, 0, 10)] = $key;
            $firstOfMonth[substr($time, 0, 7)] ??= $key;
        }
        krsort($latestOfDay);
        krsort($firstOfMonth);

        $keep = array_merge(
            array_slice(array_values($latestOfDay), 0, max(0, $this->daily)),
            array_slice(array_values($firstOfMonth), 0, max(0, $this->monthly)),
        );
        $keep = array_values(array_filter(array_keys($times), static fn (string $key): bool => in_array($key, $keep, true)));
        $delete = array_values(array_diff(array_keys($times), $keep));

        return new ToolRetentionPlan($keep, $delete);
    }
}
