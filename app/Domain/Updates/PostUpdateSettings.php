<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use InvalidArgumentException;

/**
 * Settings of the post-update step (UZEROWPUP-55), from wpup.updates.post_update.
 */
final readonly class PostUpdateSettings
{
    /**
     * @param  array<string, list<string>>  $pageCaches  plugin slug => WP-CLI arguments that purge its page cache
     *
     * @throws InvalidArgumentException when the step timeout cannot cover its own commands
     */
    public function __construct(
        public int $stepTimeoutSeconds,
        public int $updateDbTimeoutSeconds,
        public int $flushTimeoutSeconds,
        public array $pageCaches,
    ) {
        // Worst case: both database updates, and the two generic flushes plus every page cache.
        $needed = 2 * $updateDbTimeoutSeconds + (2 + count($pageCaches)) * $flushTimeoutSeconds;
        if ($stepTimeoutSeconds < $needed) {
            throw new InvalidArgumentException("wpup.updates.post_update.step_timeout_seconds ({$stepTimeoutSeconds} s) ha de cobrir els seus comandaments (mínim {$needed} s).");
        }
    }
}
