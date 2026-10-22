<?php

declare(strict_types=1);

use App\Domain\Identity\Ability;
use App\Domain\Identity\CliCommandGate;
use App\Domain\Identity\IdentityException;
use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function sudoResolver(string $environment = 'production'): OperatorResolver
{
    return new OperatorResolver('sudo', 'wpup', $environment);
}

it('identifies the operator from SUDO_USER when running as the service user', function (): void {
    $user = User::factory()->create(['unix_username' => 'anna']);

    $resolved = sudoResolver()->resolve(new ProcessIdentity('wpup', 'anna'));

    expect($resolved->id)->toBe($user->id);
});

it('refuses a direct call without sudo', function (): void {
    sudoResolver()->resolve(new ProcessIdentity('wpup', null));
})->throws(IdentityException::class, 'sudo -u wpup');

it('refuses when the process does not run as the service user', function (): void {
    User::factory()->create(['unix_username' => 'anna']);

    sudoResolver()->resolve(new ProcessIdentity('anna', 'anna'));
})->throws(IdentityException::class, 'ha de córrer com a usuari wpup');

it('refuses unknown Unix users', function (): void {
    sudoResolver()->resolve(new ProcessIdentity('wpup', 'mallory'));
})->throws(IdentityException::class, 'no està donat d\'alta');

it('refuses deactivated users', function (): void {
    User::factory()->create(['unix_username' => 'joan', 'active' => false]);

    sudoResolver()->resolve(new ProcessIdentity('wpup', 'joan'));
})->throws(IdentityException::class, 'desactivat');

it('uses the process user in posix mode for development', function (): void {
    $user = User::factory()->create(['unix_username' => 'dev']);

    expect((new OperatorResolver('posix', 'wpup', 'local'))->resolve(new ProcessIdentity('dev', null))->id)->toBe($user->id);
});

it('refuses posix mode in production', function (): void {
    (new OperatorResolver('posix', 'wpup', 'production'))->resolve(new ProcessIdentity('dev', null));
})->throws(IdentityException::class, 'només per a desenvolupament');

it('maps roles to abilities', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $inactiveAdmin = User::factory()->create(['role' => UserRole::Admin, 'active' => false]);

    expect(Ability::ManageUsers->allows($admin))->toBeTrue()
        ->and(Ability::ManageCredentials->allows($admin))->toBeTrue()
        ->and(Ability::ManageSites->allows($operator))->toBeFalse()
        ->and(Ability::ManageUsers->allows($operator))->toBeFalse()
        ->and(Ability::RunOperations->allows($operator))->toBeTrue()
        ->and(Ability::ViewReports->allows($operator))->toBeTrue()
        ->and(Ability::RunOperations->allows($inactiveAdmin))->toBeFalse();
});

it('only lets the wpup gate run the tool commands', function (): void {
    $gate = app(CliCommandGate::class);

    foreach (['user:add', 'user:list', 'site:add', 'inventory', 'lock:status', 'list', null] as $allowed) {
        expect($gate->allows($allowed))->toBeTrue();
    }

    foreach (['tinker', 'migrate', 'migrate:fresh', 'key:generate', 'user:bootstrap', 'db:wipe', 'horizon:terminate', 'config:show', 'env'] as $denied) {
        expect($gate->allows($denied))->toBeFalse();
    }
});

it('never lets a framework or package command through the wpup gate', function (): void {
    $gate = app(CliCommandGate::class);
    $leaks = [];

    foreach (Artisan::all() as $name => $command) {
        $isOurs = str_starts_with($command::class, 'App\\');

        if (! $isOurs && ! in_array($name, ['list', 'help'], true) && $gate->allows($name)) {
            $leaks[] = $name;
        }
    }

    expect($leaks)->toBe([]);
});

it('keeps the bash wrapper allow-list in sync with the config', function (): void {
    $script = (string) file_get_contents(base_path('wpup'));
    $found = preg_match("/^allowed='\\^\\((.*)\\)\\$'$/m", $script, $m);
    expect($found)->toBe(1);

    $fromScript = explode('|', $m[1] ?? '');
    $fromConfig = [];

    foreach ((array) config('wpup.cli.allowed_commands') as $entry) {
        if (is_string($entry)) {
            $fromConfig[] = str_ends_with($entry, ':') ? $entry.'.*' : $entry;
        }
    }

    sort($fromScript);
    sort($fromConfig);

    expect($fromScript)->toBe($fromConfig);
});
