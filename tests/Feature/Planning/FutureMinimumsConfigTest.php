<?php

declare(strict_types=1);

use App\Domain\Planning\FutureMinimums;
use Carbon\CarbonImmutable;

it('ships a valid table of future minimums in the configuration', function (): void {
    expect(app(FutureMinimums::class)->warnings('7.4', true, '6.9', '11.5', CarbonImmutable::parse('2026-11-06', 'UTC')))->toHaveCount(1);
});
