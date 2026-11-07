<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * What the scheduler knows of a site's pending updates before it spends any
 * time on it (ADR-0006 §3), from the plan of its latest inventory recomputed
 * now (UpdateOutlooks). Unknown when there is no recent inventory: the run
 * then decides.
 */
final readonly class UpdateOutlook
{
    /**
     * @param  list<string>  $majors  «type:slug versió→versió» of the majors that wait for staging
     */
    public function __construct(
        /** True: nothing to apply; false: something to apply; null: unknown. */
        public ?bool $nothingToDo,
        /** A security update is pending: a known vulnerability or a core x.y.z (ADR-0005). */
        public bool $security = false,
        public array $majors = [],
        /** Operator-facing: the plan's summary or why nothing applies. */
        public ?string $note = null,
    ) {}

    public static function unknown(?string $note = null): self
    {
        return new self(null, false, [], $note);
    }

    /**
     * @param  array<mixed>  $data  as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $nothing = $data['nothing_to_do'] ?? null;
        $majors = $data['majors'] ?? null;
        $note = $data['note'] ?? null;

        return new self(
            is_bool($nothing) ? $nothing : null,
            ($data['security'] ?? false) === true,
            is_array($majors) ? array_values(array_filter($majors, is_string(...))) : [],
            is_string($note) ? $note : null,
        );
    }

    /**
     * @return array{nothing_to_do: ?bool, security: bool, majors: list<string>, note: ?string}
     */
    public function toArray(): array
    {
        return ['nothing_to_do' => $this->nothingToDo, 'security' => $this->security, 'majors' => $this->majors, 'note' => $this->note];
    }
}
