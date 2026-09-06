<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use Illuminate\Support\Collection;

/**
 * One line of a report table (module 23): a label and the values of the
 * report's columns, keyed the way the report's columns() declares them.
 *
 * Values are raw numbers (money as floats in the organisation currency,
 * rates as percentages with one decimal, counts as integers) so the page
 * formats them for the locale and the export writes them as numbers; a text
 * column carries a string. `meta` is what the presentation may need beyond
 * the values — the grouped record's id, a badge colour — never a figure.
 *
 * The FORMAT_* constants name how a column is rendered; each report's
 * formats() maps its column keys onto them.
 */
final readonly class ReportRow
{
    public const FORMAT_COUNT = 'count';

    public const FORMAT_MONEY = 'money';

    public const FORMAT_PERCENT = 'percent';

    public const FORMAT_DECIMAL = 'decimal';

    public const FORMAT_TEXT = 'text';

    /**
     * @param  array<string, int|float|string>  $values
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $label,
        public array $values,
        public array $meta = [],
    ) {}

    public function value(string $key): int|float|string
    {
        return $this->values[$key] ?? 0;
    }

    public function number(string $key): float
    {
        $value = $this->value($key);

        return is_string($value) ? 0.0 : (float) $value;
    }

    /**
     * A totals line: the given keys summed over the rows, every other key
     * blank, with the recomputed ratios merged on top.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @param  list<string>  $summed
     * @param  array<string, int|float|string>  $derived
     */
    public static function totals(string $label, Collection $rows, array $summed, array $derived = []): self
    {
        $values = [];

        foreach ($summed as $key) {
            $values[$key] = self::sum($rows, $key);
        }

        return new self($label, [...$values, ...$derived]);
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public static function sum(Collection $rows, string $key): int|float
    {
        $total = $rows->sum(static fn (ReportRow $row): float => $row->number($key));

        return $rows->every(static fn (ReportRow $row): bool => is_int($row->value($key))) ? (int) $total : round($total, 2);
    }

    /** A share as a percentage with one decimal; 0 when there is nothing to divide by. */
    public static function rate(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /** An average with one decimal; 0 when there is nothing to divide by. */
    public static function average(int|float $total, int|float $count, int $decimals = 1): float
    {
        return $count > 0 ? round($total / $count, $decimals) : 0.0;
    }
}
