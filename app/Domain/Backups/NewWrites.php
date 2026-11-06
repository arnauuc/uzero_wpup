<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\NewWritesStatus;

/**
 * What NewWritesDetector found: whether the orders or users of a site were
 * written since a copy, which ones (at most the configured number, newest
 * first) and how many rows changed per table. Unknown counts as written.
 */
final readonly class NewWrites
{
    /**
     * @param  list<AffectedRecord>  $orders
     * @param  list<AffectedRecord>  $users
     * @param  array<string, int>  $tables  rows written since the copy, by table (only those with any)
     */
    public function __construct(
        public NewWritesStatus $status,
        public array $orders = [],
        public array $users = [],
        public int $ordersTotal = 0,
        public int $usersTotal = 0,
        public bool $truncated = false,
        public array $tables = [],
        public ?string $reason = null,
    ) {}

    public static function unknown(string $reason): self
    {
        return new self(NewWritesStatus::Unknown, reason: $reason);
    }

    /**
     * AGENTS.md rule 4: restoring the database may lose data unless it is
     * known that nothing was written.
     */
    public function mayLoseData(): bool
    {
        return $this->status !== NewWritesStatus::None;
    }

    public function describe(): string
    {
        if ($this->status === NewWritesStatus::None) {
            return 'No hi ha escriptures noves a les comandes ni als usuaris des de la còpia.';
        }
        if ($this->status === NewWritesStatus::Unknown) {
            return "No es pot saber si hi ha escriptures noves a les comandes i als usuaris des de la còpia ({$this->reason}): es tracta com si n'hi hagués.";
        }

        $parts = [];
        foreach ([[$this->orders, $this->ordersTotal, 'comanda', 'comandes', 'nova', 'modificada'], [$this->users, $this->usersTotal, 'usuari', 'usuaris', 'nou', 'modificat']] as [$records, $total, $one, $many, $new, $modified]) {
            if ($total === 0) {
                continue;
            }
            $listed = implode(', ', array_map(static fn (AffectedRecord $r): string => "#{$r->id} (".($r->new ? $new : $modified).", {$r->label}".($r->date === null ? '' : ", {$r->date}").')', $records));
            $more = $total - count($records);
            $parts[] = ($this->truncated ? 'almenys ' : '').$total.' '.($total === 1 ? $one : $many).': '.$listed.($more > 0 ? " i {$more} més" : '');
        }
        if ($parts === []) {
            $parts[] = 'files canviades a '.implode(', ', array_map(static fn (string $t, int $n): string => "{$t} ({$n})", array_keys($this->tables), $this->tables));
        }

        return 'Des de la còpia hi ha escriptures noves: '.implode('; ', $parts).'.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'orders' => array_map(static fn (AffectedRecord $r): array => $r->toArray(), $this->orders),
            'users' => array_map(static fn (AffectedRecord $r): array => $r->toArray(), $this->users),
            'orders_total' => $this->ordersTotal,
            'users_total' => $this->usersTotal,
            'truncated' => $this->truncated,
            'tables' => $this->tables,
            'reason' => $this->reason,
        ];
    }
}
