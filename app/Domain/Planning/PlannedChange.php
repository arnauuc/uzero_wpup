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
    ) {}

    public static function skip(self $change, SkipReason $reason, ?string $detail = null): self
    {
        return new self($change->type, $change->slug, $change->currentVersion, $change->targetVersion, PlanAction::Skip, $reason, $detail);
    }

    /**
     * @return array{type: string, slug: string, current_version: string, target_version: string, action: string, reason: ?string, detail: ?string}
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

        if (! is_string($type) || ! is_string($action) || ! is_string($slug) || ! is_string($current) || ! is_string($target)) {
            throw new UnexpectedValueException('Malformed planned change.');
        }

        $componentType = ComponentType::tryFrom($type);
        $planAction = PlanAction::tryFrom($action);
        $skipReason = is_string($reason) ? SkipReason::tryFrom($reason) : null;
        if ($componentType === null || $planAction === null || (is_string($reason) && $skipReason === null)) {
            throw new UnexpectedValueException('Unknown value in a planned change.');
        }

        return new self($componentType, $slug, $current, $target, $planAction, $skipReason, is_string($detail) ? $detail : null);
    }
}
