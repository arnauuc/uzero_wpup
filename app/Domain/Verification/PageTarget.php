<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * A page of the site to verify: its path (relative to the site's URL) and the
 * kind of page it is (home, product, checkout…).
 */
final readonly class PageTarget
{
    public function __construct(public string $path, public string $profile) {}
}
