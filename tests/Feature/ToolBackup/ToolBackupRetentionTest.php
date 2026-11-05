<?php

declare(strict_types=1);

use App\Domain\ToolBackup\ToolBackupKey;
use App\Domain\ToolBackup\ToolBackupRetention;
use Carbon\CarbonImmutable;

/*
 * Retention of the tool's backups (ADR-0009 §3, UZEROWPUP-47): the latest copy
 * of each of the 30 most recent days, and the first of each of the 12 most
 * recent months. Pure logic over the keys.
 */

/**
 * One key per day at 03:00, from $from for $days days.
 *
 * @return list<string>
 */
function toolBackupDailyKeys(string $from, int $days): array
{
    $start = CarbonImmutable::parse($from.' 03:00:00');

    return array_map(static fn (int $i): string => ToolBackupKey::for($start->addDays($i)), range(0, $days - 1));
}

it('builds lowercase keys from the copy time and reads it back', function (): void {
    $key = ToolBackupKey::for(CarbonImmutable::parse('2026-11-05 03:04:05'));

    expect($key)->toBe('tool/wpup-20261105-030405.tar.age')
        ->and(ToolBackupKey::timeOf($key)?->format('Y-m-d H:i:s'))->toBe('2026-11-05 03:04:05')
        ->and(ToolBackupKey::timeOf('tool/wpup-20261332-000000.tar.age'))->toBeNull()
        ->and(ToolBackupKey::timeOf('tool/other.tar.age'))->toBeNull()
        ->and(ToolBackupKey::timeOf('site-1/wpup-20261105-030405.tar.age'))->toBeNull();
});

it('keeps everything while there are fewer than 30 days of copies', function (): void {
    $keys = toolBackupDailyKeys('2026-10-20', 12);
    $plan = (new ToolBackupRetention(30, 12))->plan(array_reverse($keys));

    expect($plan->keep)->toBe($keys)->and($plan->delete)->toBe([]);
});

it('keeps the last 30 days and the first copy of each older month', function (): void {
    // 2026-08-01 … 2026-11-04: 96 days over four months.
    $keys = toolBackupDailyKeys('2026-08-01', 96);
    $plan = (new ToolBackupRetention(30, 12))->plan($keys);

    $expected = [
        'tool/wpup-20260801-030000.tar.age',
        'tool/wpup-20260901-030000.tar.age',
        'tool/wpup-20261001-030000.tar.age',
        ...array_slice($keys, -30),
    ];
    // 2026-11-01 is both a daily (within the last 30) and November's monthly.
    expect($plan->keep)->toBe($expected)
        ->and($plan->delete)->toHaveCount(96 - 33)
        ->and(array_intersect($plan->keep, $plan->delete))->toBe([]);
});

it('drops the monthly copies older than the 12 most recent months', function (): void {
    $keys = [];
    for ($m = 0; $m < 15; $m++) {
        $keys[] = ToolBackupKey::for(CarbonImmutable::parse('2025-08-01 03:00:00')->addMonths($m));
    }
    $plan = (new ToolBackupRetention(0, 12))->plan($keys);

    expect($plan->keep)->toBe(array_slice($keys, 3))
        ->and($plan->delete)->toBe(array_slice($keys, 0, 3));
});

it('takes the first copy of the month across a month boundary', function (): void {
    $keys = ['tool/wpup-20260131-235959.tar.age', 'tool/wpup-20260201-000000.tar.age', 'tool/wpup-20260215-030000.tar.age'];
    $plan = (new ToolBackupRetention(1, 12))->plan($keys);

    // Daily: 15 Feb. Monthly: 31 Jan (January) and 1 Feb (February), never 15 Feb as February's.
    expect($plan->keep)->toBe($keys)->and((new ToolBackupRetention(1, 1))->plan($keys)->keep)
        ->toBe(['tool/wpup-20260201-000000.tar.age', 'tool/wpup-20260215-030000.tar.age']);
});

it('keeps only the latest copy of a day, unless it is the first of its month', function (): void {
    $keys = [
        'tool/wpup-20261101-030000.tar.age',
        'tool/wpup-20261101-140000.tar.age',
        'tool/wpup-20261102-030000.tar.age',
        'tool/wpup-20261102-120000.tar.age',
    ];
    $plan = (new ToolBackupRetention(30, 12))->plan($keys);

    expect($plan->keep)->toBe(['tool/wpup-20261101-030000.tar.age', 'tool/wpup-20261101-140000.tar.age', 'tool/wpup-20261102-120000.tar.age'])
        ->and($plan->delete)->toBe(['tool/wpup-20261102-030000.tar.age']);
});

it('counts days and months that have copies, so a gap never shortens the history', function (): void {
    // Copies every third day for 120 days, then nothing for months.
    $keys = array_values(array_filter(toolBackupDailyKeys('2026-01-01', 120), static fn (string $k, int $i): bool => $i % 3 === 0, ARRAY_FILTER_USE_BOTH));
    $plan = (new ToolBackupRetention(30, 12))->plan($keys);

    expect($plan->keep)->toContain(...array_slice($keys, -30))
        ->and($plan->keep)->toContain('tool/wpup-20260101-030000.tar.age', 'tool/wpup-20260203-030000.tar.age')
        ->and(count($plan->keep) + count($plan->delete))->toBe(count($keys));
});

it('never deletes keys it did not create', function (): void {
    $keys = ['tool/notes.txt', 'tool/wpup-latest.tar.age', ...toolBackupDailyKeys('2026-09-01', 40)];
    $plan = (new ToolBackupRetention(30, 12))->plan($keys);

    expect($plan->delete)->not->toContain('tool/notes.txt', 'tool/wpup-latest.tar.age')
        ->and($plan->keep)->not->toContain('tool/notes.txt');
});
