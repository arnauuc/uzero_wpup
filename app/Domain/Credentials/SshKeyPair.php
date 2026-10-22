<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Secrets\Secret;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * An Ed25519 key pair generated on the tool server (ADR-0002). The private
 * half only exists as a Secret and is never written to disk.
 */
final readonly class SshKeyPair
{
    private function __construct(
        public Secret $privateKey,
        public string $publicKey,
    ) {}

    public static function generate(string $comment): self
    {
        $key = EC::createKey('Ed25519');
        $privateText = $key->toString('OpenSSH');
        $public = $key->getPublicKey();
        $publicText = $public instanceof EC\PublicKey ? $public->toString('OpenSSH', ['comment' => $comment]) : null;

        if ($publicText === null) {
            throw new CredentialException("No s'ha pogut generar la clau Ed25519.");
        }

        return new self(new Secret($privateText), trim($publicText));
    }

    /**
     * The public key of a stored private key ("ssh-ed25519 AAAA…", no comment).
     */
    public static function publicOf(Secret $privateKey): string
    {
        $key = PublicKeyLoader::loadPrivateKey($privateKey->reveal());
        if (! $key instanceof PrivateKey) {
            throw new CredentialException('La clau desada no és una clau Ed25519.');
        }

        $public = $key->getPublicKey();
        $text = $public instanceof EC\PublicKey ? $public->toString('OpenSSH') : null;

        return is_string($text) ? self::blob($text) : throw new CredentialException('La clau desada no és una clau Ed25519.');
    }

    /**
     * "type base64" of an OpenSSH public key line, to find it in authorized_keys.
     */
    public static function blob(string $publicKey): string
    {
        preg_match('/(ssh-ed25519|ssh-rsa|ecdsa-sha2-\S+)\s+([A-Za-z0-9+\/=]+)/', $publicKey, $m);

        return isset($m[1], $m[2]) ? "{$m[1]} {$m[2]}" : throw new CredentialException('Clau pública no vàlida.');
    }

    /**
     * authorized_keys line restricted to the tool's egress IP (ADR-0002, ADR-0003).
     */
    public static function authorizedKeysLine(string $publicKey, string $egressIp): string
    {
        return sprintf('from="%s",no-port-forwarding,no-agent-forwarding,no-X11-forwarding %s', $egressIp, trim($publicKey));
    }
}
