<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Verification\VerificationResult;
use App\Domain\Verification\VerifierClient;
use App\Domain\Verification\VerifierFailed;
use App\Domain\Verification\VerifierSettings;
use App\Enums\AuditResult;
use App\Enums\ChangeResult;
use App\Enums\RunStatus;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Screenshots of the verifier (ADR-0009), in wpup.verifier.work_dir/run-<id>/:
 *
 *  - deleted `screenshotsDays` (30) after the run finished when it went well
 *    and no result.json of it has a warning; `screenshotsFlaggedDays` (180)
 *    when the run failed or was reverted, a result has a warning or an error,
 *    or a result is missing or unreadable (when in doubt, keep longer);
 *  - those of each site's last run with screenshots, and of unfinished runs,
 *    are never deleted; a directory without a run left counts as flagged and
 *    ages from its last change;
 *  - only the images go: result.json stays;
 *  - one `cleanup.screenshots` summary in audit_log when something was purged
 *    or failed, and nothing on a dry run.
 */
final readonly class ScreenshotCleanup implements CleanupTask
{
    public const string TASK = 'screenshots';

    public function __construct(private CleanupSettings $settings, private VerifierSettings $verifier, private AuditLogger $audit) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        $problem = $this->settings->problem(['screenshots_days', 'screenshots_flagged_days']);
        if ($problem !== null) {
            return new CleanupResult(self::TASK, 0, 0, $dryRun, null, [$problem]);
        }

        $count = 0;
        $bytes = 0;
        $failures = [];
        try {
            foreach ($this->expired($now) as $images) {
                foreach ($images as $image) {
                    $size = (int) filesize($image);
                    if ($dryRun || @unlink($image)) {
                        $count++;
                        $bytes += $size;
                    } else {
                        $failures[] = "No s'ha pogut esborrar una captura.";
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Screenshot cleanup failed: '.$e::class);
            $failures[] = "No s'han pogut netejar les captures.";
        }
        $failures = array_values(array_unique($failures));
        if (! $dryRun && ($count > 0 || $failures !== [])) {
            $this->audit->record($actor, 'cleanup.screenshots', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                'screenshots' => $count,
                'bytes' => $bytes,
                'days' => $this->settings->screenshotsDays,
                'flagged_days' => $this->settings->screenshotsFlaggedDays,
                'failures' => $failures,
            ]);
        }

        return new CleanupResult(self::TASK, $count, $bytes, $dryRun, null, $failures);
    }

    /**
     * The images of every run directory past its retention.
     *
     * @return array<int, list<string>> run id => images
     */
    private function expired(CarbonImmutable $now): array
    {
        $images = [];
        foreach (glob($this->verifier->workDir.'/run-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $found = glob("{$dir}/*/*/*.png") ?: [];
            if (preg_match('/\/run-(\d+)$/D', $dir, $m) === 1 && $found !== []) {
                $images[(int) $m[1]] = $found;
            }
        }
        $runs = Run::query()->whereIn('id', array_keys($images))->get()->keyBy('id');
        $latest = [];
        foreach ($runs as $run) {
            if ($run->site_id !== null) {
                $latest[$run->site_id] = max($latest[$run->site_id] ?? 0, $run->id);
            }
        }

        return array_filter($images, function (array $files, int $id) use ($runs, $latest, $now): bool {
            $run = $runs->get($id);
            $dir = $this->verifier->workDir."/run-{$id}";
            if ($run !== null && ($run->finished_at === null || ($run->site_id !== null && $latest[$run->site_id] === $id))) {
                return false;
            }
            $finished = $run !== null ? $run->finished_at : CarbonImmutable::createFromTimestamp((int) filemtime($dir));
            $days = $run === null || $this->flagged($run, $dir) ? $this->settings->screenshotsFlaggedDays : $this->settings->screenshotsDays;

            return $finished->lessThanOrEqualTo($now->subDays($days));
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function flagged(Run $run, string $dir): bool
    {
        if (in_array($run->status, [RunStatus::Failed, RunStatus::RolledBack], true)
            || $run->changes()->where(static fn ($q) => $q->where('result', ChangeResult::Reverted->value)->orWhereNotNull('reverted_at'))->exists()) {
            return true;
        }
        foreach (glob("{$dir}/*", GLOB_ONLYDIR) ?: [] as $stepDir) {
            if ((glob("{$stepDir}/*/*.png") ?: []) === []) {
                continue;
            }
            try {
                $result = VerificationResult::fromProtocol(json_decode((string) @file_get_contents($stepDir.'/'.VerifierClient::RESULT_FILE), true), $run->id, $stepDir);
            } catch (VerifierFailed) {
                return true;
            }
            if ($result->hasWarnings()) {
                return true;
            }
        }

        return false;
    }
}
