<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Enums\AuditResult;
use App\Models\HostingServer;
use Carbon\CarbonImmutable;

/**
 * Pinning of a server's SSH host key on first contact (ADR-0002): the admin
 * confirms the fingerprint, which is compared with the key fetched again at
 * pinning time. A pinned key is never replaced here; a change of key makes
 * every connection fail until an admin investigates.
 */
final readonly class HostKeyPinning
{
    public function __construct(
        private AuditLogger $audit,
        private SshSettings $settings,
    ) {}

    public function fetch(HostingServer $server): string
    {
        return SshConnection::fetchHostKey($server->hostname, $server->ssh_port, $this->settings->connectTimeoutSeconds);
    }

    /**
     * OpenSSH-style fingerprint ("SHA256:…", unpadded base64), as shown by
     * `ssh-keygen -lf`, so the admin can compare it with the server's.
     */
    public static function fingerprint(string $hostKey): string
    {
        $parts = explode(' ', SshTarget::normaliseHostKey($hostKey));
        $blob = base64_decode($parts[1] ?? '', true);

        if ($blob === false || $blob === '') {
            throw new ConnectionFailed('La host key rebuda no és vàlida.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Pins the server's current key if its fingerprint is the confirmed one.
     *
     * @throws ConnectionFailed when the server already has a key, or the key changed since it was shown
     */
    public function pin(HostingServer $server, string $confirmedFingerprint, AuditActor $actor): string
    {
        if ($server->pinned_host_key !== null) {
            throw new ConnectionFailed("El servidor {$server->name} ja té una host key fixada; no es canvia des d'aquí.");
        }

        $key = $this->fetch($server);
        $fingerprint = self::fingerprint($key);

        if (! hash_equals($fingerprint, trim($confirmedFingerprint))) {
            $this->audit->record($actor, 'host_key.pin', AuditResult::Failure, 'hosting_server', $server->id, [
                'expected' => $confirmedFingerprint,
                'received' => $fingerprint,
            ]);

            throw new ConnectionFailed("La host key de {$server->hostname} ({$fingerprint}) no és la confirmada ({$confirmedFingerprint}). No s'ha fixat.");
        }

        $server->forceFill(['pinned_host_key' => SshTarget::normaliseHostKey($key), 'pinned_host_key_at' => CarbonImmutable::now()])->save();
        $this->audit->record($actor, 'host_key.pin', AuditResult::Success, 'hosting_server', $server->id, ['fingerprint' => $fingerprint]);

        return $fingerprint;
    }
}
