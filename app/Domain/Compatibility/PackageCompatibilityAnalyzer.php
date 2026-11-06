<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Planning\ChangeAnalysis;
use App\Domain\Planning\ChangeAnalyzer;
use App\Domain\Planning\PlannedChange;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Enums\SkipReason;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * The planner's static analysis of one plan run (UZEROWPUP-50): obtains the
 * artifact of each plugin or theme update and runs StaticCompatibility with the
 * site's PHP minor. Errors skip the update; warnings are kept for the report.
 * If the analysis cannot run (the download is blocked, phpcs times out or
 * crashes, the site's PHP is unknown) the update is skipped: a package that was
 * not analysed is not taken as compatible. The core and the translations are not
 * analysed here (the core is covered by its declared requirements; translations
 * carry no code). Only the web's PHP counts: without a verified one nothing is
 * analysed. Each scan has its own timeout and the step has a budget: when what is
 * left cannot cover another download and scan, the rest are skipped.
 */
final class PackageCompatibilityAnalyzer implements ChangeAnalyzer
{
    /** Monotonic start of the step's analysis budget (seconds). */
    private readonly float $startedAt;

    /**
     * @param  string|null  $phpVersion  the PHP that serves the site's web; null when it was not verified
     *                                   (the CLI's PHP may differ: nothing is analysed against it)
     * @param  Closure(): SiteDriver  $driverFor  lazy: the site is only reached for an update that needs its package URL
     */
    public function __construct(
        private readonly int $runId,
        private readonly ?string $phpVersion,
        private readonly Closure $driverFor,
        private readonly PackageArtifacts $artifacts,
        private readonly StaticCompatibility $compatibility,
        private readonly CompatSettings $settings,
        private readonly SecretRedactor $redactor,
    ) {
        $this->startedAt = hrtime(true) / 1e9;
    }

    public function analyze(PlannedChange $change, ?PendingUpdate $update): ?ChangeAnalysis
    {
        if (! in_array($change->type, [ComponentType::Plugin, ComponentType::Woocommerce, ComponentType::Theme], true)) {
            return null;
        }
        if ($update === null) {
            return $this->unable("no es troba l'actualització a l'inventari.");
        }
        if ($this->phpVersion === null) {
            return $this->unable("el PHP del web del lloc no s'ha pogut verificar.");
        }
        if (! PhpRuntime::isVersion($this->phpVersion)) {
            return $this->unable("el PHP del lloc («{$this->phpVersion}») no es pot llegir.");
        }
        if ($this->remainingSeconds() < $this->settings->minRemainingSeconds) {
            return $this->unable("temps d'anàlisi esgotat.");
        }
        $minor = PhpRuntime::minorOf($this->phpVersion);

        try {
            $artifact = $this->artifacts->obtain($this->runId, $this->driverFor, $update);
            $report = $this->compatibility->analyse($artifact, $minor);
        } catch (ArtifactException|DriverException|StaticAnalysisFailed $e) {
            $reason = $this->redactor->redact($e->getMessage());
            Log::warning('Static compatibility analysis not completed.', ['slug' => $change->slug, 'version' => $change->targetVersion, 'reason' => $reason]);

            return $this->unable($reason);
        }

        $warnings = array_map(
            static fn (CompatFinding $f): string => "{$f->location()} {$f->message}",
            array_slice($report->findingsOf(false), 0, $this->settings->warningsMax),
        );
        if ($report->errors === 0) {
            return new ChangeAnalysis(warnings: $warnings);
        }

        $where = implode(', ', array_map(
            static fn (CompatFinding $f): string => $f->location(),
            array_slice($report->findingsOf(true), 0, $this->settings->detailMaxFindings),
        ));

        return new ChangeAnalysis(
            SkipReason::StaticAnalysis,
            "PHPCompatibility: {$report->errors} errors per a PHP {$minor}".($where === '' ? '' : " ({$where})").'.',
            $warnings,
        );
    }

    /** What is left of the step's analysis budget (config static_analysis.step_budget_seconds). */
    private function remainingSeconds(): float
    {
        return $this->settings->stepBudgetSeconds - (hrtime(true) / 1e9 - $this->startedAt);
    }

    private function unable(string $reason): ChangeAnalysis
    {
        return new ChangeAnalysis(SkipReason::StaticAnalysis, "No s'ha pogut analitzar el paquet, s'omet per precaució: {$reason}");
    }
}
