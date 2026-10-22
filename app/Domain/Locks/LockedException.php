<?php

declare(strict_types=1);

namespace App\Domain\Locks;

use RuntimeException;

/**
 * The site or its hosting account is busy. Carries who holds it.
 */
final class LockedException extends RuntimeException
{
    public function __construct(
        public readonly LockLevel $level,
        public readonly ?LockHolder $holder,
    ) {
        parent::__construct(sprintf(
            'El %s està bloquejat%s.',
            $level->label(),
            $holder === null ? '' : ' per '.$holder->describe(),
        ));
    }
}
