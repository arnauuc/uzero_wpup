<?php

declare(strict_types=1);

use App\Domain\Drivers\SiteDriver;
use Tests\Contracts\SiteDriverContract;
use Tests\Support\FakeDriver;

// The fake must behave like the real drivers, so it runs the same contract.
SiteDriverContract::register(fn (): SiteDriver => new FakeDriver, 'FakeDriver');
