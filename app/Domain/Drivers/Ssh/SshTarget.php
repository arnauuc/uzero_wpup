<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

/**
 * Where and as whom the SSH driver connects. No secret material here.
 */
final readonly class SshTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public ?string $pinnedHostKey,
        public string $wpPath,
        public ?string $phpBinary,
        // Domain name on a Plesk server, to find its PHP handler (UZEROWPUP-29).
        public ?string $pleskDomain = null,
        // "X.Y" the web was last seen serving (HTTP probe): the binary to prefer
        // when the domain has pools under several PHP versions.
        public ?string $webPhpMinor = null,
    ) {}

    public function label(): string
    {
        return "{$this->user}@{$this->host}:{$this->port}";
    }

    /**
     * known_hosts entry for the pinned key ("[host]:port type base64").
     */
    public function knownHostsLine(): string
    {
        $host = $this->port === 22 ? $this->host : "[{$this->host}]:{$this->port}";

        return $host.' '.self::normaliseHostKey((string) $this->pinnedHostKey);
    }

    /**
     * "type base64" without comment or extra spaces, to compare keys.
     */
    public static function normaliseHostKey(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];

        return implode(' ', array_slice($parts, 0, 2));
    }
}
