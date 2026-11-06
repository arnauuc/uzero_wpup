<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * One table of an OrderWatermark: whether it exists, its highest ID and the
 * highest value of each of its date columns (null: no rows).
 */
final readonly class WatermarkTable
{
    private const string DATE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    private const string UNIX = '/^\d{1,12}$/D';

    /**
     * @param  array<string, string|null>  $max  column => "Y-m-d H:i:s" (GMT) or a unix time
     */
    public function __construct(
        public bool $present,
        public ?int $maxId,
        public array $max,
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_bool($data['present'] ?? null) || ! array_key_exists('max_id', $data) || ! is_array($data['max'] ?? null)) {
            return null;
        }
        $maxId = $data['max_id'];
        if ($maxId !== null && (! is_int($maxId) || $maxId < 0)) {
            return null;
        }
        $max = [];
        foreach ($data['max'] as $column => $value) {
            if (! is_string($column) || preg_match('/^[a-z_]{1,64}$/D', $column) !== 1 || ($value !== null && ! self::validValue($value))) {
                return null;
            }
            $max[$column] = $value;
        }

        return new self($data['present'], $maxId, $max);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function validValue(mixed $value): bool
    {
        return is_string($value) && (preg_match(self::DATE, $value) === 1 || preg_match(self::UNIX, $value) === 1);
    }

    /**
     * Whether $now is after $before: dates of the same format compare as text,
     * unix times as numbers.
     */
    public static function later(?string $now, ?string $before): bool
    {
        if ($now === null) {
            return false;
        }
        if ($before === null) {
            return true;
        }

        return ctype_digit($now) && ctype_digit($before) ? (int) $now > (int) $before : strcmp($now, $before) > 0;
    }

    /**
     * @return array{present: bool, max_id: int|null, max: array<string, string|null>}
     */
    public function toArray(): array
    {
        return ['present' => $this->present, 'max_id' => $this->maxId, 'max' => $this->max];
    }
}
