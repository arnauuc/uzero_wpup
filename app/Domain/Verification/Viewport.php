<?php

declare(strict_types=1);

namespace App\Domain\Verification;

final readonly class Viewport
{
    public function __construct(public string $name, public int $width) {}
}
