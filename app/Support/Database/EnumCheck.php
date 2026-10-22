<?php

declare(strict_types=1);

namespace App\Support\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Builds PostgreSQL CHECK constraints from backed enum cases, so the database
 * rejects values the application enums do not know (no PostgreSQL ENUM types).
 */
final class EnumCheck
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function sql(string $column, string $enum): string
    {
        $values = array_map(
            static fn (BackedEnum $case): string => "'".str_replace("'", "''", (string) $case->value)."'",
            $enum::cases(),
        );

        return sprintf('"%s" IN (%s)', $column, implode(', ', $values));
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function add(string $table, string $column, string $enum): void
    {
        DB::statement(sprintf(
            'ALTER TABLE "%s" ADD CONSTRAINT "%s_%s_check" CHECK (%s)',
            $table,
            $table,
            $column,
            self::sql($column, $enum),
        ));
    }
}
