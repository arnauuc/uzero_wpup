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
 * - Components of any length, compared as numbers (no integer overflow).
 * - Pre-releases, with or without separator and number, only when the whole
 *   suffix is one such token (optionally followed by "-…" as in WordPress trunk):
 *   dev < alpha (a, pre) < beta (b) < RC < release < pl/p (patch level).
 *   6.5-RC1 < 6.5-RC2 < 6.5; 6.9-alpha-60000-src is 6.9 alpha.
 * - Build metadata after "+" and any other suffix after "-", "_" or "~"
 *   (PHP's 8.1.2-1ubuntu2.14, 2.0.0-b2b, 1.2.3_patch1) are ignored: the release.
 *
 * Anything else (empty, "6.x", ">= 7.4", "6.5 or higher", "dev-main") does not
 * parse: callers must treat it conservatively, never as satisfied.
 */
final readonly class WpVersion
{
    private const array STAGES = ['dev' => 0, 'alpha' => 1, 'a' => 1, 'pre' => 1, 'beta' => 2, 'b' => 2, 'rc' => 3, 'pl' => 5, 'p' => 5];

    private const int RELEASE = 4;

    /**
     * @param  list<string>  $numbers  digits without leading zeros ("0" for zero)
     */
    private function __construct(
        public array $numbers,
        public int $stage,
        public int $stageNumber,
    ) {}

    public static function parse(?string $value): ?self
    {
        $text = strtolower(trim((string) $value));
        if (preg_match('/^v?(\d+(?:\.\d+)*)(.*)$/', $text, $m) !== 1) {
            return null;
        }

        $numbers = array_map(static fn (string $n): string => ltrim($n, '0') === '' ? '0' : ltrim($n, '0'), explode('.', $m[1]));
        $suffix = explode('+', $m[2], 2)[0];
        if ($suffix === '') {
            return new self($numbers, self::RELEASE, 0);
        }

        if (preg_match('/^[-._~]?(dev|alpha|beta|pre|rc|pl|a|b|p)[-._]?(\d{0,9})(?:-.*)?$/', $suffix, $s) === 1) {
            return new self($numbers, self::STAGES[$s[1]], $s[2] === '' ? 0 : (int) $s[2]);
        }

        // A distribution or build suffix: the release itself.
        return preg_match('/^[-_~][0-9a-z][0-9a-z._~-]*$/', $suffix) === 1 ? new self($numbers, self::RELEASE, 0) : null;
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
            $a = $this->numbers[$i] ?? '0';
            $b = $other->numbers[$i] ?? '0';
            // Same length: byte order is numeric order (no float conversion of long numbers).
            $cmp = strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
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
     * The first $count numeric components, zero-padded (6.9 → ['6', '9']).
     *
     * @return list<string>
     */
    public function head(int $count): array
    {
        return array_map(fn (int $i): string => $this->numbers[$i] ?? '0', range(0, $count - 1));
    }
}
