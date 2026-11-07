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
     * @param  list<string>  $blockedRequests  blocked on purpose by the verifier (checkout): not errors
     */
    public function __construct(
        public string $path,
        public string $profile,
        public string $viewport,
        public ?int $httpStatus,
        public ?string $screenshot,
        public array $consoleErrors,
        public array $resourceErrors,
        public array $blockedRequests,
        public int $navigationMs,
        public ?string $error,
    ) {}
}
