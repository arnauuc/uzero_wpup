<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

enum ImportFormat: string
{
    case Csv = 'csv';
    case Yaml = 'yaml';

    public static function fromPath(string $path): ?self
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => self::Csv,
            'yaml', 'yml' => self::Yaml,
            default => null,
        };
    }
}
