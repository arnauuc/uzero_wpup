<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\SshConnection;
use App\Domain\Drivers\Ssh\SshSettings;
use App\Domain\Drivers\Ssh\SshTarget;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Enums\SiteDriverType;
use App\Models\HostingAccount;
use App\Models\StoredSecret;

/**
 * SSH keys of hosting accounts (UZEROWPUP-123, ADR-0002): one Ed25519 key per
 * account, generated here, stored in the SecretStore and installed restricted
 * to the tool's egress IP.
 *
 * Rotation is resumable: the new key is kept as pending until it is proven to
 * work, and only then becomes the account's key and the old one is removed.
 * At any point the account keeps a key that works.
 */
final readonly class SshKeyManager
{
    public function __construct(
        private SecretStore $secrets,
        private AuditLogger $audit,
        private SshSettings $settings,
        private ?string $egressIp,
    ) {}

    /**
     * Generates the account's first key. Returns the authorized_keys line to
     * install (the only thing ever shown).
     */
    public function generate(HostingAccount $account, AuditActor $actor, bool $dryRun = false): string
    {
        $this->assertSsh($account);
        if ($account->credential_ref !== null) {
            throw new CredentialException("El compte {$this->label($account)} ja té clau: fes servir ssh-key:rotate.");
        }
        $egressIp = $this->egressIp();

        if ($dryRun) {
            return SshKeyPair::authorizedKeysLine('ssh-ed25519 <clau nova> '.$this->comment($account), $egressIp);
        }

        $pair = SshKeyPair::generate($this->comment($account));
        try {
            $ref = $this->secrets->put('ssh-key', $pair->privateKey, $actor);
        } finally {
            $pair->privateKey->wipe();
        }
        $account->forceFill(['credential_ref' => $ref, 'ssh_public_key' => $pair->publicKey])->save();
        $this->audit->record($actor, 'ssh_key.generate', AuditResult::Success, 'hosting_account', $account->id, [
            'public_key' => SshKeyPair::blob($pair->publicKey),
        ]);

        return SshKeyPair::authorizedKeysLine($pair->publicKey, $egressIp);
    }

    /**
     * Rotates the account's key, resuming an interrupted rotation if there is one.
     * States: pending (new key, not yet proven) → switched (old key retiring) → done.
     *
     * @return list<string> notes for the admin (e.g. an old key still accepted)
     */
    public function rotate(HostingAccount $account, AuditActor $actor): array
    {
        $this->assertSsh($account);
        if ($account->credential_ref === null) {
            throw new CredentialException("El compte {$this->label($account)} no té clau: fes servir ssh-key:generate.");
        }
        $egressIp = $this->egressIp();

        try {
            if ($account->retiring_credential_ref === null) {
                $this->switchToNewKey($account, $actor, $egressIp);
            }

            return $this->retireOldKey($account, $actor);
        } catch (DriverException $e) {
            throw new CredentialException("La rotació s'ha aturat: {$e->getMessage()} Es manté la clau que funciona; torna-ho a provar per reprendre-la.");
        }
    }

    /**
     * Installs a new key with the current one, proves it and makes it current.
     */
    private function switchToNewKey(HostingAccount $account, AuditActor $actor, string $egressIp): void
    {
        if ($account->pending_credential_ref === null) {
            $pair = SshKeyPair::generate($this->comment($account));
            try {
                $ref = $this->secrets->put('ssh-key', $pair->privateKey, $actor);
            } finally {
                $pair->privateKey->wipe();
            }
            $account->forceFill(['pending_credential_ref' => $ref])->save();
            $this->audit->record($actor, 'ssh_key.rotate.start', AuditResult::Success, 'hosting_account', $account->id);
        }

        $pendingRef = (string) $account->pending_credential_ref;
        $newKey = $this->secrets->reveal($pendingRef, $actor, 'ssh-key:rotate');
        $oldKey = $this->secrets->reveal((string) $account->credential_ref, $actor, 'ssh-key:rotate');

        try {
            $newPublic = SshKeyPair::publicOf($newKey);
            if (! $this->works($account, $newKey)) {
                $install = $this->connect($account, $oldKey)->run($this->installScript($newPublic.' '.$this->comment($account), $egressIp), 60);
                if (! $install->successful()) {
                    throw new CredentialException("No s'ha pogut instal·lar la clau nova a {$this->label($account)}; es manté l'actual.");
                }
            }
            if (! $this->works($account, $newKey)) {
                throw new CredentialException("La clau nova no dona accés a {$this->label($account)}; es manté l'actual. Torna-ho a provar: la rotació es reprendrà.");
            }
        } finally {
            $newKey->wipe();
            $oldKey->wipe();
        }

        $account->forceFill([
            'retiring_credential_ref' => $account->credential_ref,
            'credential_ref' => $pendingRef,
            'pending_credential_ref' => null,
            'ssh_public_key' => $newPublic.' '.$this->comment($account),
        ])->save();
    }

    /**
     * Removes the old key from the server (with the new one) and forgets it.
     *
     * @return list<string>
     */
    private function retireOldKey(HostingAccount $account, AuditActor $actor): array
    {
        $retiringRef = (string) $account->retiring_credential_ref;
        $oldKey = $this->secrets->reveal($retiringRef, $actor, 'ssh-key:rotate');
        $newKey = $this->secrets->reveal((string) $account->credential_ref, $actor, 'ssh-key:rotate');
        $notes = [];

        try {
            $oldPublic = SshKeyPair::publicOf($oldKey);
            $removal = $this->connect($account, $newKey)->run($this->removeScript($oldPublic), 60);
            if (! $removal->successful()) {
                throw new CredentialException("No s'ha pogut retirar la clau antiga de {$this->label($account)}. Torna-ho a provar.");
            }
            if ($this->works($account, $oldKey)) {
                $notes[] = "La clau antiga encara dona accés a {$this->label($account)} (no és a ~/.ssh/authorized_keys): retira-la a mà.";
            }
        } finally {
            $oldKey->wipe();
            $newKey->wipe();
        }

        $account->forceFill(['retiring_credential_ref' => null])->save();
        StoredSecret::query()->whereKey($retiringRef)->delete();
        $this->audit->record($actor, 'ssh_key.rotate', AuditResult::Success, 'hosting_account', $account->id, [
            'old_public_key' => $oldPublic,
            'new_public_key' => SshKeyPair::blob((string) $account->ssh_public_key),
        ]);

        return $notes;
    }

    private function works(HostingAccount $account, Secret $key): bool
    {
        $connection = $this->connect($account, $key);
        try {
            return $connection->run('true', 30)->successful();
        } catch (DriverException) {
            return false;
        } finally {
            $connection->close();
        }
    }

    private function connect(HostingAccount $account, Secret $key): SshConnection
    {
        $server = $account->hostingServer()->firstOrFail();
        $target = new SshTarget($server->hostname, $server->ssh_port, $account->system_user, $server->pinned_host_key, '/', null);

        return new SshConnection($target, $key, $this->settings->connectTimeoutSeconds);
    }

    /**
     * Appends the key to ~/.ssh/authorized_keys unless it is already there.
     */
    private function installScript(string $publicKey, string $egressIp): string
    {
        $line = escapeshellarg(SshKeyPair::authorizedKeysLine($publicKey, $egressIp));
        $blob = escapeshellarg(SshKeyPair::blob($publicKey));

        return 'umask 077 && mkdir -p "$HOME/.ssh" && touch "$HOME/.ssh/authorized_keys"'
            .' && chmod 700 "$HOME/.ssh" && chmod 600 "$HOME/.ssh/authorized_keys"'
            ." && { grep -qF -- {$blob} \"\$HOME/.ssh/authorized_keys\" || printf '%s\\n' {$line} >> \"\$HOME/.ssh/authorized_keys\"; }";
    }

    /**
     * Removes every authorized_keys line with this key.
     */
    private function removeScript(string $publicKey): string
    {
        $blob = escapeshellarg(SshKeyPair::blob($publicKey));

        return 'f="$HOME/.ssh/authorized_keys"; [ -f "$f" ] || exit 0;'
            ." umask 077; grep -vF -- {$blob} \"\$f\" > \"\$f.wpup\"; mv -f \"\$f.wpup\" \"\$f\" && chmod 600 \"\$f\"";
    }

    private function egressIp(): string
    {
        $ip = $this->egressIp ?? '';
        $address = explode('/', $ip, 2)[0];

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new CredentialException("Configura l'IP pública fixa de sortida de l'eina (WPUP_EGRESS_IP, ADR-0003) abans de generar claus.");
        }

        return $ip;
    }

    private function assertSsh(HostingAccount $account): void
    {
        if ($account->driver !== SiteDriverType::Ssh) {
            throw new CredentialException("El compte {$this->label($account)} no fa servir SSH.");
        }
    }

    private function comment(HostingAccount $account): string
    {
        return 'wpup:'.$this->label($account);
    }

    private function label(HostingAccount $account): string
    {
        return $account->hostingServer()->firstOrFail()->name.'/'.$account->system_user;
    }
}
