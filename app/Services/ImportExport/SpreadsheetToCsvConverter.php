<?php

declare(strict_types=1);

namespace App\Services\ImportExport;

use DateInterval;
use DateTimeInterface;
use League\Csv\Exception as CsvException;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Turns an uploaded Excel workbook into the CSV that Filament's import action
 * already knows how to read (module 18, decision D-1).
 *
 * Filament parses an upload with league/csv, so a real workbook would reach a
 * text parser as binary noise. Converting first keeps the whole pipeline —
 * column mapping, validation, duplicate handling, the failed-rows report, the
 * history and the notifications — exactly as it is for a CSV.
 *
 * Nothing is written to disk. The workbook is read where the upload already
 * lies, on the private disk under `livewire-tmp` (OpenSpout opens a workbook as
 * a ZIP container, which needs a path rather than a stream), and the CSV is
 * built in a `php://temp` stream: in memory up to the spill threshold, and past
 * it in a temporary file PHP owns, keeps at 0600 and removes when the stream is
 * closed. Filament reads that stream inside the request and serialises the rows
 * into the queued jobs, so no converted file has to outlive the upload.
 *
 * Rules of the conversion:
 *
 * - the first worksheet only, because an import maps one header to one entity;
 *   the worksheet count travels back on the result so the UI can say what was
 *   left out rather than dropping it silently;
 * - the first row is the header row, as for a CSV, with the empty columns
 *   Excel reports past the last real one trimmed off, and every data row
 *   squared to the header's width;
 * - values stay faithful: a date becomes an ISO-8601 string, a number a plain
 *   decimal with no thousands separator and no scientific notation, a boolean
 *   the token the CSV importer already accepts, a blank cell an empty string;
 * - empty rows are skipped, so trailing ones cost nothing;
 * - everything is streamed — one row is held at a time, never the sheet — and
 *   the row ceiling of the calling action is enforced while reading, so an
 *   oversized workbook is refused instead of filling memory.
 *
 * .xls (the Excel 97-2003 binary format) is deliberately absent: OpenSpout
 * cannot read it, so the import action does not advertise or accept it.
 *
 * .ods (OpenDocument) is absent for a sharper reason: OpenSpout's ODS reader
 * returns every boolean cell as true. Its cell formatter casts the raw XML
 * attribute — `office:boolean-value="false"` — straight to bool, and the
 * non-empty string "false" casts to true. The reader hands back a
 * `BooleanCell` that carries only that ruined bool, and its reader, sheet
 * iterator, row iterator and cell formatter are all final, so nothing in this
 * application can see the attribute underneath and correct it. A false in the
 * sheet would silently arrive as a true in the database — `is_primary` on the
 * contact importer is exactly such a column — so the format is not offered at
 * all rather than offered and quietly wrong.
 */
final class SpreadsheetToCsvConverter
{
    /** The workbook extensions this converter reads. */
    public const EXTENSIONS = ['xlsx'];

    /** The content types they carry, which the import action advertises to the file picker. */
    public const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** The tokens Filament's `ImportColumn::boolean()` reads back as true and false. */
    private const BOOLEAN_TRUE = 'true';

    private const BOOLEAN_FALSE = 'false';

    /** The converted CSV stays in memory up to this size before PHP spills it to a private temporary file. */
    private const CSV_MEMORY_BYTES = 8 * 1024 * 1024;

    /** Whether a file name carries one of the workbook extensions this converter reads. */
    public static function handles(string $fileName): bool
    {
        return in_array(self::extensionOf($fileName), self::EXTENSIONS, true);
    }

    /**
     * @param  string  $workbookPath  where the uploaded workbook lies on the local disk
     * @param  string  $fileName  the name the user uploaded, which carries the format
     * @param  int|null  $maxDataRows  the row ceiling of the calling import action, header excluded
     *
     * @throws SpreadsheetImportException
     */
    public function convert(string $workbookPath, string $fileName, ?int $maxDataRows = null): SpreadsheetConversion
    {
        $extension = self::extensionOf($fileName);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw SpreadsheetImportException::unsupportedFormat();
        }

        if (! is_file($workbookPath) || ! is_readable($workbookPath)) {
            throw SpreadsheetImportException::unreadable();
        }

        $reader = new XlsxReader;

        try {
            $reader->open($workbookPath);
        } catch (OpenSpoutException $exception) {
            throw SpreadsheetImportException::unreadable($exception);
        }

