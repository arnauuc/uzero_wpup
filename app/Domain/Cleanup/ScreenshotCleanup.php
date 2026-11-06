<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use Carbon\CarbonImmutable;

/**
 * Extension point for the screenshots of the verifier (F4); there are none yet,
 * so it does nothing. The F4 task that stores them must implement run() with
 * the ADR-0009 rules (periods in CleanupSettings, config wpup.cleanup):
 *
 *  - delete after `screenshotsDays` (30) when the run had no differences or
 *    errors, after `screenshotsFlaggedDays` (180) when it had a warning or
 *    an error;
 *  - never delete those of each site's last run, whatever their age;
 *  - leave the verification rows (without images) untouched: they stay forever;
 *  - write one `cleanup.screenshots` summary to audit_log when something was
 *    purged or failed, and nothing on a dry run.
 */
final readonly class ScreenshotCleanup implements CleanupTask
{
    public const string TASK = 'screenshots';

    public function __construct(private CleanupSettings $settings) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        return new CleanupResult(self::TASK, 0, 0, $dryRun, "Encara no hi ha captures (F4): res a netejar ({$this->settings->screenshotsDays}/{$this->settings->screenshotsFlaggedDays} dies).");
    }
}
