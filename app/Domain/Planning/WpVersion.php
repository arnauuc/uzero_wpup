<?php

declare(strict_types=1);

namespace App\Domain\Planning;

/**
 * A WordPress-style version, compared tolerantly (UZEROWPUP-48). The single place
 * where the planner compares versions: never version_compare() on raw strings
 * (it says 6.5 < 6.5.0) and never string comparison (6.10 < 6.9).
 *
 * - Any number of numeric components; missing ones are zero: 6.5 == 6.5.0 == 6.5.0.0.
 * - An optional leading "v" (v2.1).
 * - Pre-releases, with or without separator and number: dev < alpha (a) < beta (b)
 *   < RC < release < pl (patch level). 6.5-RC1 < 6.5-RC2 < 6.5; 6.9-alpha-60000-src is 6.9 alpha.
 * - Build metadata after "+" and distribution suffixes after "-" that are not a
 *   pre-release (PHP's 8.1.2-1ubuntu2.14) are ignored.
 *
 * Anything else (empty, "6.x", ">= 7.4", "6.5 or higher", "dev-main") does not
 * parse: callers must treat it conservatively, never as satisfied.
 */
final readonly class WpVersion
{
    private const array STAGES = ['dev' => 0, 'alpha' => 1, 'a' => 1, 'beta' => 2, 'b' => 2, 'rc' => 3, 'pl' => 5, 'p' => 5];

    private const int RELEASE = 4;

    /**
     * @param  list<int>  $numbers
     */
    private function __construct(
        public array $numbers,
        public int $stage,
        public int $stageNumber,
    ) {}

    public static function parse(?string $value): ?self
    {
        $text = strtolower(trim((string) $value));
        if (preg_match('/^v?(\d{1,9}(?:\.\d{1,9})*)(.*)$/', $text, $m) !== 1) {
            return null;
        }

        $numbers = array_map(intval(...), explode('.', $m[1]));
        $suffix = explode('+', $m[2], 2)[0];
        if ($suffix === '') {
            return new self($numbers, self::RELEASE, 0);
        }

        if (preg_match('/^[-._~]?(dev|alpha|beta|rc|pl|a|b|p)[-._]?(\d{0,9})(?![a-z])/', $suffix, $s) === 1) {
            return new self($numbers, self::STAGES[$s[1]], $s[2] === '' ? 0 : (int) $s[2]);
        }

        // A distribution or build suffix after a dash: the release itself.
        return preg_match('/^-[0-9a-z][0-9a-z.~-]*$/', $suffix) === 1 ? new self($numbers, self::RELEASE, 0) : null;
    }

    public static function isValid(?string $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Negative, zero or positive, like the spaceship operator.
     */
    public function compare(self $other): int
    {
        $length = max(count($this->numbers), count($other->numbers));
        for ($i = 0; $i < $length; $i++) {
            $cmp = ($this->numbers[$i] ?? 0) <=> ($other->numbers[$i] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return [$this->stage, $this->stageNumber] <=> [$other->stage, $other->stageNumber];
    }

    /**
     * Whether $have meets the minimum $need; null when either cannot be read
     * (the caller decides, conservatively).
     */
    public static function satisfies(?string $have, ?string $need): ?bool
    {
        $a = self::parse($have);
        $b = self::parse($need);

        return $a === null || $b === null ? null : $a->compare($b) >= 0;
    }

    /**
     * Total order for sorting offers: an unreadable version sorts below any readable one.
     */
    public static function compareStrings(string $a, string $b): int
    {
        $x = self::parse($a);
        $y = self::parse($b);

        return match (true) {
            $x !== null && $y !== null => $x->compare($y),
            $x === null && $y === null => strcmp($a, $b),
            default => $x === null ? -1 : 1,
        };
    }

    /**
     * The first $count numeric components, zero-padded (6.9 → [6, 9]).
     *
     * @return list<int>
     */
    public function head(int $count): array
    {
        return array_map(fn (int $i): int => $this->numbers[$i] ?? 0, range(0, $count - 1));
    }
}
