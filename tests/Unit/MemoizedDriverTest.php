<?php

declare(strict_types=1);

use App\Domain\Compatibility\MemoizedDriver;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;

it('makes the driver once', function (): void {
    $calls = 0;
    $driver = Mockery::mock(SiteDriver::class);
    assert($driver instanceof SiteDriver);
    $memo = new MemoizedDriver(function () use (&$calls, $driver): SiteDriver {
        $calls++;

        return $driver;
    });

    expect($memo())->toBe($driver)->and($memo())->toBe($driver)->and($calls)->toBe(1);
});

it('remembers a failure instead of trying again', function (): void {
    $calls = 0;
    $memo = new MemoizedDriver(function () use (&$calls): SiteDriver {
        $calls++;

        throw new ConnectionFailed('sense connexió');
    });

    expect(fn () => $memo())->toThrow(ConnectionFailed::class);
    expect(fn () => $memo())->toThrow(ConnectionFailed::class);
    expect($calls)->toBe(1);
});
