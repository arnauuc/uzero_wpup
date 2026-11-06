<?php

declare(strict_types=1);

use App\Domain\Planning\FutureMinimums;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

it('ships a table of future minimums that parses', function (): void {
    expect(fn () => FutureMinimums::fromConfig(config('wpup.future_minimums')))->not->toThrow(UnexpectedValueException::class);
});

it('ignores a malformed table and logs the error once, without breaking anything', function (): void {
    config(['wpup.future_minimums' => [['component' => 'woocommerce']]]);
    app()->forgetInstance(FutureMinimums::class);
    Log::partialMock()->shouldReceive('error')->once();

    $now = CarbonImmutable::parse('2026-11-06', 'UTC');

    expect(app(FutureMinimums::class)->warnings('7.4', true, '6.9', '11.5', $now))->toBe([])
        ->and(app(FutureMinimums::class)->warnings('7.4', true, '6.9', '11.5', $now))->toBe([]);
});
