<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * The tool backups to keep and to delete, oldest first.
 */
final readonly class ToolRetentionPlan
{
    /**
     * @param  list<string>  $keep
     * @param  list<string>  $delete
     */
    public function __construct(
        public array $keep,
        public array $delete,
    ) {}
}
