<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\PrerequisiteName;

/**
 * Result of one update prerequisite (UZEROWPUP-34). The reason is operator-facing
 * Catalan and only set when the check failed; the value is what was measured
 * (PHP version, bytes needed for the backup…), if anything.
 */
final readonly class PrerequisiteCheck
{
    public function __construct(
        public PrerequisiteName $name,
        public bool $passed,
        public ?string $reason = null,
        public int|string|null $value = null,
    ) {}

    /**
     * @return array{name: string, passed: bool, reason: ?string, value: int|string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name->value, 'passed' => $this->passed, 'reason' => $this->reason, 'value' => $this->value];
    }

    /**
     * Reads a check back from a stored snapshot; null if the row is malformed.
     */
    public static function fromArray(mixed $row): ?self
    {
        if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_bool($row['passed'] ?? null)) {
            return null;
        }
        $name = PrerequisiteName::tryFrom($row['name']);
        if ($name === null) {
            return null;
        }
        $value = $row['value'] ?? null;

        return new self($name, $row['passed'], is_string($row['reason'] ?? null) ? $row['reason'] : null, is_int($value) || is_string($value) ? $value : null);
    }
}
