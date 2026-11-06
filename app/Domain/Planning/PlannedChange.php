<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\ComponentType;
use App\Enums\SkipReason;
use UnexpectedValueException;

/**
 * One component in the plan: what would change and, if it would not, why.
 */
final readonly class PlannedChange
{
    public function __construct(
        public ComponentType $type,
        public string $slug,
        public string $currentVersion,
        public string $targetVersion,
        public PlanAction $action,
        public ?SkipReason $reason = null,
        public ?string $detail = null,
        /** A major change (ADR-0005): needs staging first; applied, it must be reviewed. */
        public bool $major = false,
        /** Why it does not wait for the quarantine, if it does not. */
        public ?QuarantineBypass $bypass = null,
        /** Whole days until it leaves the quarantine, when skipped for it. */
        public ?int $quarantineDaysLeft = null,
        /** @var list<string> warnings of the static analysis, for the report (UZEROWPUP-50) */
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->type, $this->slug, $this->currentVersion, $this->targetVersion, $this->action, $this->reason, $this->detail, $this->major, $this->bypass, $this->quarantineDaysLeft, $warnings);
    }

    public static function skip(self $change, SkipReason $reason, ?string $detail = null, ?int $quarantineDaysLeft = null): self
    {
        return new self($change->type, $change->slug, $change->currentVersion, $change->targetVersion, PlanAction::Skip, $reason, $detail, $change->major, $change->bypass, $quarantineDaysLeft, $change->warnings);
    }

    /**
     * The same update with a note for the operator.
     */
    public function withDetail(?string $detail): self
    {
        return new self($this->type, $this->slug, $this->currentVersion, $this->targetVersion, $this->action, $this->reason, $detail, $this->major, $this->bypass, $this->quarantineDaysLeft, $this->warnings);
    }

    /**
     * Whether the change makes the whole run of the site need staging first (ADR-0005 section 1).
     */
    public function requiresStaging(): bool
    {
        return $this->major && ($this->action === PlanAction::Update || $this->reason === SkipReason::MajorRequiresStaging);
    }

    /**
     * @return array{type: string, slug: string, current_version: string, target_version: string, action: string, reason: ?string, detail: ?string, major: bool, quarantine_bypass: ?string, quarantine_days_left: ?int, warnings?: list<string>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'slug' => $this->slug,
            'current_version' => $this->currentVersion,
            'target_version' => $this->targetVersion,
            'action' => $this->action->value,
            'reason' => $this->reason?->value,
            'detail' => $this->detail,
            'major' => $this->major,
            'quarantine_bypass' => $this->bypass?->value,
            'quarantine_days_left' => $this->quarantineDaysLeft,
            ...($this->warnings === [] ? [] : ['warnings' => $this->warnings]),
        ];
    }

    /**
     * @param  array<mixed>  $data  as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $reason = $data['reason'] ?? null;
        $detail = $data['detail'] ?? null;
        $type = $data['type'] ?? null;
        $action = $data['action'] ?? null;
        $slug = $data['slug'] ?? null;
        $current = $data['current_version'] ?? null;
        $target = $data['target_version'] ?? null;
        $bypass = $data['quarantine_bypass'] ?? null;
        $daysLeft = $data['quarantine_days_left'] ?? null;

        if (! is_string($type) || ! is_string($action) || ! is_string($slug) || ! is_string($current) || ! is_string($target)) {
            throw new UnexpectedValueException('Malformed planned change.');
        }

        $componentType = ComponentType::tryFrom($type);
        $planAction = PlanAction::tryFrom($action);
        $skipReason = is_string($reason) ? SkipReason::tryFrom($reason) : null;
        $quarantineBypass = is_string($bypass) ? QuarantineBypass::tryFrom($bypass) : null;
        if ($componentType === null || $planAction === null || (is_string($reason) && $skipReason === null) || (is_string($bypass) && $quarantineBypass === null)) {
            throw new UnexpectedValueException('Unknown value in a planned change.');
        }

        // Plans stored before UZEROWPUP-127 have no major (recomputed), bypass or days left.
        $warnings = is_array($data['warnings'] ?? null) ? array_values(array_filter($data['warnings'], is_string(...))) : [];
        $major = array_key_exists('major', $data) ? $data['major'] === true : VersionChange::isMajor($componentType, $current, $target);

        return new self($componentType, $slug, $current, $target, $planAction, $skipReason, is_string($detail) ? $detail : null, $major, $quarantineBypass, is_int($daysLeft) ? $daysLeft : null, $warnings);
    }
}
