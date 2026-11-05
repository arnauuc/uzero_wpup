<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Settings of SiteLocalTarget (config wpup.backups.site_local).
 */
final readonly class SiteLocalSettings
{
    /**
     * @param  string  $root  WorkPath of the store inside the work directory
     * @param  string  $stagingDir  private directory of the tool server for temporary copies
     * @param  int  $staleAfterSeconds  age after which purgeStale() removes temporary files
     */
    public function __construct(
        public string $root,
        public int $copyChunkBytes,
        public string $stagingDir,
        public int $canaryTimeoutSeconds,
        public bool $verifyTls,
        public int $staleAfterSeconds,
    ) {}

    public function withRoot(string $root): self
    {
        return new self($root, $this->copyChunkBytes, $this->stagingDir, $this->canaryTimeoutSeconds, $this->verifyTls, $this->staleAfterSeconds);
    }
}
