<?php

declare(strict_types=1);

namespace App\Domain\Sites;

/**
 * @param  string|null  $reason  why the site is (or would be) blocked; null when it is not
 */
final readonly class BlockResult
{
    public function __construct(public BlockOutcome $outcome, public ?string $reason) {}
}
