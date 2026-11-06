<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * One table of an OrderWatermark: whether it exists, its highest ID, the
 * highest value of each of its date columns (null: no rows) and, for users,
 * an aggregate fingerprint of its rows ("count:xor"). A table whose values do
 * not validate is kept as not readable, instead of invalidating the whole
 * watermark: the detector then counts every row of it as written.
 */
final readonly class WatermarkTable
{
    private const string DATE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    private const string UNIX = '/^\d{1,12}$/D';

    private const string FINGERPRINT = '/^\d{1,12}:\d{1,20}$/D';

    /**
     * @param  array<string, string|null>  $max  column => "Y-m-d H:i:s" (GMT) or a unix time
     */
    public function __construct(
        public bool $present,
        public ?int $maxId,
        public array $max,
        public ?string $fingerprint = null,
        public bool $readable = true,
    ) {}

    public static function unreadable(): self
    {
        return new self(true, null, [], null, false);
    }

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data) || ! is_bool($data['present'] ?? null) || ($data['readable'] ?? true) !== true) {
            return self::unreadable();
        }
        if (! $data['present']) {
            return new self(false, null, []);
        }
        $maxId = $data['max_id'] ?? null;
        $fingerprint = $data['fingerprint'] ?? null;
        if (($maxId !== null && (! is_int($maxId) || $maxId < 0)) || ! is_array($data['max'] ?? null)
            || ($fingerprint !== null && ! self::validFingerprint($fingerprint))) {
            return self::unreadable();
        }
        $max = [];
        foreach ($data['max'] as $column => $value) {
            if (! is_string($column) || preg_match('/^[a-z_]{1,64}$/D', $column) !== 1 || ($value !== null && ! self::validValue($value))) {
                return self::unreadable();
            }
            $max[$column] = $value;
        }

        return new self(true, $maxId, $max, $fingerprint);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function validValue(mixed $value): bool
    {
        return is_string($value) && (preg_match(self::DATE, $value) === 1 || preg_match(self::UNIX, $value) === 1);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function validFingerprint(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FINGERPRINT, $value) === 1;
    }

    /**
     * @return array{present: bool, readable: bool, max_id: int|null, max: array<string, string|null>, fingerprint: string|null}
     */
    public function toArray(): array
    {
        return ['present' => $this->present, 'readable' => $this->readable, 'max_id' => $this->maxId, 'max' => $this->max, 'fingerprint' => $this->fingerprint];
    }
}
