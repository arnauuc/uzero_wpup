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
     * @param  int  $overheadSeconds  budget for what is not a counted command: connecting, listing
     *                                the components and the driver's own work around each command
     * @param  array<string, PageCacheCommand>  $pageCaches  plugin slug => its page cache purge
     *
     * @throws InvalidArgumentException when a value is not positive or the step timeout cannot
     *                                  cover the step's work
     */
    public function __construct(
        public int $stepTimeoutSeconds,
        public int $updateDbTimeoutSeconds,
        public int $commandTimeoutSeconds,
        public int $overheadSeconds,
        public array $pageCaches,
    ) {
        foreach (['step_timeout_seconds' => $stepTimeoutSeconds, 'update_db_timeout_seconds' => $updateDbTimeoutSeconds, 'command_timeout_seconds' => $commandTimeoutSeconds, 'overhead_seconds' => $overheadSeconds] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("wpup.updates.post_update.{$name} ha de ser positiu.");
            }
        }
        // Worst case: both database updates, the WooCommerce checks, both generic flushes, and for
        // every page cache its has-command check and its purge; plus the overhead.
        $needed = 2 * $updateDbTimeoutSeconds + (4 + 2 * count($pageCaches)) * $commandTimeoutSeconds + $overheadSeconds;
        if ($stepTimeoutSeconds < $needed) {
            throw new InvalidArgumentException("wpup.updates.post_update.step_timeout_seconds ({$stepTimeoutSeconds} s) ha de cobrir el pitjor cas del pas (mínim {$needed} s).");
        }
    }
}
