<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The single queue of ADR-0006 §2: the runs that take the global slot start in
 * the order they were queued (runs.id), manual or scheduled alike. Only an
 * admin's `--now` (audited) goes ahead: it does not take the global slot.
 *
 * A run that may not start now (an update outside its site's window) does not
 * hold the ones behind it. A run queued more than `staleQueuedHours` ago is
 * ignored (and logged): a lost job must not stop the fleet.
 */
final readonly class GlobalQueue
{
    public function __construct(private MaintenanceWindowGate $windows, private int $staleQueuedHours) {}

    /**
     * The first queued run that takes the global slot and may start now;
     * with $before, only one queued before it.
     */
    public function head(CarbonImmutable $now, ?Run $before = null): ?Run
    {
        $query = Run::query()->with('site')
            ->where('status', RunStatus::Queued->value)
            ->whereIn('type', self::slotTypes())
            ->whereRaw("COALESCE(flags->>'now', 'false') <> 'true'")
            ->when($before !== null, static fn ($q) => $q->where('id', '<', $before->id ?? 0))
            ->orderBy('id');

        foreach ($query->get() as $run) {
            if ($run->queued_at->lessThan($now->subHours($this->staleQueuedHours))) {
                Log::warning('A run has been queued for too long: it no longer holds the queue.', ['run' => $run->id, 'queued_at' => $run->queued_at->toIso8601String()]);

                continue;
            }
            if ($this->mayStart($run, $now)) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @throws ConcurrencyLimitReached when a run queued before this one must start first
     */
    public function assertTurn(Run $run, CarbonImmutable $now): void
    {
        if (($run->flags['now'] ?? false) === true || ! $run->type->usesConcurrencyLimits()) {
            return;
        }
        if ($this->head($now, $run) !== null) {
            throw new ConcurrencyLimitReached('global');
        }
    }

    /**
     * The run types that take the global slot (RunType::usesConcurrencyLimits()).
     *
     * @return list<string>
     */
    public static function slotTypes(): array
    {
        return array_values(array_map(
            static fn (RunType $type): string => $type->value,
            array_filter(RunType::cases(), static fn (RunType $type): bool => $type->usesConcurrencyLimits()),
        ));
    }

    private function mayStart(Run $run, CarbonImmutable $now): bool
    {
        if (! $run->type->respectsMaintenanceWindow() || $run->site === null) {
            return true;
        }
        try {
            return $this->windows->nextStart($run->site, $now) === null;
        } catch (InvalidArgumentException) {
            // Its engine fails it as soon as it picks it up.
            return true;
        }
    }
}
