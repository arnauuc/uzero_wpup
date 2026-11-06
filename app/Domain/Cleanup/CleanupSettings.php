<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use InvalidArgumentException;

/**
 * Retention periods of the daily cleanup (ADR-0009 §4, config wpup.cleanup).
 * The ADR values are floors: the configuration may only keep data longer.
 */
final readonly class CleanupSettings
{
    public const int MIN_RUN_OUTPUT_DAYS = 90;

    public const int MIN_RUN_OUTPUT_FAILED_DAYS = 365;

    public const int MIN_SCREENSHOTS_DAYS = 30;

    public const int MIN_SCREENSHOTS_FLAGGED_DAYS = 180;

    public function __construct(
        public int $runOutputDays,
        public int $runOutputFailedDays,
        public int $screenshotsDays,
        public int $screenshotsFlaggedDays,
    ) {
        foreach ([
            'run_output_days' => [$runOutputDays, self::MIN_RUN_OUTPUT_DAYS],
            'run_output_failed_days' => [$runOutputFailedDays, self::MIN_RUN_OUTPUT_FAILED_DAYS],
            'screenshots_days' => [$screenshotsDays, self::MIN_SCREENSHOTS_DAYS],
            'screenshots_flagged_days' => [$screenshotsFlaggedDays, self::MIN_SCREENSHOTS_FLAGGED_DAYS],
        ] as $key => [$value, $floor]) {
            if ($value < $floor) {
                throw new InvalidArgumentException("wpup.cleanup.{$key} must be at least {$floor} (ADR-0009).");
            }
        }
    }
}
