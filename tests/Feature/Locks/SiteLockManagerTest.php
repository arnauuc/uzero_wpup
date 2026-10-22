<?php

declare(strict_types=1);

use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLease;
use App\Domain\Locks\SiteLockManager;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\HostingAccount;
use App\Models\Site;
use App\Models\User;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    // Dedicated test database of Redis (REDIS_LOCKS_DB in phpunit.xml).
    Redis::connection('locks')->command('flushdb');
});

function locks(int $ttl = 60): SiteLockManager
{
    $connection = Redis::connection('locks');

    return $connection instanceof PhpRedisConnection
        ? new SiteLockManager($connection, $ttl)
        : throw new LogicException('Locks need phpredis.');
}

/**
 * @param  array<string, mixed>  $parameters
 */
function lockCommand(string $name, array $parameters = []): PendingCommand
{
    $result = artisan($name, $parameters);

    return $result instanceof PendingCommand ? $result : throw new LogicException('Mocked console output is disabled.');
}

function lockedBy(Closure $attempt): LockedException
{
    try {
        $attempt();
    } catch (LockedException $e) {
        return $e;
    }

    throw new LogicException('Expected the site to be locked.');
}

it('gives a site to one run at a time and says who holds it', function (): void {
    $site = Site::factory()->create();
    locks()->acquire($site, 'anna', 7);

    $e = lockedBy(fn () => locks()->acquire($site, 'joan', 8));

    expect($e->level)->toBe(LockLevel::Site)
        ->and($e->holder?->actor)->toBe('anna')
        ->and($e->holder?->runId)->toBe(7)
        ->and($e->getMessage())->toContain('anna')
        ->and($e->getMessage())->toContain('#7');
});

it('blocks another site of the same hosting account', function (): void {
    $account = HostingAccount::factory()->create();
    $a = Site::factory()->for($account)->create();
    $b = Site::factory()->for($account)->create();

    locks()->acquire($a, 'anna');

    expect(lockedBy(fn () => locks()->acquire($b, 'joan'))->level)->toBe(LockLevel::HostingAccount);
});

it('lets sites of different hosting accounts run in parallel', function (): void {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    locks()->acquire($a, 'anna');
    $lease = locks()->acquire($b, 'joan');

    expect($lease->siteId)->toBe($b->id);
});

it('takes nothing when one of the two locks is busy', function (): void {
    $account = HostingAccount::factory()->create();
    $a = Site::factory()->for($account)->create();
    $b = Site::factory()->for($account)->create();
    locks()->acquire($a, 'anna');

    try {
        locks()->acquire($b, 'joan');
    } catch (LockedException) {
    }

    expect(locks()->holder(LockLevel::Site, $b->id))->toBeNull();
});

it('frees both locks on release', function (): void {
    $site = Site::factory()->create();
    locks()->acquire($site, 'anna')->release();

    expect(locks()->status())->toBe([])
        ->and(locks()->acquire($site, 'joan')->siteId)->toBe($site->id);
});

it('does not let another owner release or refresh a lease', function (): void {
    $site = Site::factory()->create();
    $lease = locks()->acquire($site, 'anna');

    $impostor = new SiteLease(locks(), $lease->siteId, $lease->hostingAccountId, 'not-the-owner');
    $impostor->release();

    expect(locks()->holder(LockLevel::Site, $site->id)?->actor)->toBe('anna')
        ->and((new SiteLease(locks(), $lease->siteId, $lease->hostingAccountId, 'nope'))->refresh())->toBeFalse()
        ->and($lease->refresh())->toBeTrue();
});

it('expires a lease that is not refreshed (dead worker)', function (): void {
    $site = Site::factory()->create();
    $lease = locks(1)->acquire($site, 'anna');

    sleep(2);

    expect($lease->refresh())->toBeFalse()
        ->and(locks()->acquire($site, 'joan')->siteId)->toBe($site->id);
});

it('lists active locks with holder and remaining time', function (): void {
    $site = Site::factory()->create();
    locks()->acquire($site, 'anna', 3);

    $status = locks()->status();

    expect($status)->toHaveCount(2)
        ->and($status[0]->level)->toBe(LockLevel::HostingAccount)
        ->and($status[1]->level)->toBe(LockLevel::Site)
        ->and($status[1]->objectId)->toBe($site->id)
        ->and($status[1]->holder->runId)->toBe(3)
        ->and($status[1]->secondsLeft)->toBeGreaterThan(0);
});

it('lets an admin force-release a lock and audits it', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    app(SiteLockManager::class)->acquire($site, 'anna', 9);
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    app()->instance(ProcessIdentity::class, new ProcessIdentity('wpup', 'admin1'));
    app()->instance(OperatorResolver::class, new OperatorResolver('sudo', 'wpup', 'testing'));

    lockCommand('lock:release', ['site' => 'botiga'])
        ->expectsConfirmation("Alliberar-lo? L'execució que el té pot quedar a mitges.", 'yes')
        ->assertSuccessful();

    expect(app(SiteLockManager::class)->holder(LockLevel::Site, $site->id))->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'lock.release')->exists())->toBeTrue();
});

it('does not let an operator force-release', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    app(SiteLockManager::class)->acquire($site, 'anna');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    app()->instance(ProcessIdentity::class, new ProcessIdentity('wpup', 'oper1'));
    app()->instance(OperatorResolver::class, new OperatorResolver('sudo', 'wpup', 'testing'));

    lockCommand('lock:release', ['site' => 'botiga'])->assertFailed();

    expect(app(SiteLockManager::class)->holder(LockLevel::Site, $site->id)?->actor)->toBe('anna');
});

it('shows the lock status to operators', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    app(SiteLockManager::class)->acquire($site, 'anna');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    app()->instance(ProcessIdentity::class, new ProcessIdentity('wpup', 'oper1'));
    app()->instance(OperatorResolver::class, new OperatorResolver('sudo', 'wpup', 'testing'));

    lockCommand('lock:status')->expectsOutputToContain('botiga')->assertSuccessful();
});
