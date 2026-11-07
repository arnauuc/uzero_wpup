<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * A resource of the page that answered 4xx/5xx (status) or did not load (failure).
 */
final readonly class ResourceError
{
    public function __construct(public string $url, public ?int $status, public ?string $failure) {}
}
