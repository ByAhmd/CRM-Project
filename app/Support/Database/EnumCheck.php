<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database CHECK constraints for code-enum columns (decision A-4).
 *
 * Enum columns are stored as VARCHAR and constrained at the database, so a
 * value the application does not know can never be written by a seeder, a
 * tinker session or a bug. Adding an enum case means a migration that drops
 * and recreates the constraint through this helper.
 *
 * Engine-aware (D-1): MySQL 8 and MariaDB spell the DROP differently, and the
 * production engine is confirmed only before the first production migration.
 */
final class EnumCheck
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function apply(string $table, string $column, string $enum): void
    {
        $values = implode(', ', array_map(
            static fn (\BackedEnum $case): string => DB::getPdo()->quote((string) $case->value),
            $enum::cases(),
        ));

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (`%s` IN (%s))',
            $table,
            self::name($table, $column),
            $column,
            $values,
        ));
    }

    public static function drop(string $table, string $column): void
    {
        $constraint = self::name($table, $column);

        if (self::isMariaDb()) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT IF EXISTS `%s`', $table, $constraint));

            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` DROP CHECK `%s`', $table, $constraint));
    }

    public static function name(string $table, string $column): string
    {
        return sprintf('%s_%s_check', $table, $column);
    }

    private static function isMariaDb(): bool
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;

        return stripos($version, 'mariadb') !== false;
    }
}
