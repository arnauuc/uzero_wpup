<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * An order or a user written since a copy (NewWrites). No personal data: its
 * ID, whether it is new (or a modified one), its date and a label (the order's
 * type and status, or the user's roles).
 */
final readonly class AffectedRecord
{
    public function __construct(
        public int $id,
        public bool $new,
        public ?string $date,
        public string $label,
    ) {}

    /**
     * @return array{id: int, new: bool, date: string|null, label: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'new' => $this->new, 'date' => $this->date, 'label' => $this->label];
    }
}
