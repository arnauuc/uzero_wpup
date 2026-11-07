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
     * @return array{nothing_to_do: ?bool, security: bool, majors: list<string>, note: ?string}
     */
    public function toArray(): array
    {
        return ['nothing_to_do' => $this->nothingToDo, 'security' => $this->security, 'majors' => $this->majors, 'note' => $this->note];
    }
}
