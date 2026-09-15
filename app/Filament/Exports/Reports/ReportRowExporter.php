<?php

declare(strict_types=1);

namespace App\Filament\Exports\Reports;

use App\Filament\Support\ImportExportActions;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / XLSX download of a report table (module 23, decision D-13).
 *
 * Report rows are aggregates computed server-side inside the viewer's
 * scope, not records of a table, so Filament's queued Exporter (which
 * chunks a model query) does not fit; the file is written with the same
 * library Filament's XLSX job uses (openspout) and streamed back through
 * the page's export action, which is gated by `reports.view`.
 *
 * The file carries the same columns the page shows, in the same order,
 * with the totals line last: labels as text, counts as integers, money as
 * numbers with two decimals, rates and averages with one — never the
 * locale-formatted strings, so a spreadsheet reads them as numbers. Text
 * cells are protected against formula injection the way Filament's export
 * columns are. The file is written to the private disk and removed once it
 * has been streamed — in a `finally`, so an aborted transfer cleans up too,
 * with the scheduler pruning the directory hourly as the last resort;
 * nothing is kept and nothing is audited.
 */
final class ReportRowExporter
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMATS = [self::FORMAT_CSV, self::FORMAT_XLSX];

    /** Where the files are written before they are streamed, on the private disk. */
    public const DIRECTORY = 'reports';

    private const CONTENT_TYPES = [
        self::FORMAT_CSV => 'text/csv; charset=UTF-8',
        self::FORMAT_XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  array<string, string>  $formats  key => ReportRow::FORMAT_*
     * @param  Collection<int, ReportRow>  $rows
     */
    public static function download(
        string $format,
        string $fileName,
        string $labelHeading,
        array $columns,
        array $formats,
        Collection $rows,
        ?ReportRow $totals,
    ): StreamedResponse {
        $format = in_array($format, self::FORMATS, true) ? $format : self::FORMAT_CSV;
        $path = self::write($format, $labelHeading, $columns, $formats, $rows, $totals);

        // finally, not a trailing statement: a client that aborts the
        // transfer (or a read that throws) must not leave the file behind on
        // a quota-bound host (D-1). The scheduler prunes the directory hourly
        // for whatever a killed process still managed to strand there.
        return response()->streamDownload(static function () use ($path): void {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, $fileName.'.'.$format, ['Content-Type' => self::CONTENT_TYPES[$format]]);
    }

    /**
     * Writes the file and returns its absolute path.
     *
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $formats
     * @param  Collection<int, ReportRow>  $rows
     */
    public static function write(
        string $format,
        string $labelHeading,
        array $columns,
        array $formats,
        Collection $rows,
        ?ReportRow $totals,
    ): string {
        $disk = Storage::disk(ImportExportActions::FILE_DISK);
        $disk->makeDirectory(self::DIRECTORY);
        $path = $disk->path(self::DIRECTORY.'/'.Str::uuid()->toString().'.'.$format);

        $writer = $format === self::FORMAT_XLSX ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([self::text($labelHeading), ...array_map(self::text(...), array_values($columns))]));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(self::cells($row, $columns, $formats)));
        }

        if ($totals !== null) {
            $writer->addRow(Row::fromValues(self::cells($totals, $columns, $formats)));
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $formats
     * @return list<int|float|string>
     */
    private static function cells(ReportRow $row, array $columns, array $formats): array
    {
        $cells = [self::text($row->label)];

        foreach (array_keys($columns) as $key) {
            $value = $row->value($key);

            $cells[] = match ($formats[$key] ?? ReportRow::FORMAT_TEXT) {
                ReportRow::FORMAT_COUNT => is_string($value) ? self::text($value) : (int) $value,
                ReportRow::FORMAT_MONEY => is_string($value) ? self::text($value) : round((float) $value, 2),
                ReportRow::FORMAT_PERCENT, ReportRow::FORMAT_DECIMAL => is_string($value) ? self::text($value) : round((float) $value, 1),
                default => self::text((string) $value),
            };
        }

        return $cells;
    }

    /**
     * A text cell a spreadsheet will never evaluate (CWE-1236): a value that
     * starts with = + - @ TAB or CR is prefixed with a quote. A sign-led
     * numeric string such as "-5" is a number to a spreadsheet, not a formula,
     * and is left unchanged, as Filament's export columns do.
     */
    private static function text(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], ['-', '+'], true) && is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
