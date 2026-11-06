<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

/**
 * One PHPCompatibility message: path relative to the package, line, message.
 */
final readonly class CompatFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
        public bool $isError,
    ) {}

    /** "file:line" */
    public function location(): string
    {
        return "{$this->file}:{$this->line}";
    }

    /**
     * @return array{file: string, line: int, message: string, severity: string}
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'message' => $this->message, 'severity' => $this->isError ? 'error' : 'warning'];
    }

    public static function fromArray(mixed $row): ?self
    {
        if (! is_array($row) || ! is_string($row['file'] ?? null) || ! is_int($row['line'] ?? null) || ! is_string($row['message'] ?? null)) {
            return null;
        }

        return new self($row['file'], $row['line'], $row['message'], ($row['severity'] ?? null) === 'error');
    }
}
