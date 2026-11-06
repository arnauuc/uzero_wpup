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

    public const string INCLUDE = 'include';

    public function __construct(
        /** Before F7: apply the changes that need staging in place. */
        public bool $allowMajor = false,
        /** Apply versions still in quarantine. */
        public bool $skipQuarantine = false,
        /** @var list<string> frozen or excluded components of the profile to plan anyway in this run (UZEROWPUP-51) */
        public array $include = [],
    ) {}

    /**
     * @param  array<mixed>  $flags  runs.flags
     */
    public static function fromFlags(array $flags): self
    {
        $include = $flags[self::INCLUDE] ?? [];

        return new self(
            ($flags[self::ALLOW_MAJOR] ?? false) === true,
            ($flags[self::SKIP_QUARANTINE] ?? false) === true,
            is_array($include) ? array_values(array_filter($include, is_string(...))) : [],
        );
    }

    /**
     * Only the overrides that are on.
     *
     * @return array<string, bool|list<string>>
     */
    public function toFlags(): array
    {
        return array_filter([self::ALLOW_MAJOR => $this->allowMajor, self::SKIP_QUARANTINE => $this->skipQuarantine, self::INCLUDE => $this->include]);
    }

    /**
     * The same, without the profile override: what plan.version_policy_overridden audits.
     *
     * @return array<string, bool>
     */
    public function versionPolicyFlags(): array
    {
        return array_filter([self::ALLOW_MAJOR => $this->allowMajor, self::SKIP_QUARANTINE => $this->skipQuarantine]);
    }

    public function any(): bool
    {
        return $this->allowMajor || $this->skipQuarantine || $this->include !== [];
    }
}
