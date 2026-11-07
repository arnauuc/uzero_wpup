<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Enums\PhpLogLevel;

/**
 * A PHP log message and how many times it repeated (UZEROWPUP-71). The message
 * is already redacted and truncated; the signature is a hash, so neither holds
 * a secret. first/last are the log's own timestamps, when the lines have one.
 */
final readonly class LogIssueGroup
{
    public function __construct(
        public string $signature,
        public PhpLogLevel $level,
        public string $message,
        public int $count,
        public ?string $firstSeen,
        public ?string $lastSeen,
    ) {}

    public function seenAgain(?string $at): self
    {
        return new self($this->signature, $this->level, $this->message, $this->count + 1, $this->firstSeen, $at ?? $this->lastSeen);
    }

    /**
     * @return array{signature: string, level: string, message: string, count: int, first_seen: ?string, last_seen: ?string}
     */
    public function toArray(): array
    {
        return [
            'signature' => $this->signature,
            'level' => $this->level->value,
            'message' => $this->message,
            'count' => $this->count,
            'first_seen' => $this->firstSeen,
            'last_seen' => $this->lastSeen,
        ];
    }
}
