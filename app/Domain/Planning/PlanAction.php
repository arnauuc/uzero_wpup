<?php

declare(strict_types=1);

namespace App\Domain\Planning;

enum PlanAction: string
{
    case Update = 'update';
    case Skip = 'skip';

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::Update => 'actualitzar',
            self::Skip => 'ometre',
        };
    }
}
