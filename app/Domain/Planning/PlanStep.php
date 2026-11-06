<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\ChangeResult;
use App\Enums\PhpVersionSource;
use App\Models\Run;
use App\Models\SiteSnapshot;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Second step of a plan run: plans from the snapshot the inventory step just
 * took. It touches neither the site nor the tool's state; it only records, in
 * the run's own run_changes, each skipped component with its reason
 * (UZEROWPUP-48).
 */
final readonly class PlanStep implements ReadOnlyStep
{
    public const string NAME = 'plan';

    /** Size of the string columns of run_changes. */
    private const int COLUMN_LENGTH = 255;

    /**
     * @param  Closure(Run, ?string): ChangeAnalyzer|null  $analyzerFor  static analysis of the new
     *                                                                   packages (UZEROWPUP-50); given
     *                                                                   the run and the PHP of the web, null if not verified
     */
    public function __construct(private UpdatePlanner $planner, private int $timeoutSeconds, private int $verifiedPhpMaxAgeSeconds, private ?Closure $analyzerFor = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Calcularà què s'actualitzaria i què s'ometria al lloc ".($context->run->site->name ?? '(desconegut)').', sense tocar-lo.'
            .($this->analyzerFor === null ? '' : " Descarregarà els paquets nous al servidor de l'eina i els analitzarà amb PHPCompatibility.");
    }

    public function run(StepContext $context): StepOutcome
    {
        $site = $context->run->site;
        $snapshotId = $context->resultOf(InventoryStep::NAME)['snapshot_id'] ?? null;
        $snapshot = is_int($snapshotId) ? SiteSnapshot::query()->find($snapshotId) : null;
        if ($site === null || $snapshot === null) {
            return StepOutcome::failed('No hi ha cap inventari fresc per planificar.');
        }

        // The plan never probes the web, so the PHP may be the CLI's. The latest probe
        // verified PHP stands in for it only if it is recent and the CLI agrees on the minor.
        $php = $snapshot->php_version;
        $source = $snapshot->php_source;
        if ($source === PhpVersionSource::Cli) {
            $verified = SiteSnapshot::latestWebVerifiedPhp($site, $this->verifiedPhpMaxAgeSeconds);
            if ($verified !== null && PhpRuntime::isVersion($php) && PhpRuntime::isVersion($verified->php_version)
                && PhpRuntime::minorOf($verified->php_version) === PhpRuntime::minorOf($php)) {
                $php = $verified->php_version;
                $source = $verified->php_source;
            }
        }

        $analyzer = $this->analyzerFor === null ? null : ($this->analyzerFor)($context->run, $source === PhpVersionSource::Cli ? null : $php);
        $plan = $this->planner->plan($snapshot, $site->refresh(), $site->profile, $php, $source, $analyzer);
        $this->recordSkips($context->run, $plan);

        return StepOutcome::succeeded($plan->summary(), '', $plan->toArray());
    }

    /**
     * One run_changes row per skipped component. version_after is the target that
     * was not applied. A retried step replaces only its own rows (step = plan).
     * Slug and versions come from the site: cut to the column size, never failing.
     */
    private function recordSkips(Run $run, UpdatePlan $plan): void
    {
        DB::transaction(static function () use ($run, $plan): void {
            $run->changes()->where('step', self::NAME)->delete();
            foreach ($plan->changes as $change) {
                if ($change->action !== PlanAction::Skip) {
                    continue;
                }
                $run->changes()->create([
                    'step' => self::NAME,
                    'component_type' => $change->type,
                    'slug' => self::column($change->slug),
                    'version_before' => self::column($change->currentVersion),
                    'version_after' => self::column($change->targetVersion),
                    'result' => ChangeResult::Skipped,
                    'reason' => $change->reason,
                    'reason_detail' => $change->detail,
                    'is_major' => VersionChange::isMajor($change->type, $change->currentVersion, $change->targetVersion),
                ]);
            }
        });
    }

    /** Fits a site-provided value in a varchar(255) column (valid UTF-8, at most 255 bytes). */
    private static function column(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::COLUMN_LENGTH, 'UTF-8');
    }
}
