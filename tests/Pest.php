<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Migrations run as wpup_owner before the suite (`make test`); tests run as wpup_app
// inside a transaction that is rolled back, so RefreshDatabase is not used.
pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature');
