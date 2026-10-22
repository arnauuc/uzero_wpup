<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

/**
 * Masks secrets in any text before it is logged, stored in run_steps, audited or
 * shown (AGENTS.md rule 2). It knows:
 *
 * - the secrets revealed in this process (SecretStore::reveal() registers them),
 *   also in their base64, URL-encoded and JSON-escaped forms;
 * - common secret shapes that may come from remote output even if never
 *   registered: private key blocks, passwords in DSNs and command arguments,
 *   Authorization headers.
 *
 * It is a singleton per process; workers call reset() when a run ends.
 */
final class SecretRedactor
{
    public const MASK = Secret::MASK;

    /**
     * Registered values shorter than this are not masked on their own, to avoid
     * destroying unrelated output (they are still caught by the patterns).
     */
    private const MIN_LENGTH = 6;

    /**
     * @var array<string, true> needle => true, longest first when applied
     */
    private array $needles = [];

    public function register(#[\SensitiveParameter] Secret|string $secret): void
    {
        $value = $secret instanceof Secret ? $secret->reveal() : $secret;

        if (strlen($value) < self::MIN_LENGTH) {
            return;
        }

        foreach ($this->variants($value) as $variant) {
            $this->needles[$variant] = true;
        }
    }

    public function reset(): void
    {
        $this->needles = [];
    }

    public function redact(string $text): string
    {
        if ($this->needles !== []) {
            $needles = array_keys($this->needles);
            usort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $text = str_replace($needles, self::MASK, $text);
        }

        return $this->redactPatterns($text);
    }

    /**
     * Redacts every string inside a nested array; Secret objects become the mask.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function redactArray(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                $value instanceof Secret => self::MASK,
                is_string($value) => $this->redact($value),
                is_array($value) => $this->redactArray($value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function variants(string $value): array
    {
        $variants = [
            $value,
            base64_encode($value),
            rawurlencode($value),
            urlencode($value),
            substr((string) json_encode($value, JSON_UNESCAPED_UNICODE), 1, -1),
            substr((string) json_encode($value), 1, -1),
        ];

        return array_values(array_unique(array_filter(
            $variants,
            static fn (string $v): bool => strlen($v) >= self::MIN_LENGTH,
        )));
    }

    private function redactPatterns(string $text): string
    {
        $patterns = [
            // PEM / OpenSSH private key blocks.
            '/-----BEGIN ([A-Z0-9 ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----/s' => '-----BEGIN $1PRIVATE KEY-----'.self::MASK.'-----END $1PRIVATE KEY-----',
            // Credentials in URLs/DSNs: scheme://user:password@host
            '#([a-z][a-z0-9+.\-]*://[^\s:/@]*:)[^\s@/]+@#i' => '$1'.self::MASK.'@',
            // Command-line and env style passwords and tokens.
            '/(--?(?:password|passwd|pass|token|secret|api[-_]?key)[= ])(\'[^\']*\'|"[^"]*"|\S+)/i' => '$1'.self::MASK,
            '/\b((?:MYSQL_PWD|PGPASSWORD|[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY))=)(\'[^\']*\'|"[^"]*"|\S+)/' => '$1'.self::MASK,
            // HTTP Authorization headers.
            '/(Authorization:\s*(?:Bearer|Basic|Token)\s+)\S+/i' => '$1'.self::MASK,
            // JSON fields with secret-looking names.
            '/("(?:password|passwd|secret|token|api_key|apikey|private_key|access_token|refresh_token)"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i' => '$1'.self::MASK.'$2',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }
}
