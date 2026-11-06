<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

/**
 * Retention periods of the daily cleanup (config wpup.cleanup). The values
 * below are the ADR-0009 §4 floors, a safety net like the artifacts one: the
 * configuration may only keep data longer. A period under its floor does not
 * break the cleanup: the class that uses it fails (see problem()) and the
 * others run.
 */
final readonly class CleanupSettings
{
    /** ADR-0009 floors, in days. */
    public const int MIN_RUN_OUTPUT_DAYS = 90;

    public const int MIN_RUN_OUTPUT_FAILED_DAYS = 365;

    public const int MIN_SCREENSHOTS_DAYS = 30;

    public const int MIN_SCREENSHOTS_FLAGGED_DAYS = 180;

    public function __construct(
        public int $runOutputDays,
        public int $runOutputFailedDays,
        public int $screenshotsDays,
        public int $screenshotsFlaggedDays,
        public int $chunkSize,
    ) {}

    /**
     * Operator message (Catalan, no secrets) if one of the given periods is
     * below its ADR-0009 floor or the chunk size is not positive; null if fine.
     *
     * @param  list<string>  $keys  run_output_days, run_output_failed_days, screenshots_days, screenshots_flagged_days
     */
    public function problem(array $keys): ?string
    {
        $values = [
            'run_output_days' => [$this->runOutputDays, self::MIN_RUN_OUTPUT_DAYS],
            'run_output_failed_days' => [$this->runOutputFailedDays, self::MIN_RUN_OUTPUT_FAILED_DAYS],
            'screenshots_days' => [$this->screenshotsDays, self::MIN_SCREENSHOTS_DAYS],
            'screenshots_flagged_days' => [$this->screenshotsFlaggedDays, self::MIN_SCREENSHOTS_FLAGGED_DAYS],
        ];
        foreach ($keys as $key) {
            [$value, $floor] = $values[$key];
            if ($value < $floor) {
                return "Configuració invàlida: wpup.cleanup.{$key} ha de ser com a mínim {$floor} dies (ADR-0009).";
            }
        }

        return $this->chunkSize < 1 ? 'Configuració invàlida: wpup.cleanup.chunk_size ha de ser positiu.' : null;
    }
}
