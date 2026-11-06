<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Enums\ComponentType;
use App\Models\RunArtifact;
use App\Support\PrivateDirectory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Package artifacts on the tool server (UZEROWPUP-49): one private directory
 * per run ("run-<id>", 0700) under wpup.artifacts.dir, files 0600. A file is
 * written to a ".part" temporary of the same directory and renamed into
 * place, so a published artifact is never half-written; its row is only
 * inserted after the rename. verify() rehashes it before every use.
 */
final readonly class ArtifactStore
{
    public const string PARTIAL_SUFFIX = '.part';

    public function __construct(private ArtifactSettings $settings) {}

    public function find(int $runId, ComponentType $type, string $slug, string $version): ?Artifact
    {
        $row = RunArtifact::query()
            ->where(['run_id' => $runId, 'component_type' => $type->value, 'slug' => $slug, 'version' => $version])
            ->first();

        return $row === null ? null : Artifact::fromRecord($row, $this->settings->directory);
    }

    /**
     * A new temporary file in the run's directory, after removing the ones a
     * dead attempt left (a run executes one step at a time).
     *
     * @throws ArtifactException
     */
    public function temporaryFile(int $runId): string
    {
        $dir = $this->runDirectory($runId);
        foreach (glob($dir.'/*'.self::PARTIAL_SUFFIX) ?: [] as $stale) {
            @unlink($stale);
        }
        $path = $dir.'/'.bin2hex(random_bytes(8)).self::PARTIAL_SUFFIX;
        if (! touch($path) || ! chmod($path, 0600)) {
            throw ArtifactException::storage("no s'ha pogut crear un fitxer temporal.");
        }

        return $path;
    }

    /**
     * Publishes a downloaded temporary file as the run's artifact.
     *
     * @throws ArtifactException
     */
    public function commit(int $runId, PackageSource $source, string $temporary, string $sha256, int $sizeBytes): Artifact
    {
        $relative = "run-{$runId}/{$source->fileName()}";
        if (! rename($temporary, rtrim($this->settings->directory, '/').'/'.$relative)) {
            @unlink($temporary);
            throw ArtifactException::storage("no s'ha pogut desar el paquet de «{$source->slug}».");
        }

        $row = RunArtifact::query()->create([
            'run_id' => $runId,
            'component_type' => $source->type,
            'slug' => $source->slug,
            'version' => $source->version,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'path' => $relative,
            'source_host' => $source->host(),
        ]);

        return Artifact::fromRecord($row, $this->settings->directory);
    }

    /**
     * Rehashes the stored bytes. Call it before every analysis or install.
     *
     * @throws ArtifactIntegrityFailed when it was purged, is missing or does not match
     */
    public function verify(Artifact $artifact): Artifact
    {
        if ($artifact->purged) {
            throw ArtifactIntegrityFailed::purged($artifact);
        }
        if (! is_file($artifact->path) || is_link($artifact->path)) {
            throw ArtifactIntegrityFailed::missing($artifact);
        }
        $hash = hash_file('sha256', $artifact->path);
        if ($hash === false || filesize($artifact->path) !== $artifact->sizeBytes || ! hash_equals($artifact->sha256, $hash)) {
            throw ArtifactIntegrityFailed::mismatch($artifact);
        }

        return $artifact;
    }

    /**
     * Removes the file (and the run's directory once empty) and marks the row
     * purged; the row stays so the run still says what it installed.
     *
     * @throws ArtifactException
     */
    public function purge(RunArtifact $row, CarbonImmutable $now): void
    {
        $path = rtrim($this->settings->directory, '/').'/'.$row->path;
        if (file_exists($path) && ! unlink($path)) {
            throw ArtifactException::storage("no s'ha pogut esborrar «{$row->path}».");
        }
        $row->forceFill(['purged_at' => $now])->save();
        @rmdir(dirname($path));
    }

    /**
     * @throws ArtifactException
     */
    private function runDirectory(int $runId): string
    {
        try {
            PrivateDirectory::ensure($this->settings->directory);

            return PrivateDirectory::ensure(rtrim($this->settings->directory, '/')."/run-{$runId}");
        } catch (RuntimeException $e) {
            throw ArtifactException::storage($e->getMessage());
        }
    }
}
