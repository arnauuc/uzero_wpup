<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditActor;
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
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The continuous scheduler of ADR-0006 (UZEROWPUP-129), one tick a minute:
 *
 * 1. Settles the windows whose run finished (succeeded, failed or rolled back).
 *    A window is never tried again: a site that failed waits for its next one.
 * 2. Closes the queued windows that closed before their turn: deferred to the
 *    site's next window, with priority.
 * 3. Queues every active site with automatic updates whose window is open and
 *    that has no record for that occurrence of it yet (scheduled_windows: one
 *    per site and opening, so a site is handled once per window).
 * 4. Starts the next site, only when no update is running or waiting to start
 *    (one update at a time, ADR-0006 §2). Queued manual runs go first: they
 *    already wait in the global queue, and the scheduler never adds a second
 *    run behind them. An admin's --now skips the queue altogether.
 *    Order: a pending security update (known vulnerability or core x.y.z),
 *    then the sites deferred from their previous window, then the longest
 *    without a successful update, then by name. Sites with nothing applicable
 *    (UpdateOutlooks, offline) are skipped without spending any time; a site
 *    whose estimated duration (UpdateDurationEstimator) does not fit in what is
 *    left of its window waits for its next one, with priority.
 *
 * The runs are queued as `sistema (scheduler)` with the site's strategy and
 * the version policy of ADR-0005: never --allow-major nor --skip-quarantine.
 * A dry run decides the same and writes nothing.
 */
final readonly class UpdateScheduler
{
    public function __construct(
        private MaintenanceWindowGate $windows,
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
                $window = $this->windows->windowOf($site);
            } catch (InvalidArgumentException $e) {
                Log::warning('A site with automatic updates has an invalid maintenance window.', ['site' => $site->name, 'error' => $e->getMessage()]);

                continue;
            }
            $occurrence = $window->occurrence($now);
            if ($occurrence === null) {
                continue;
            }
            [$opens, $closes] = $occurrence;
            if (ScheduledWindow::query()->where('site_id', $site->id)->where('opens_at', self::bind($opens))->exists()) {
                continue;
            }

            $previous = ScheduledWindow::query()->where('site_id', $site->id)->orderByDesc('opens_at')->first();
            $entry = new ScheduledWindow([
                'site_id' => $site->id,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'status' => ScheduledWindowStatus::Pending,
                'priority' => $previous?->status === ScheduledWindowStatus::Deferred,
                'details' => ['window' => $window->label()],
            ]);
            $entry->setRelation('site', $site);
            if (! $dryRun) {
                $entry->save();
            }
            $queued[] = $entry;
            $decisions[] = new SchedulerDecision(SchedulerAction::Enqueued, $site->name, $window->label().' fins a les '.$closes->format('H:i').($entry->priority ? ', amb prioritat' : ''));
        }

        return $queued;
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
            $site = $entry->site;
            if ($site === null) {
                continue;
            }
            $site->refresh();
            if (! $site->active || ! $site->auto_update) {
                $reason = ! $site->active ? 'El lloc està desactivat.' : "S'ha desactivat l'actualització automàtica del lloc.";
                $this->close($entry, ScheduledWindowStatus::Cancelled, $reason, [], $dryRun);
                $decisions[] = new SchedulerDecision(SchedulerAction::Cancelled, $site->name, $reason);

                continue;
            }
            $skip = $this->notAutomatic($site);
            $outlook = $skip === null ? $this->outlooks->of($site, $now) : null;
            if ($skip === null && $outlook?->nothingToDo === true) {
                $skip = 'Res a aplicar: '.$outlook->note;
            }
            if ($skip !== null) {
                $this->close($entry, ScheduledWindowStatus::Skipped, $skip, $outlook === null ? [] : ['outlook' => $outlook->toArray()], $dryRun);
                $decisions[] = new SchedulerDecision(SchedulerAction::Skipped, $site->name, $skip);

                continue;
            }
            $candidates[] = ['entry' => $entry, 'site' => $site, 'outlook' => $outlook ?? UpdateOutlook::unknown(), 'last' => $this->lastSuccess($site)];
        }

        usort($candidates, static fn (array $a, array $b): int => [! $a['outlook']->security, ! $a['entry']->priority, $a['last'] ?? 0, $a['site']->name]
            <=> [! $b['outlook']->security, ! $b['entry']->priority, $b['last'] ?? 0, $b['site']->name]);

        foreach ($candidates as ['entry' => $entry, 'site' => $site, 'outlook' => $outlook]) {
            $estimate = $this->estimator->estimateSeconds($site, $site->strategy);
            $needed = max($estimate, $this->minimumMinutes * 60);
            $left = $entry->closes_at->getTimestamp() - $now->getTimestamp();
            $details = ['estimate_seconds' => $estimate, 'outlook' => $outlook->toArray()];
            if ($left < $needed) {
                $reason = sprintf('No hi cap: cal uns %d min i en queden %d de la finestra.', intdiv($needed + 59, 60), intdiv(max(0, $left), 60));
                $this->close($entry, ScheduledWindowStatus::Deferred, $reason, $details, $dryRun);
                $decisions[] = new SchedulerDecision(SchedulerAction::Deferred, $site->name, $reason);

                continue;
            }

            $runId = null;
            if (! $dryRun) {
                [$run] = $this->launcher->launch(RunType::Update, [$site], AuditActor::system($this->actor), ['scheduled' => true, 'scheduled_window_id' => $entry->id], $site->strategy);
                $runId = $run->id;
                $entry->forceFill(['status' => ScheduledWindowStatus::Launched, 'run_id' => $runId, 'details' => [...$entry->details, ...$details]])->save();
            }
            $decisions[] = new SchedulerDecision(SchedulerAction::Launched, $site->name, sprintf('estimació: %d min%s', intdiv($estimate + 59, 60), $outlook->security ? '; actualització de seguretat pendent' : ''), $runId);

            return;
        }
    }

    /**
     * Why nothing may start now (an update is running, or one is waiting and may start); null when idle.
     */
    private function busy(CarbonImmutable $now): ?string
    {
        $active = Run::query()->where('type', RunType::Update->value)
            ->whereIn('status', [RunStatus::Running->value, RunStatus::RollingBack->value, RunStatus::Interrupted->value])
            ->orderBy('id')->value('id');
        if (is_int($active)) {
            return "L'actualització #{$active} està en curs.";
        }

        $queued = Run::query()->with('site')->where('type', RunType::Update->value)->where('status', RunStatus::Queued->value)->orderBy('id')->get();
        foreach ($queued as $run) {
            try {
                $startable = ($run->flags['now'] ?? false) === true || $run->site === null || $this->windows->nextStart($run->site, $now) === null;
            } catch (InvalidArgumentException) {
                // Its engine fails it as soon as it picks it up.
                $startable = true;
            }
            if ($startable) {
                return "L'actualització #{$run->id} espera torn a la cua.";
            }
        }

        return null;
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
     * Unix time of the site's last successful update run; null when it never had one.
     */
    private function lastSuccess(Site $site): ?int
    {
        $last = Run::query()->where('site_id', $site->id)->where('type', RunType::Update->value)
            ->where('status', RunStatus::Succeeded->value)->max('finished_at');

        return is_string($last) ? CarbonImmutable::parse($last)->getTimestamp() : null;
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
     * A timestamptz query binding with its offset: never read in the session's time zone.
     */
    private static function bind(CarbonImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:sP');
    }
}
