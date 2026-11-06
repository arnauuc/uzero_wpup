<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\PhpVersionSource;
use UnexpectedValueException;

/**
 * What would be updated on a site and what would be skipped, in update order.
 */
final readonly class UpdatePlan
{
    /**
     * @param  list<PlannedChange>  $changes  ordered core → WooCommerce → plugins → themes → translations
     */
    public function __construct(
        public string $phpVersion,
        public PhpVersionSource $phpSource,
        /** True when the web's PHP was confirmed; false when it is the CLI's guess. */
        public bool $phpVerified,
        public ?string $blockedReason,
        public array $changes,
        /** Overrides of the version policy this plan was made with (ADR-0005). */
        public PlanOptions $options = new PlanOptions,
        /** @var list<string> FutureMinimums warnings (UZEROWPUP-52): releases that will freeze the site. */
        public array $environmentWarnings = [],
    ) {}

    /**
     * ADR-0005: one change that needs staging makes the whole run of the site staging-first.
     * Before F7 those changes are skipped, unless --allow-major applies them in place.
     */
    public function requiresStaging(): bool
    {
        return array_filter($this->changes, static fn (PlannedChange $c): bool => $c->requiresStaging()) !== [];
    }

    /**
     * Majors that would be applied (only with --allow-major before F7): the report's
     * «Majors aplicades: cal revisar» and a warning notification (F5).
     *
     * @return list<PlannedChange>
     */
    public function appliedMajors(): array
    {
        return array_values(array_filter($this->changes, static fn (PlannedChange $c): bool => $c->major && $c->action === PlanAction::Update));
    }

    public function updateCount(): int
    {
        return count(array_filter($this->changes, static fn (PlannedChange $c): bool => $c->action === PlanAction::Update));
    }

    public function skipCount(): int
    {
        return count($this->changes) - $this->updateCount();
    }

    public function summary(): string
    {
        return sprintf("%d s'actualitzarien, %d s'ometrien", $this->updateCount(), $this->skipCount());
    }

    /**
     * @return array{php: array{version: string, source: string, verified: bool}, blocked_reason: ?string, requires_staging: bool, overrides: array{allow_major: bool, skip_quarantine: bool}, environment_warnings: list<string>, changes: list<array{type: string, slug: string, current_version: string, target_version: string, action: string, reason: ?string, detail: ?string, major: bool, quarantine_bypass: ?string, quarantine_days_left: ?int, warnings?: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'php' => ['version' => $this->phpVersion, 'source' => $this->phpSource->value, 'verified' => $this->phpVerified],
            'blocked_reason' => $this->blockedReason,
            'requires_staging' => $this->requiresStaging(),
            'overrides' => [PlanOptions::ALLOW_MAJOR => $this->options->allowMajor, PlanOptions::SKIP_QUARANTINE => $this->options->skipQuarantine],
            'environment_warnings' => $this->environmentWarnings,
            'changes' => array_map(static fn (PlannedChange $c): array => $c->toArray(), $this->changes),
        ];
    }

    /**
     * @param  array<mixed>  $data  as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $php = $data['php'] ?? null;
        $changes = $data['changes'] ?? null;
        $blocked = $data['blocked_reason'] ?? null;
        if (! is_array($php) || ! is_array($changes) || ! is_string($php['version'] ?? null) || ! is_string($php['source'] ?? null)) {
            throw new UnexpectedValueException('Malformed update plan.');
        }

        $parsed = [];
        foreach ($changes as $change) {
            $parsed[] = is_array($change) ? PlannedChange::fromArray($change) : throw new UnexpectedValueException('Malformed update plan.');
        }

        $source = PhpVersionSource::tryFrom($php['source']) ?? throw new UnexpectedValueException('Unknown PHP source in an update plan.');

        $overrides = $data['overrides'] ?? null;
        $warnings = $data['environment_warnings'] ?? null;

        return new self($php['version'], $source, ($php['verified'] ?? false) === true, is_string($blocked) ? $blocked : null, $parsed, PlanOptions::fromFlags(is_array($overrides) ? $overrides : []), is_array($warnings) ? array_values(array_filter($warnings, is_string(...))) : []);
    }
}
