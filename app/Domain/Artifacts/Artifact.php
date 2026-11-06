<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Enums\ComponentType;
use App\Models\RunArtifact;

/**
 * A package stored for a run (UZEROWPUP-49). Staging and production install
 * these same bytes; ArtifactStore::verify() checks them before every use.
 */
final readonly class Artifact
{
    public function __construct(
        public int $id,
        public int $runId,
        public ComponentType $type,
        public string $slug,
        public string $version,
        public string $sha256,
        public int $sizeBytes,
        /** Absolute path on the tool server. */
        public string $path,
        public ?string $sourceHost,
        public bool $purged = false,
    ) {}

    public static function fromRecord(RunArtifact $row, string $root): self
    {
        return new self(
            $row->id,
            $row->run_id,
            $row->component_type,
            $row->slug,
            $row->version,
            $row->sha256,
            $row->size_bytes,
            rtrim($root, '/').'/'.$row->path,
            $row->source_host,
            $row->purged_at !== null,
        );
    }
}
