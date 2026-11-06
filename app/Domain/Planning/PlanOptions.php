<?php

declare(strict_types=1);

namespace App\Domain\Planning;

/**
 * Per-run overrides of the version policy (ADR-0005 sections 2 and 4), only for an
 * `admin` and audited. Stored in runs.flags so the update run (UZEROWPUP-60) and
 * the report (F5) read the same values.
 */
final readonly class PlanOptions
{
    public const string ALLOW_MAJOR = 'allow_major';

    public const string SKIP_QUARANTINE = 'skip_quarantine';

    public function __construct(
        /** Before F7: apply the changes that need staging in place. */
        public bool $allowMajor = false,
        /** Apply versions still in quarantine. */
        public bool $skipQuarantine = false,
    ) {}

    /**
     * @param  array<mixed>  $flags  runs.flags
     */
    public static function fromFlags(array $flags): self
    {
        return new self(($flags[self::ALLOW_MAJOR] ?? false) === true, ($flags[self::SKIP_QUARANTINE] ?? false) === true);
    }

    /**
     * Only the overrides that are on.
     *
     * @return array<string, bool>
     */
    public function toFlags(): array
    {
        return array_filter([self::ALLOW_MAJOR => $this->allowMajor, self::SKIP_QUARANTINE => $this->skipQuarantine]);
    }

    public function any(): bool
    {
        return $this->allowMajor || $this->skipQuarantine;
    }
}
