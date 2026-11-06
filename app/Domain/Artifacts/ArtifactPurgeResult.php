<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

/**
 * What a retention purge of artifacts removed (or would remove, on a dry run).
 */
final readonly class ArtifactPurgeResult
{
    /**
     * @param  list<string>  $failures  operator messages, no secrets
     */
    public function __construct(
        public int $count,
        public int $bytes,
        public array $failures,
        public bool $dryRun,
    ) {}
}
