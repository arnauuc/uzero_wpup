<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * What one page did in one viewport. The screenshot path is relative to the
 * run's output directory.
 */
final readonly class PageResult
{
    /**
     * @param  list<string>  $consoleErrors
     * @param  list<ResourceError>  $resourceErrors
     */
    public function __construct(
        public string $path,
        public string $profile,
        public string $viewport,
        public ?int $httpStatus,
        public ?string $screenshot,
        public array $consoleErrors,
        public array $resourceErrors,
        public int $navigationMs,
        public ?string $error,
    ) {}
}
