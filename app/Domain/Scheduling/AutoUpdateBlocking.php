<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\ScheduledWindowStatus;
use App\Models\Run;
use App\Models\ScheduledWindow;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-0006 §3: a site whose scheduled update failed in `blockAfter`
 * consecutive windows is blocked for automatic updates, with a warning, until
 * an operator reviews it (`site:edit --auto-update` lifts it). Only the
 * windows in which a run finished count: a skipped or deferred window does
 * not break the streak, a successful one resets it, and so does an
 * operator's review (`site:edit --auto-update`: only the windows settled
 * after it count). Manual updates are not blocked (blocked_reason is for that).
 *
 * The warning goes on the run that made it (result_summary.warnings), in
 * audit_log (`site.auto_update_blocked`) and in the log; the notification
 * channels come in F5.
 */
final readonly class AutoUpdateBlocking
{
    public function __construct(private AuditLogger $audit, private int $blockAfter) {}

    /**
     * Called once the window of a failed run is settled. Returns the reason when it blocked the site.
     */
    public function afterFailure(Site $site, Run $run, AuditActor $actor): ?string
    {
        $recent = ScheduledWindow::query()->where('site_id', $site->id)
            ->whereIn('status', [ScheduledWindowStatus::Succeeded->value, ScheduledWindowStatus::Failed->value])
            ->when($site->auto_update_unblocked_at !== null, static fn ($q) => $q->where('updated_at', '>', $site->auto_update_unblocked_at?->format('Y-m-d H:i:s.uP')))
            ->orderByDesc('opens_at')->limit($this->blockAfter)->get();
        if ($recent->count() < $this->blockAfter || $recent->contains(static fn (ScheduledWindow $w): bool => $w->status !== ScheduledWindowStatus::Failed)) {
            return null;
        }

        $reason = sprintf(
            "L'actualització programada ha fallat %d finestres seguides (execucions %s): no s'actualitza automàticament fins que un operador el revisi i ho reactivi amb `wpup site:edit %s --auto-update`.",
            $this->blockAfter,
            implode(', ', $recent->map(static fn (ScheduledWindow $w): string => '#'.($w->run_id ?? '?'))->reverse()->all()),
            $site->name,
        );

        $blocked = DB::transaction(function () use ($site, $run, $actor, $reason, $recent): bool {
            $fresh = Site::query()->lockForUpdate()->findOrFail($site->id);
            if ($fresh->auto_update_blocked_at !== null) {
                return false;
            }
            $fresh->forceFill(['auto_update_blocked_at' => CarbonImmutable::now(), 'auto_update_blocked_reason' => $reason])->save();
            $this->audit->record($actor, 'site.auto_update_blocked', AuditResult::Success, 'site', $fresh->id, [
                'name' => $fresh->name,
                'reason' => $reason,
                'runs' => array_values($recent->pluck('run_id')->all()),
            ]);
            // Appended in place, as the engine does with the interruptions.
            DB::update(
                "UPDATE runs SET result_summary = jsonb_set(COALESCE(result_summary, '{}'::jsonb), '{warnings}', COALESCE(result_summary->'warnings', '[]'::jsonb) || ?::jsonb) WHERE id = ?",
                [json_encode([$reason], JSON_THROW_ON_ERROR), $run->id],
            );

            return true;
        });
        $site->refresh();
        if (! $blocked) {
            return null;
        }
        Log::warning('Site blocked for automatic updates after consecutive failed windows.', ['site' => $site->name, 'run_id' => $run->id]);

        return $reason;
    }
}
