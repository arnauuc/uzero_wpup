<?php

declare(strict_types=1);

use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

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

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
});

it('lets an admin add an operator and audits it', function (): void {
    actingAsOperator('admin1');

    command('user:add', ['unix_username' => 'marta', '--name' => 'Marta'])
        ->expectsOutputToContain("donat d'alta")
        ->assertSuccessful();

    $marta = User::query()->where('unix_username', 'marta')->sole();
    $entry = AuditLogEntry::query()->where('action', 'user.add')->sole();

    expect($marta->role)->toBe(UserRole::Operator)
        ->and($entry->actor)->toBe('admin1')
        ->and($entry->channel)->toBe(AuditChannel::Cli)
        ->and($entry->object_id)->toBe((string) $marta->id);
});

it('denies user management to operators and audits the denial', function (): void {
    actingAsOperator('oper1');

    command('user:add', ['unix_username' => 'intrus'])
        ->expectsOutputToContain('No tens permís')
        ->assertFailed();

    expect(User::query()->where('unix_username', 'intrus')->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'command.denied')->where('result', AuditResult::Denied->value)->exists())->toBeTrue();
});

it('refuses commands from unknown callers', function (): void {
    actingAsOperator('stranger');

    command('user:list')->expectsOutputToContain("no està donat d'alta")->assertFailed();
});

it('does nothing in dry-run mode', function (): void {
    actingAsOperator('admin1');

    command('user:add', ['unix_username' => 'pere', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect(User::query()->where('unix_username', 'pere')->exists())->toBeFalse();
});

it('rejects invalid usernames and roles', function (): void {
    actingAsOperator('admin1');

    command('user:add', ['unix_username' => 'Bad Name'])->assertFailed();
    command('user:add', ['unix_username' => 'ok', '--role' => 'root'])->assertFailed();
});

it('changes roles but never removes the last active admin', function (): void {
    actingAsOperator('admin1');

    command('user:role', ['unix_username' => 'admin1', 'role' => 'operator'])
        ->expectsOutputToContain("l'últim admin")
        ->assertFailed();

    command('user:role', ['unix_username' => 'oper1', 'role' => 'admin'])->assertSuccessful();

    expect(User::query()->where('unix_username', 'oper1')->sole()->role)->toBe(UserRole::Admin);
});

it('deactivates users without deleting them, but not oneself', function (): void {
    actingAsOperator('admin1');

    command('user:deactivate', ['unix_username' => 'admin1'])
        ->expectsConfirmation('Desactivar «admin1»?', 'yes')
        ->expectsOutputToContain('a tu mateix')
        ->assertFailed();

    command('user:deactivate', ['unix_username' => 'oper1'])
        ->expectsConfirmation('Desactivar «oper1»?', 'yes')
        ->assertSuccessful();

    expect(User::query()->where('unix_username', 'oper1')->sole()->active)->toBeFalse();
});

it('bootstraps the first admin only once', function (): void {
    User::query()->delete();

    command('user:bootstrap', ['unix_username' => 'arnau'])->assertSuccessful();
    command('user:bootstrap', ['unix_username' => 'altre'])->expectsOutputToContain('Ja hi ha un admin')->assertFailed();

    expect(User::query()->where('unix_username', 'arnau')->sole()->role)->toBe(UserRole::Admin)
        ->and(AuditLogEntry::query()->where('actor', 'sistema (bootstrap)')->exists())->toBeTrue();
});
