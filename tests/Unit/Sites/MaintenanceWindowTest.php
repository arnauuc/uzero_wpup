<?php

declare(strict_types=1);

use App\Domain\Sites\MaintenanceWindow;
use Carbon\CarbonImmutable;

function madrid(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Europe/Madrid');
}

it('is open between its start and end on its days', function (): void {
    // 2026-11-02 is a Monday.
    $window = new MaintenanceWindow([1, 2, 3, 4, 5], '10:00', '13:00', 'Europe/Madrid');

    expect($window->closesAt(madrid('2026-11-02 10:00'))?->format('Y-m-d H:i'))->toBe('2026-11-02 13:00')
        ->and($window->closesAt(madrid('2026-11-02 12:59')))->not->toBeNull()
        ->and($window->closesAt(madrid('2026-11-02 13:00')))->toBeNull()
        ->and($window->closesAt(madrid('2026-11-02 09:59')))->toBeNull()
        // Saturday is not one of its days.
        ->and($window->closesAt(madrid('2026-11-07 11:00')))->toBeNull()
        ->and($window->nextOpening(madrid('2026-11-02 13:30'))->format('Y-m-d H:i'))->toBe('2026-11-03 10:00')
        ->and($window->nextOpening(madrid('2026-11-06 13:30'))->format('Y-m-d H:i'))->toBe('2026-11-09 10:00')
        ->and($window->label())->toBe('dl,dt,dc,dj,dv 10:00–13:00');
});

it('belongs to the day it starts on when it crosses midnight', function (): void {
    // Only on Sundays, from 23:00 to 02:00.
    $window = new MaintenanceWindow([7], '23:00', '02:00', 'Europe/Madrid');

    expect($window->closesAt(madrid('2026-11-08 23:30'))?->format('Y-m-d H:i'))->toBe('2026-11-09 02:00')
        // Monday 01:00 is still Sunday's window.
        ->and($window->closesAt(madrid('2026-11-09 01:00')))->not->toBeNull()
        // Sunday 01:00 belongs to Saturday's window, which does not exist.
        ->and($window->closesAt(madrid('2026-11-08 01:00')))->toBeNull()
        ->and($window->nextOpening(madrid('2026-11-09 01:00'))->format('Y-m-d H:i'))->toBe('2026-11-15 23:00');
});

it('follows the wall clock across a daylight saving change', function (): void {
    // 2026-10-25: clocks go back at 03:00 in Madrid.
    $window = new MaintenanceWindow([1, 2, 3, 4, 5, 6, 7], '01:00', '06:00', 'Europe/Madrid');

    expect($window->closesAt(madrid('2026-10-25 05:30'))?->format('Y-m-d H:i T'))->toBe('2026-10-25 06:00 CET')
        ->and($window->closesAt(CarbonImmutable::parse('2026-10-25 05:30', 'UTC')))->toBeNull();
});

it('moves a start inside the hour skipped by the clocks to the same time one hour later', function (): void {
    // 2026-03-29: clocks go forward at 02:00 in Madrid; 02:30 does not exist that night.
    $window = new MaintenanceWindow([7], '02:30', '05:00', 'Europe/Madrid');

    expect($window->closesAt(madrid('2026-03-29 03:15')))->toBeNull()
        ->and($window->closesAt(madrid('2026-03-29 03:45'))?->format('Y-m-d H:i T'))->toBe('2026-03-29 05:00 CEST')
        ->and($window->nextOpening(madrid('2026-03-28 12:00'))->format('Y-m-d H:i T'))->toBe('2026-03-29 03:30 CEST');
});

it('never turns a window inside the skipped hour into a whole-day one', function (): void {
    // 02:15–02:45 lies entirely in the skipped hour on 2026-03-29: both move one hour later.
    $window = new MaintenanceWindow([7], '02:15', '02:45', 'Europe/Madrid');

    expect($window->closesAt(madrid('2026-03-29 03:30'))?->format('H:i T'))->toBe('03:45 CEST')
        ->and($window->closesAt(madrid('2026-03-29 12:00')))->toBeNull()
        ->and($window->closesAt(madrid('2026-03-30 01:00')))->toBeNull();
});

it('gives the same reasons to the site commands and to the gate', function (): void {
    expect(MaintenanceWindow::errors([1], '05:50', '06:00', 15))->toBe(['La finestra ha de durar com a mínim 15 minuts, el temps mínim per començar una actualització.'])
        ->and(MaintenanceWindow::errors([1], '23:50', '00:10', 15))->toBe([])
        ->and(MaintenanceWindow::errors([0], '01:00', '06:00'))->toHaveCount(1)
        ->and((new MaintenanceWindow([1], '23:00', '02:00', 'Europe/Madrid'))->lengthMinutes())->toBe(180);
});

it('rejects malformed windows', function (): void {
    expect(fn () => new MaintenanceWindow([], '01:00', '06:00', 'Europe/Madrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MaintenanceWindow([8], '01:00', '06:00', 'Europe/Madrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MaintenanceWindow([1], '1:00', '06:00', 'Europe/Madrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MaintenanceWindow([1], '06:00', '06:00', 'Europe/Madrid'))->toThrow(InvalidArgumentException::class);
});
