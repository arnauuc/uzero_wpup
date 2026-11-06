<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use Closure;

/**
 * The artifact of a pending update for a run: the one the run already holds
 * (verified), or else resolved from the site and downloaded. Looking it up
 * first means a resumed step does not need the site to still offer the
 * update. This is what the analysis (UZEROWPUP-50) and the install
 * (UZEROWPUP-54) call; both verify() the artifact again right before use.
 */
final readonly class PackageArtifacts
{
    public function __construct(
        private ArtifactStore $store,
        private ArtifactFetcher $fetcher,
        private PackageUrlResolver $resolver,
    ) {}

    /**
     * @param  SiteDriver|Closure(): SiteDriver  $driver  a closure is only called when the artifact is not stored yet
     *
     * @throws ArtifactException
     * @throws ConnectionFailed
     */
    public function obtain(int $runId, SiteDriver|Closure $driver, PendingUpdate $update): Artifact
    {
        $existing = $this->store->find($runId, PackageUrlResolver::componentType($update), $update->slug, $update->newVersion);
        if ($existing !== null) {
            return $this->store->verify($existing);
        }

        return $this->fetcher->fetch($runId, $this->resolver->resolve($driver instanceof Closure ? $driver() : $driver, $update));
    }
}
