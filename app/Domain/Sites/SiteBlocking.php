<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\PrerequisiteCheck;
use App\Domain\Inventory\SitePrerequisites;
use App\Domain\Notifications\SiteBlockNotifier;
use App\Enums\AuditResult;
use App\Enums\PrerequisiteName;
use App\Enums\SiteBlockSource;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Applies the outcome of the prerequisites to the site (UZEROWPUP-34): a failed
 * check blocks it (reason, time and source on the sites row, where inventory,
 * plan, report and summaries read it), and when everything passes again the
 * tool lifts its own block. A block set by an operator is never touched.
 *
 * The decision is taken on the row re-read with a lock inside a transaction,
 * together with its audit entry (`site.blocked`, `site.unblocked`): an
 * operator's block made during the inventory is not trampled, and a failed
 * audit leaves the site as it was so a retry audits. The notification goes out
 * after the commit. Only a change is audited and notified: reasons are stable
 * texts, so the same problem on the next inventory changes nothing.
 *
 * A dry run changes nothing. It has no HTTP probe, so the PHP checks cannot
 * decide: the prediction uses the other checks only and never promises a
 * release of a block that depends on PHP.
 */
final readonly class SiteBlocking
{
    public const string REASON_SEPARATOR = '; ';

    /** Checks that need the web's PHP, which a dry run (no probe) cannot verify. */
    private const array PHP_CHECKS = [PrerequisiteName::PhpMinimum, PrerequisiteName::PhpVerified];

    /** Start of every reason that does not come from a PHP check. */
    private const array NON_PHP_REASONS = [
        SitePrerequisites::REASON_SPACE_SHORT,
        SitePrerequisites::REASON_SPACE_UNKNOWN,
        SitePrerequisites::REASON_WRITE_DENIED,
        SitePrerequisites::REASON_WRITE_UNKNOWN,
        SitePrerequisites::REASON_WP_CLI,
        SitePrerequisites::REASON_INCOMPLETE,
    ];

    public function __construct(private AuditLogger $audit, private SiteBlockNotifier $notifier) {}

    /**
     * @param  list<PrerequisiteCheck>  $checks  must hold one check per prerequisite: anything else
     *                                           counts as a failure ("prerequisits incomplets")
     */
    public function apply(Site $site, array $checks, AuditActor $actor, bool $dryRun): BlockResult
    {
        $present = array_unique(array_map(static fn (PrerequisiteCheck $c): string => $c->name->value, $checks));
        if (count($present) !== count(PrerequisiteName::cases()) || count($checks) !== count(PrerequisiteName::cases())) {
            $checks[] = new PrerequisiteCheck(PrerequisiteName::WpCli, false, SitePrerequisites::REASON_INCOMPLETE);
        }

        return $this->decide($site, $checks, $actor, $dryRun, false);
    }

    /**
     * Blocks the site because WP-CLI does not work on it. Never lifts a block.
     */
    public function blockForWpCli(Site $site, AuditActor $actor): BlockResult
    {
        return $this->decide($site, [new PrerequisiteCheck(PrerequisiteName::WpCli, false, SitePrerequisites::REASON_WP_CLI)], $actor, false, true);
    }

    /**
     * @param  list<PrerequisiteCheck>  $checks
     */
    private function decide(Site $site, array $checks, AuditActor $actor, bool $dryRun, bool $onlyBlock): BlockResult
    {
        /** @var Closure|null $notify */
        $notify = null;

        $result = DB::transaction(function () use ($site, $checks, $actor, $dryRun, $onlyBlock, &$notify): BlockResult {
            $fresh = Site::query()->lockForUpdate()->findOrFail($site->id);

            // A block with a reason but no source predates the column: it was set by hand.
            if ($fresh->blocked_by === SiteBlockSource::Operator || ($fresh->blocked_by === null && $fresh->blocked_reason !== null)) {
                return new BlockResult(BlockOutcome::Unchanged, $fresh->blocked_reason);
            }

            $reasons = [];
            foreach ($checks as $check) {
                if (! $check->passed && ! ($dryRun && in_array($check->name, self::PHP_CHECKS, true))) {
                    $reasons[] = $check->reason ?? "prerequisit {$check->name->value} no complert";
                }
            }
            $reason = $reasons === [] ? null : implode(self::REASON_SEPARATOR, array_unique($reasons));
            $ours = $fresh->blocked_by === SiteBlockSource::Prerequisites;

            if ($reason === null) {
                if (! $ours || $onlyBlock) {
                    return new BlockResult(BlockOutcome::Unchanged, $fresh->blocked_reason);
                }
                if ($dryRun) {
                    return $this->dependsOnPhp($fresh->blocked_reason)
                        ? new BlockResult(BlockOutcome::Unchanged, $fresh->blocked_reason)
                        : new BlockResult(BlockOutcome::WouldUnblock, null);
                }

                $previous = $fresh->blocked_reason;
                $fresh->forceFill(['blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null])->save();
                $this->audit->record($actor, 'site.unblocked', AuditResult::Success, 'site', $fresh->id, ['previous_reason' => $previous, 'source' => SiteBlockSource::Prerequisites->value]);
                $notify = fn () => $this->notifier->siteUnblocked($fresh);

                return new BlockResult(BlockOutcome::Unblocked, null);
            }

            if ($ours && $fresh->blocked_reason === $reason) {
                return new BlockResult(BlockOutcome::Unchanged, $reason);
            }
            if ($dryRun) {
                return new BlockResult(BlockOutcome::WouldBlock, $reason);
            }

            $fresh->forceFill([
                'blocked_reason' => $reason,
                'blocked_at' => $ours ? $fresh->blocked_at : CarbonImmutable::now(),
                'blocked_by' => SiteBlockSource::Prerequisites,
            ])->save();
            $this->audit->record($actor, 'site.blocked', AuditResult::Success, 'site', $fresh->id, ['reason' => $reason, 'source' => SiteBlockSource::Prerequisites->value]);
            $notify = fn () => $this->notifier->siteBlocked($fresh, $reason);

            return new BlockResult(BlockOutcome::Blocked, $reason);
        });

        $site->refresh();
        if ($notify !== null) {
            $notify();
        }

        return $result;
    }

    /**
     * Whether a stored block comes (at least partly) from a PHP check.
     */
    private function dependsOnPhp(?string $reason): bool
    {
        foreach (explode(self::REASON_SEPARATOR, (string) $reason) as $part) {
            $known = false;
            foreach (self::NON_PHP_REASONS as $prefix) {
                $known = $known || str_starts_with($part, $prefix);
            }
            if (! $known) {
                return true;
            }
        }

        return false;
    }
}
