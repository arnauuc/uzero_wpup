<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\SshConnection;
use App\Domain\Drivers\Ssh\SshTarget;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\UserRole;
use App\Models\HostingAccount;
use App\Models\StoredSecret;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Fixtures;

/*
 * ssh-key:generate and ssh-key:rotate against the Docker test sites. The
 * fixture's base key lives in a root-owned file (like a key set up by the
 * hosting provider); the tool manages ~/.ssh/authorized_keys.
 */

if (! Fixtures::up('wp83')) {
    it('manages SSH keys on the fixtures')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    actingAsOperator('admin1');
    config(['wpup.ssh.egress_ip' => Fixtures::clientIp('wp83')]);
    Fixtures::run('wp83', 'rm -f "$HOME/.ssh/authorized_keys"');
});

afterEach(function (): void {
    Fixtures::run('wp83', 'rm -f "$HOME/.ssh/authorized_keys"');
});

/**
 * @return array{server: string, account: string}
 */
function accountArgs(HostingAccount $account): array
{
    return ['server' => $account->hostingServer()->firstOrFail()->name, 'account' => $account->system_user];
}

function keyWorks(HostingAccount $account, string $ref): bool
{
    $server = $account->hostingServer()->firstOrFail();
    $key = app(SecretStore::class)->reveal($ref, AuditActor::system('tests'), 'test');
    try {
        return (new SshConnection(new SshTarget($server->hostname, 22, $account->system_user, $server->pinned_host_key, '/', null), $key, 5))
            ->run('true', 10)->successful();
    } catch (DriverException) {
        return false;
    }
}

it('generates a key and shows only the restricted public line', function (): void {
    $account = Fixtures::site('wp83', withKey: false)->hostingAccount()->firstOrFail();

    Artisan::call('ssh-key:generate', accountArgs($account));
    $output = Artisan::output();
    $account->refresh();

    expect($output)->toContain('from="'.Fixtures::clientIp('wp83').'",no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 ')
        ->and($output)->not->toContain('PRIVATE KEY')
        ->and($account->credential_ref)->not->toBeNull()
        ->and(StoredSecret::query()->findOrFail($account->credential_ref)->ciphertext)->not->toContain('OPENSSH');

    // Installed as shown, the key gives access (from= matches the tool's address).
    Fixtures::run('wp83', 'umask 077; mkdir -p "$HOME/.ssh"; printf "%s\n" '.escapeshellarg(sshKeyLine($output)).' >> "$HOME/.ssh/authorized_keys"');
    expect(keyWorks($account, (string) $account->credential_ref))->toBeTrue();

    command('ssh-key:generate', accountArgs($account))->expectsOutputToContain('ja té clau')->assertFailed();
});

it('does nothing in a dry run', function (): void {
    $account = Fixtures::site('wp83', withKey: false)->hostingAccount()->firstOrFail();

    command('ssh-key:generate', accountArgs($account) + ['--dry-run' => true])->expectsOutputToContain('[dry-run]')->assertSuccessful();

    expect($account->fresh()?->credential_ref)->toBeNull();
});

it('rotates a key, retiring the old one, and warns when it cannot', function (): void {
    $account = Fixtures::site('wp83')->hostingAccount()->firstOrFail();
    $original = (string) $account->credential_ref;

    // The original key is root-managed: it cannot be removed, and the admin is told.
    command('ssh-key:rotate', accountArgs($account))->expectsOutputToContain('retira-la a mà')->assertSuccessful();
    $account->refresh();
    $first = (string) $account->credential_ref;
    expect($first)->not->toBe($original)
        ->and(keyWorks($account, $first))->toBeTrue()
        ->and(StoredSecret::query()->whereKey($original)->exists())->toBeFalse();

    // The tool's own key is removed from ~/.ssh/authorized_keys.
    $firstSecret = app(SecretStore::class)->reveal($first, AuditActor::system('tests'), 'test')->reveal();
    command('ssh-key:rotate', accountArgs($account))->doesntExpectOutputToContain('retira-la a mà')->assertSuccessful();
    $account->refresh();

    expect(keyWorks($account, (string) $account->credential_ref))->toBeTrue()
        ->and(substr_count(Fixtures::run('wp83', 'cat "$HOME/.ssh/authorized_keys"'), 'ssh-ed25519'))->toBe(1)
        ->and(Fixtures::run('wp83', 'cat "$HOME/.ssh/authorized_keys"'))->toContain('from="')
        ->and($account->pending_credential_ref)->toBeNull()
        ->and($account->retiring_credential_ref)->toBeNull();

    $server = $account->hostingServer()->firstOrFail();
    $old = new SshConnection(new SshTarget($server->hostname, 22, $account->system_user, $server->pinned_host_key, '/', null), new Secret($firstSecret), 5);
    expect(fn () => $old->run('true', 10))->toThrow(ConnectionFailed::class);
});

it('resumes an interrupted rotation and keeps the working key meanwhile', function (): void {
    $account = Fixtures::site('wp83')->hostingAccount()->firstOrFail();
    $server = $account->hostingServer()->firstOrFail();
    $original = (string) $account->credential_ref;
    $pinned = $server->pinned_host_key;

    // The server cannot be reached halfway (wrong host key): the rotation stops.
    $server->forceFill(['pinned_host_key' => SshConnection::fetchHostKey('wp74', 22, 5)])->save();
    command('ssh-key:rotate', accountArgs($account))->expectsOutputToContain("s'ha aturat")->assertFailed();
    $account->refresh();
    $pending = (string) $account->pending_credential_ref;
    expect($account->credential_ref)->toBe($original)->and($pending)->not->toBe('');

    $server->forceFill(['pinned_host_key' => $pinned])->save();
    expect(keyWorks($account, $original))->toBeTrue();

    command('ssh-key:rotate', accountArgs($account))->assertSuccessful();
    expect($account->fresh()?->credential_ref)->toBe($pending);
});

it('never leaves the private key in the output, the logs or on disk', function (): void {
    $startedAt = time();
    $account = Fixtures::site('wp83', withKey: false)->hostingAccount()->firstOrFail();
    Artisan::call('ssh-key:generate', accountArgs($account));
    $output = Artisan::output();
    $account->refresh();
    Fixtures::run('wp83', 'umask 077; mkdir -p "$HOME/.ssh"; printf "%s\n" '.escapeshellarg(sshKeyLine($output)).' >> "$HOME/.ssh/authorized_keys"');
    Artisan::call('ssh-key:rotate', accountArgs($account));
    $output .= Artisan::output();

    $private = app(SecretStore::class)->reveal((string) $account->fresh()?->credential_ref, AuditActor::system('tests'), 'test')->reveal();
    $body = trim(implode('', array_slice(explode("\n", trim($private)), 1, -1)));
    $needles = ['PRIVATE KEY', substr($body, 20, 40)];

    $logs = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        $logs .= (string) file_get_contents($log);
    }
    $recent = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(sys_get_temp_dir(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getMTime() >= $startedAt && $file->getSize() < 1_000_000) {
            $recent .= (string) @file_get_contents($file->getPathname());
        }
    }

    foreach ($needles as $needle) {
        expect($output)->not->toContain($needle)
            ->and($logs)->not->toContain($needle)
            ->and($recent)->not->toContain($needle);
    }
});

function sshKeyLine(string $output): string
{
    preg_match('/^from=.*$/m', $output, $line);

    return $line[0] ?? '';
}
