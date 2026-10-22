<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Ssh\SshConnection;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Models\HostingAccount;
use App\Models\HostingServer;
use App\Models\Site;

/**
 * Sites pointing at the Docker test sites (make fixtures-up). Never real ones.
 */
final class Fixtures
{
    public static function up(string $host): bool
    {
        $socket = @fsockopen($host, 22, $code, $message, 2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * A site on fixture $name, with the fixture key in the SecretStore and,
     * unless $pin is false, the host key pinned ($hostKey, or the real one).
     */
    public static function site(string $name = 'wp83', ?string $user = null, ?string $hostKey = null, bool $pin = true, bool $withKey = true): Site
    {
        $server = HostingServer::factory()->create([
            'hostname' => $name,
            'pinned_host_key' => $pin ? ($hostKey ?? SshConnection::fetchHostKey($name, 22, 5)) : null,
        ]);
        $key = (string) file_get_contents(base_path('docker/fixtures/.keys/id_ed25519'));
        $account = HostingAccount::factory()->for($server)->create([
            'system_user' => $user ?? "sub_{$name}",
            'credential_ref' => $withKey ? app(SecretStore::class)->put('ssh-key', new Secret($key), AuditActor::system('tests')) : null,
        ]);

        return Site::factory()->for($account)->create([
            'name' => $name.'-'.bin2hex(random_bytes(3)),
            'url' => "http://{$name}.fixtures.test",
            'wp_path' => "/var/www/vhosts/{$name}.fixtures.test/httpdocs",
        ]);
    }
}
