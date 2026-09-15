<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guards a DATETIME column on its way back to TIMESTAMP.
 *
 * The 2026_09_14 migrations widened workflow moments from TIMESTAMP to
 * DATETIME. Rolling one back narrows the column again, and TIMESTAMP stores
 * only 1970-01-01 00:00:01 to 2038-01-19 03:14:07 UTC: in strict mode the
 * ALTER fails part-way on the first value outside that range, and without
 * strict mode the value is silently zeroed. The rollback therefore refuses up
 * front, naming the table, the column and the offending rows, before any DDL
 * runs.
 *
 * The bounds are read as the session sees them. The DATETIME to TIMESTAMP
 * conversion interprets a stored wall clock in the session time zone, and
 * FROM_UNIXTIME() renders the first and last representable instants in that
 * same zone, identically on MySQL 8 and MariaDB.
 */
final class TimestampRange
{
    /** First representable TIMESTAMP instant, in seconds since the epoch. */
    public const int FIRST_INSTANT = 1;

    /** Last representable TIMESTAMP instant, in seconds since the epoch. */
    public const int LAST_INSTANT = 2147483647;

    /** How many offending ids one message names before it only counts the rest. */
    private const int LISTED_IDS = 20;

    /**
     * @param  list<string>  $columns
     *
     * @throws RuntimeException when any column holds a value TIMESTAMP cannot store
     */
    public static function refuseValuesOutside(string $table, array $columns): void
    {
        $problems = [];

        foreach ($columns as $column) {
            $ids = DB::table($table)
                ->whereNotNull($column)
                ->where(static fn ($query) => $query
                    ->whereRaw(sprintf('`%s` < FROM_UNIXTIME(%d)', $column, self::FIRST_INSTANT))
                    ->orWhereRaw(sprintf('`%s` > FROM_UNIXTIME(%d)', $column, self::LAST_INSTANT)))
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($ids === []) {
                continue;
            }

            $listed = implode(', ', array_slice($ids, 0, self::LISTED_IDS));
            $more = count($ids) - self::LISTED_IDS;

            $problems[] = sprintf(
                '%s.%s holds values outside the TIMESTAMP range in rows %s%s',
                $table,
                $column,
                $listed,
                $more > 0 ? sprintf(' and %d more', $more) : '',
            );
        }

        if ($problems !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to convert back to TIMESTAMP, which stores only 1970-01-01 00:00:01 to 2038-01-19 03:14:07 UTC: %s. Correct or clear those values, then roll back again.',
                implode('; ', $problems),
            ));
        }
    }
}
