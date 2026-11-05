<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

final readonly class DropboxSetupResult
{
    public function __construct(
        public int $targetId,
        public bool $created,
        public ?string $previousRoot,
    ) {}
}
