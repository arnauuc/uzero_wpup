<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditActor;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Concurrency\GlobalQueue;
use App\Domain\Runs\Concurrency\MaintenanceWindowGate;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\ScheduledWindowStatus;
use App\Enums\SiteBlockSource;
use App\Enums\UpdateStrategy;
use App\Models\Run;
use App\Models\ScheduledWindow;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The continuous scheduler of ADR-0006 (UZEROWPUP-129), one tick a minute:
 *
 * 1. Settles the windows whose run finished (succeeded, failed or rolled back).
 *    A window is never tried again: a site that failed waits for its next one.
 * 2. Closes the queued windows that closed before their turn: deferred to the
 *    site's next window, with priority.
 * 3. Queues every active site with automatic updates whose window is open and
 *    that has no record for that window yet. A window is identified by the
 *    site and the local date it opens on (scheduled_windows.window_date), so
 *    editing the window or a change of the clocks never makes a second one;
 *    and a site whose previous window is still open (its run started late) is
 *    not queued again until that one is settled.
 * 4. Queues the update of the next scheduled site, only when nothing holds or
 *    waits for the single queue (no run that takes the global slot is active,
 *    holds the slot or may start: GlobalQueue). Manual and scheduled runs then
 *    share that queue in the order they were queued; only an admin's --now
 *    goes ahead. Which scheduled site goes next: a pending security update
 *    (known vulnerability or core x.y.z), then the sites deferred from their
 *    previous window, then the longest without a successful update, then by
 *    name. A site whose lock (or its hosting account's) is held waits for the
 *    next tick. Sites with nothing applicable (UpdateOutlooks, offline, once
 *    per window) are skipped without spending any time; a site whose estimated
 *    duration (UpdateDurationEstimator) does not fit in what is left of its
 *    window waits for its next one, with priority.
 *
 * The runs are queued as `sistema (scheduler)` with the site's strategy and
 * the version policy of ADR-0005: never --allow-major nor --skip-quarantine.
 * The run and its window are recorded in one transaction; the run reaches the
 * engine after the commit. A site that fails to be handled is logged and the
 * tick goes on with the others. A dry run decides the same and writes nothing.
 */
final readonly class UpdateScheduler
{
    public function __construct(
        private MaintenanceWindowGate $windows,
        private GlobalQueue $queue,
        private ConcurrencyGate $slots,
        private SiteLockManager $locks,
        private UpdateOutlooks $outlooks,
        private UpdateDurationEstimator $estimator,
        private RunLauncher $launcher,
        private string $actor,
        private int $minimumMinutes,
    ) {}

    /**
     * @return list<SchedulerDecision>
     */
    public function tick(CarbonImmutable $now, bool $dryRun = false): array
    {
        $decisions = [];
        $this->settle($dryRun, $decisions);
        $this->expire($now, $dryRun, $decisions);
        $queued = $this->enqueue($now, $dryRun, $decisions);
        $this->dispatch($now, $dryRun, $queued, $decisions);

        return $decisions;
    }

    /**
     * @param  list<SchedulerDecision>  $decisions
     */
    private function settle(bool $dryRun, array &$decisions): void
    {
        $launched = ScheduledWindow::query()->with(['run', 'site'])->where('status', ScheduledWindowStatus::Launched->value)->orderBy('id')->get();
        foreach ($launched as $entry) {
            $run = $entry->run;
            if ($run === null || ! $run->status->isTerminal()) {
                continue;
            }
            [$status, $reason] = match ($run->status) {
                RunStatus::Succeeded => [ScheduledWindowStatus::Succeeded, null],
                RunStatus::Failed, RunStatus::RolledBack => [ScheduledWindowStatus::Failed, $run->failureReason()],
                default => [ScheduledWindowStatus::Cancelled, "L'execució ha acabat com a {$run->status->value}."],
            };
            $this->close($entry, $status, $reason, ['run_status' => $run->status->value], $dryRun);
            $decisions[] = new SchedulerDecision(SchedulerAction::Settled, $entry->site?->name, $status->label().($reason === null ? '' : ": {$reason}"), $run->id);
        }
    }

    /**
     * @param  list<SchedulerDecision>  $decisions
     */
    private function expire(CarbonImmutable $now, bool $dryRun, array &$decisions): void
    {
        $closed = ScheduledWindow::query()->with('site')
            ->where('status', ScheduledWindowStatus::Pending->value)
            ->where('closes_at', '<=', self::bind($now))
            ->orderBy('id')->get();
        foreach ($closed as $entry) {
            $reason = "La finestra s'ha tancat abans que li arribés el torn a la cua.";
            $this->close($entry, ScheduledWindowStatus::Deferred, $reason, [], $dryRun);
            $decisions[] = new SchedulerDecision(SchedulerAction::Deferred, $entry->site?->name, $reason);
        }
    }

    /**
     * @param  list<SchedulerDecision>  $decisions
     * @return list<ScheduledWindow> the windows it queued (not saved in a dry run)
     */
    private function enqueue(CarbonImmutable $now, bool $dryRun, array &$decisions): array
    {
        $queued = [];
        foreach ($this->eligibleSites() as $site) {
            try {
                $entry = $this->windowFor($site, $now);
            } catch (Throwable $e) {
                Log::error('The scheduler could not queue a site.', ['site' => $site->name, 'error' => $e->getMessage()]);

                continue;
            }
            if ($entry === null) {
                continue;
            }
            if (! $dryRun) {
                $entry->save();
            }
            $queued[] = $entry;
            $label = $entry->details['window'] ?? null;
            $decisions[] = new SchedulerDecision(SchedulerAction::Enqueued, $site->name, (is_string($label) ? $label : '').' fins a les '
                .$entry->closes_at->setTimezone($this->windows->timezone())->format('H:i').($entry->priority ? ', amb prioritat' : ''));
        }

        return $queued;
    }

    /**
     * The new (unsaved) record of the site's open window; null when its window
     * is closed or already has one, or its previous one is still open.
     */
    private function windowFor(Site $site, CarbonImmutable $now): ?ScheduledWindow
    {
        try {
            $window = $this->windows->windowOf($site);
        } catch (InvalidArgumentException $e) {
            Log::warning('A site with automatic updates has an invalid maintenance window.', ['site' => $site->name, 'error' => $e->getMessage()]);

            return null;
        }
        $occurrence = $window->occurrence($now);
        if ($occurrence === null) {
            return null;
        }
        [$opens, $closes] = $occurrence;
        $date = $this->dateOf($opens);
        $records = ScheduledWindow::query()->where('site_id', $site->id);
        if ((clone $records)->where('window_date', $date)->exists()
            || (clone $records)->whereIn('status', [ScheduledWindowStatus::Pending->value, ScheduledWindowStatus::Launched->value])->exists()) {
            return null;
        }

        $previous = (clone $records)->orderByDesc('window_date')->first();
        $entry = new ScheduledWindow([
            'site_id' => $site->id,
            'window_date' => $date,
            'opens_at' => $opens->utc(),
            'closes_at' => $closes->utc(),
            'status' => ScheduledWindowStatus::Pending,
            'priority' => $previous?->status === ScheduledWindowStatus::Deferred,
            'details' => ['window' => $window->label()],
        ]);
        $entry->setRelation('site', $site);

        return $entry;
    }

    /**
     * @param  list<ScheduledWindow>  $queued
     * @param  list<SchedulerDecision>  $decisions
     */
    private function dispatch(CarbonImmutable $now, bool $dryRun, array $queued, array &$decisions): void
    {
        $busy = $this->busy($now);
        if ($busy !== null) {
            $decisions[] = new SchedulerDecision(SchedulerAction::Waiting, null, $busy);

            return;
        }

        $pending = ScheduledWindow::query()->with('site')
            ->where('status', ScheduledWindowStatus::Pending->value)
            ->where('closes_at', '>', self::bind($now))
            ->get()->all();
        if ($dryRun) {
            array_push($pending, ...$queued);
        }

        $candidates = [];
        foreach ($pending as $entry) {
            try {
                $outlook = $this->review($entry, $now, $dryRun, $decisions);
            } catch (Throwable $e) {
                Log::error('The scheduler could not review a queued site.', ['site' => $entry->site?->name, 'error' => $e->getMessage()]);

                continue;
            }
            if ($outlook !== null && $entry->site !== null) {
                $candidates[] = ['entry' => $entry, 'site' => $entry->site, 'outlook' => $outlook];
            }
        }

        $last = $this->lastSuccess(array_map(static fn (array $c): int => $c['site']->id, $candidates));
        usort($candidates, static fn (array $a, array $b): int => [! $a['outlook']->security, ! $a['entry']->priority, $last[$a['site']->id] ?? 0, $a['site']->name]
            <=> [! $b['outlook']->security, ! $b['entry']->priority, $last[$b['site']->id] ?? 0, $b['site']->name]);

        foreach ($candidates as ['entry' => $entry, 'site' => $site, 'outlook' => $outlook]) {
            try {
                if ($this->start($entry, $site, $outlook, $now, $dryRun, $decisions)) {
                    return;
                }
            } catch (Throwable $e) {
                Log::error('The scheduler could not start a site.', ['site' => $site->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Whether the queued window may still go: its outlook when it is a
     * candidate; null when it was cancelled or skipped (recorded).
     *
     * @param  list<SchedulerDecision>  $decisions
     */
    private function review(ScheduledWindow $entry, CarbonImmutable $now, bool $dryRun, array &$decisions): ?UpdateOutlook
    {
        $site = $entry->site;
        if ($site === null) {
            return null;
        }
        $site->refresh();

        $cancel = match (true) {
            ! $site->active => 'El lloc està desactivat.',
            ! $site->auto_update => "S'ha desactivat l'actualització automàtica del lloc.",
            default => $this->windowChanged($entry, $site, $now, $dryRun),
        };
        if ($cancel !== null) {
            $this->close($entry, ScheduledWindowStatus::Cancelled, $cancel, [], $dryRun);
            $decisions[] = new SchedulerDecision(SchedulerAction::Cancelled, $site->name, $cancel);

            return null;
        }

        $skip = $this->notAutomatic($site);
        $outlook = null;
        if ($skip === null) {
            $outlook = $this->outlookOf($entry, $site, $now, $dryRun);
            if ($outlook->nothingToDo === true) {
                $skip = 'Res a aplicar: '.$outlook->note;
            }
        }
        if ($skip !== null) {
            $this->close($entry, ScheduledWindowStatus::Skipped, $skip, [], $dryRun);
            $decisions[] = new SchedulerDecision(SchedulerAction::Skipped, $site->name, $skip);

            return null;
        }

        return $outlook;
    }

    /**
     * Why the queued window no longer stands after an edit of the site's
     * window (null when it does; a later closing is applied to it).
     */
    private function windowChanged(ScheduledWindow $entry, Site $site, CarbonImmutable $now, bool $dryRun): ?string
    {
        try {
            $occurrence = $this->windows->windowOf($site)->occurrence($now);
        } catch (InvalidArgumentException $e) {
            return "La finestra del lloc ja no és vàlida: {$e->getMessage()}";
        }
        if ($occurrence === null || $this->dateOf($occurrence[0]) !== $entry->window_date) {
            return "La finestra del lloc ha canviat i ja no és oberta: s'espera a la propera.";
        }
        if (! $occurrence[1]->equalTo($entry->closes_at)) {
            $entry->forceFill(['opens_at' => $occurrence[0]->utc(), 'closes_at' => $occurrence[1]->utc()]);
            if (! $dryRun) {
                $entry->save();
            }
        }

        return null;
    }

    /**
     * The site's outlook, computed once per window and kept with it.
     */
    private function outlookOf(ScheduledWindow $entry, Site $site, CarbonImmutable $now, bool $dryRun): UpdateOutlook
    {
        $stored = $entry->details['outlook'] ?? null;
        if (is_array($stored)) {
            return UpdateOutlook::fromArray($stored);
        }
        $outlook = $this->outlooks->of($site, $now);
        $entry->forceFill(['details' => [...$entry->details, 'outlook' => $outlook->toArray()]]);
        if (! $dryRun) {
            $entry->save();
        }

        return $outlook;
    }

    /**
     * Queues the site's update if it may start now and fits in its window.
     * True when it did (the tick is over).
     *
     * @param  list<SchedulerDecision>  $decisions
     */
    private function start(ScheduledWindow $entry, Site $site, UpdateOutlook $outlook, CarbonImmutable $now, bool $dryRun, array &$decisions): bool
    {
        // It could not start now: another one may.
        if ($this->locks->holder(LockLevel::Site, $site->id) !== null || $this->locks->holder(LockLevel::HostingAccount, $site->hosting_account_id) !== null) {
            $decisions[] = new SchedulerDecision(SchedulerAction::Waiting, $site->name, 'El lloc o el seu compte d\'hosting estan ocupats.');

            return false;
        }

        $estimate = $this->estimator->estimateSeconds($site, $site->strategy);
        $needed = max($estimate, $this->minimumMinutes * 60);
        $left = $entry->closes_at->getTimestamp() - $now->getTimestamp();
        if ($left < $needed) {
            $reason = sprintf('No hi cap: cal uns %d min i en queden %d de la finestra.', intdiv($needed + 59, 60), intdiv(max(0, $left), 60));
            $this->close($entry, ScheduledWindowStatus::Deferred, $reason, ['estimate_seconds' => $estimate], $dryRun);
            $decisions[] = new SchedulerDecision(SchedulerAction::Deferred, $site->name, $reason);

            return false;
        }

        $run = null;
        if (! $dryRun) {
            // The run and its window together, or neither; the engine sees the run after the commit.
            $run = DB::transaction(function () use ($entry, $site, $estimate): Run {
                $run = $this->launcher->prepare(RunType::Update, $site, AuditActor::system($this->actor), ['scheduled' => true, 'scheduled_window_id' => $entry->id], $site->strategy);
                $entry->forceFill(['status' => ScheduledWindowStatus::Launched, 'run_id' => $run->id, 'details' => [...$entry->details, 'estimate_seconds' => $estimate]])->save();

                return $run;
            });
            $this->launcher->dispatch($run);
        }
        $decisions[] = new SchedulerDecision(SchedulerAction::Launched, $site->name, sprintf('estimació: %d min%s', intdiv($estimate + 59, 60), $outlook->security ? '; actualització de seguretat pendent' : ''), $run?->id);

        return true;
    }

    /**
     * Why nothing may be queued now (a run holds or waits for the single queue); null when idle.
     */
    private function busy(CarbonImmutable $now): ?string
    {
        $active = Run::query()->whereIn('type', GlobalQueue::slotTypes())
            ->whereIn('status', [RunStatus::Running->value, RunStatus::RollingBack->value, RunStatus::Interrupted->value])
            ->orderBy('id')->value('id');
        if (is_int($active)) {
            return "L'execució #{$active} està en curs.";
        }
        if ($this->slots->occupied('global')) {
            return 'Una execució té la plaça de la cua global.';
        }
        $head = $this->queue->head($now);

        return $head === null ? null : "L'execució #{$head->id} espera torn a la cua.";
    }

    /**
     * Why the scheduler does not update the site at all (operator block, a strategy not available yet).
     */
    private function notAutomatic(Site $site): ?string
    {
        if ($site->blocked_by === SiteBlockSource::Operator || ($site->blocked_by === null && $site->blocked_reason !== null)) {
            return "Bloquejat per un operador: {$site->blocked_reason}";
        }
        if ($site->strategy === UpdateStrategy::StagingFirst) {
            return "L'estratègia del lloc és staging, que arriba a F7.";
        }

        return null;
    }

    /**
     * Unix time of each site's last successful update run (absent: never), in one query.
     *
     * @param  list<int>  $siteIds
     * @return array<int, int>
     */
    private function lastSuccess(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $last = [];
        $rows = Run::query()->toBase()->whereIn('site_id', $siteIds)->where('type', RunType::Update->value)
            ->where('status', RunStatus::Succeeded->value)->groupBy('site_id')
            ->selectRaw('site_id, MAX(finished_at) AS last')->get();
        foreach ($rows as $row) {
            if (is_numeric($row->site_id ?? null) && is_string($row->last ?? null)) {
                $last[(int) $row->site_id] = CarbonImmutable::parse($row->last)->getTimestamp();
            }
        }

        return $last;
    }

    /**
     * @return iterable<Site>
     */
    private function eligibleSites(): iterable
    {
        return Site::query()->where('active', true)->where('auto_update', true)->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function close(ScheduledWindow $entry, ScheduledWindowStatus $status, ?string $reason, array $details, bool $dryRun): void
    {
        $entry->forceFill(['status' => $status, 'reason' => $reason, 'details' => [...$entry->details, ...$details]]);
        if (! $dryRun) {
            $entry->save();
        }
    }

    /**
     * The local date a window occurrence opens on: its identity.
     */
    private function dateOf(CarbonImmutable $opens): string
    {
        return $opens->setTimezone($this->windows->timezone())->format('Y-m-d');
    }

    /**
     * A timestamptz query binding in UTC with its offset: never read in the session's time zone.
     */
    private static function bind(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:sP');
    }
}