        $csv = fopen('php://temp/maxmemory:'.self::CSV_MEMORY_BYTES, 'w+b');

        if ($csv === false) {
            $reader->close();

            throw SpreadsheetImportException::unreadable();
        }

        $writer = CsvWriter::from($csv);
        $sheetCount = 0;
        $sheetName = '';
        $rowCount = 0;
        $width = null;
        $failure = null;

        // A refusal is carried out of the loops rather than thrown from inside
        // them: an exception's stack trace holds the arguments of every frame it
        // crossed, and a trace holding the reader would keep the workbook open
        // for as long as the exception lives.
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetCount++;

                if ($sheetCount > 1) {
                    // Counted for the notice the UI shows, never imported: one
                    // import maps one header row to one entity.
                    continue;
                }

                $sheetName = $sheet->getName();

                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    $cells = $row->getNumCells();

                    // A sparse row leaves holes in getCells(), so the row is read
                    // by index and a hole becomes the blank cell it stands for.
                    for ($index = 0; $index < $cells; $index++) {
                        $cell = $row->getCellAtIndex($index);
                        $values[] = $cell === null ? '' : $this->cell($cell);
                    }

                    if ($width === null) {
                        $values = $this->withoutTrailingBlanks($values);

                        if ($values === []) {
                            continue;
                        }

                        $width = count($values);
                        $writer->insertOne($values);

                        continue;
                    }

                    if ($maxDataRows !== null && $rowCount >= $maxDataRows) {
                        $failure = SpreadsheetImportException::tooManyRows($maxDataRows);

                        break 2;
                    }

                    $writer->insertOne($this->squaredTo($values, $width));
                    $rowCount++;
                }
            }
        } catch (OpenSpoutException|CsvException $exception) {
            $failure = SpreadsheetImportException::unreadable($exception);
        } finally {
            $reader->close();
        }

        if ($failure === null && $width === null) {
            $failure = SpreadsheetImportException::emptySheet();
        }

        if ($failure instanceof SpreadsheetImportException) {
            fclose($csv);

            throw $failure;
        }

        rewind($csv);

        return new SpreadsheetConversion($csv, $sheetName, $sheetCount, $rowCount);
    }

    /**
     * One cell as the CSV text the importer's column casts read back.
     *
     * A formula exports the value the spreadsheet cached for it, not the
     * formula itself; a cell the spreadsheet marked as an error exports blank.
     */
    private function cell(Cell $cell): string
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            $value === null, $value === '' => '',
            is_bool($value) => $value ? self::BOOLEAN_TRUE : self::BOOLEAN_FALSE,
            is_int($value), is_float($value) => $this->number($value),
            $value instanceof DateTimeInterface => $this->dateTime($value),
            $value instanceof DateInterval => $this->duration($value),
            default => (string) $value,
        };
    }

    /**
     * A plain decimal: no thousands separator, no locale, no scientific
     * notation — the spreadsheet's display format is presentation, the value
     * is what the importer must read.
     */
    private function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_nan($value) || is_infinite($value)) {
            return '';
        }

        $formatted = (string) $value;

        if (stripos($formatted, 'e') === false) {
            return $formatted;
        }

        return rtrim(rtrim(sprintf('%.20F', $value), '0'), '.');
    }

    /** ISO-8601: a date-only cell keeps its date, a timestamp keeps its time and offset. */
    private function dateTime(DateTimeInterface $value): string
    {
        return $value->format('H:i:s') === '00:00:00'
            ? $value->format('Y-m-d')
            : $value->format('Y-m-d\TH:i:sP');
    }

    /** A duration cell as hours:minutes:seconds, days folded into the hours. */
    private function duration(DateInterval $value): string
    {
        return sprintf('%02d:%02d:%02d', ($value->d * 24) + $value->h, $value->i, $value->s);
    }

    /**
     * Excel reports the columns a sheet has ever used, so a header row often
     * ends in blanks. They would become duplicate empty header names, which
     * league/csv refuses outright, and columns nobody can map.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function withoutTrailingBlanks(array $values): array
    {
        while ($values !== [] && end($values) === '') {
            array_pop($values);
        }

        return $values;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function squaredTo(array $values, int $width): array
    {
        return array_slice(array_pad($values, $width, ''), 0, $width);
    }

    private static function extensionOf(string $fileName): string
    {
        return mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    }
}
