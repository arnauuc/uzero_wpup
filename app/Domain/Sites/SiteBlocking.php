<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\PrerequisiteCheck;
use App\Domain\Notifications\SiteBlockNotifier;
use App\Enums\AuditResult;
use App\Enums\SiteBlockSource;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Applies the outcome of the prerequisites to the site (UZEROWPUP-34): a failed
 * check blocks it (reason, time and source on the sites row, where inventory,
 * plan, report and summaries read it), and when everything passes again the
 * tool lifts its own block. A block set by an operator is never touched.
 *
 * Only a change is audited (`site.blocked`, `site.unblocked`) and notified. A
 * dry run changes nothing and reports what would happen.
 */
final readonly class SiteBlocking
{
    public const string REASON_SEPARATOR = '; ';

    public function __construct(private AuditLogger $audit, private SiteBlockNotifier $notifier) {}

    /**
     * @param  list<PrerequisiteCheck>  $checks
     */
    public function apply(Site $site, array $checks, AuditActor $actor, bool $dryRun): BlockResult
    {
        // A block with a reason but no source predates the column: it was set by hand.
        if ($site->blocked_by === SiteBlockSource::Operator || ($site->blocked_by === null && $site->blocked_reason !== null)) {
            return new BlockResult(BlockOutcome::Unchanged, $site->blocked_reason);
        }

        $reasons = [];
        foreach ($checks as $check) {
            if (! $check->passed) {
                $reasons[] = $check->reason ?? "prerequisit {$check->name->value} no complert";
            }
        }
        $reason = $reasons === [] ? null : implode(self::REASON_SEPARATOR, $reasons);

        if ($reason === null) {
            return $site->blocked_by === SiteBlockSource::Prerequisites
                ? $this->unblock($site, $actor, $dryRun)
                : new BlockResult(BlockOutcome::Unchanged, null);
        }

        if ($site->blocked_by === SiteBlockSource::Prerequisites && $site->blocked_reason === $reason) {
            return new BlockResult(BlockOutcome::Unchanged, $reason);
        }

        if ($dryRun) {
            return new BlockResult(BlockOutcome::WouldBlock, $reason);
        }

        $site->forceFill([
            'blocked_reason' => $reason,
            'blocked_at' => $site->blocked_by === SiteBlockSource::Prerequisites ? $site->blocked_at : CarbonImmutable::now(),
            'blocked_by' => SiteBlockSource::Prerequisites,
        ])->save();
        $this->audit->record($actor, 'site.blocked', AuditResult::Success, 'site', $site->id, ['reason' => $reason, 'source' => SiteBlockSource::Prerequisites->value]);
        $this->notifier->siteBlocked($site, $reason);

        return new BlockResult(BlockOutcome::Blocked, $reason);
    }

    private function unblock(Site $site, AuditActor $actor, bool $dryRun): BlockResult
    {
        if ($dryRun) {
            return new BlockResult(BlockOutcome::WouldUnblock, null);
        }

        $previous = $site->blocked_reason;
        $site->forceFill(['blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null])->save();
        $this->audit->record($actor, 'site.unblocked', AuditResult::Success, 'site', $site->id, ['previous_reason' => $previous, 'source' => SiteBlockSource::Prerequisites->value]);
        $this->notifier->siteUnblocked($site);

        return new BlockResult(BlockOutcome::Unblocked, null);
    }
}
