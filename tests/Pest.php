<?php

declare(strict_types=1);

use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

use function Pest\Laravel\artisan;

// Migrations run as wpup_owner before the suite (`make test`); tests run as wpup_app
// inside a transaction that is rolled back, so RefreshDatabase is not used.
pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature', 'Integration');

// Tests that drive a separate worker process cannot use a transaction: the
// process must see the data. They clean up after themselves.
pest()->extend(TestCase::class)->in('Process');

/**
 * @param  array<string, mixed>  $parameters
 */
function command(string $name, array $parameters = []): PendingCommand
{
    $result = artisan($name, $parameters);

    return $result instanceof PendingCommand ? $result : throw new LogicException('Mocked console output is disabled.');
}

/**
 * Runs the next commands as $unixUser invoking `sudo -u wpup wpup ...`.
 */
function actingAsOperator(string $unixUser): void
{
    app()->instance(ProcessIdentity::class, new ProcessIdentity('wpup', $unixUser));
    app()->instance(OperatorResolver::class, new OperatorResolver('sudo', 'wpup', 'testing'));
}
