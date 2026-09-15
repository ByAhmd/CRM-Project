<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Query counting for the performance probes (plan section 8).
 *
 * A probe runs the same surface twice — once over a small data set, once
 * over a larger one — and asserts that the extra rows did not add queries.
 * A constant difference means the page is set-based; a difference that
 * grows with the rows is an N+1.
 */
trait CountsQueries
{
    /**
     * Every SQL statement the callback issued, in order.
     *
     * @param  callable(): mixed  $callback
     * @return list<string>
     */
    protected function queriesDuring(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return array_values(array_map(static fn (array $entry): string => (string) $entry['query'], $log));
    }

    /**
     * @param  callable(): mixed  $callback
     */
    protected function countQueries(callable $callback): int
    {
        return count($this->queriesDuring($callback));
    }

    /**
     * The statements that read from the given table, for a message that
     * names the repeated query rather than just a number.
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    protected function queriesTouching(array $queries, string $table): array
    {
        return array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '`'.$table.'`')));
    }

    /**
     * The most repeated statements, to make a failing probe self-explaining.
     *
     * @param  list<string>  $queries
     */
    protected function topRepeated(array $queries, int $limit = 5): string
    {
        $counts = array_count_values($queries);
        arsort($counts);

        $lines = [];

        foreach (array_slice($counts, 0, $limit, true) as $sql => $count) {
            $lines[] = sprintf('%dx %s', $count, $sql);
        }

        return implode("\n", $lines);
    }
}
