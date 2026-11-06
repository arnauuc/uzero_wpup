<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

/**
 * A stored artifact is missing or its bytes no longer match the SHA-256
 * recorded when it was downloaded: it must not be analysed nor installed.
 */
final class ArtifactIntegrityFailed extends ArtifactException
{
    public static function missing(Artifact $artifact): self
    {
        return new self("L'artefacte de «{$artifact->slug}» {$artifact->version} (execució {$artifact->runId}) no hi és: no es fa servir.");
    }

    public static function mismatch(Artifact $artifact): self
    {
        return new self("L'artefacte de «{$artifact->slug}» {$artifact->version} (execució {$artifact->runId}) no coincideix amb el SHA-256 desat: no es fa servir.");
    }

    public static function purged(Artifact $artifact): self
    {
        return new self("L'artefacte de «{$artifact->slug}» {$artifact->version} (execució {$artifact->runId}) ja s'ha purgat per retenció.");
    }
}
