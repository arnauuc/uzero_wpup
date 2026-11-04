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
    ) {}

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
     * @return array{php: array{version: string, source: string, verified: bool}, blocked_reason: ?string, changes: list<array{type: string, slug: string, current_version: string, target_version: string, action: string, reason: ?string, detail: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'php' => ['version' => $this->phpVersion, 'source' => $this->phpSource->value, 'verified' => $this->phpVerified],
            'blocked_reason' => $this->blockedReason,
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

        return new self($php['version'], PhpVersionSource::from($php['source']), ($php['verified'] ?? false) === true, is_string($blocked) ? $blocked : null, $parsed);
    }
}
