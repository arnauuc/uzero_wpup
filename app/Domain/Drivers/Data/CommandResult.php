<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * Raw result of a remote command. Output is NOT redacted: pass it through the
 * SecretRedactor before storing or showing it.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
