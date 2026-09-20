<?php

declare(strict_types=1);

namespace App\Services\ImportExport;

/**
 * What converting an uploaded workbook to CSV produced (module 18): the CSV
 * itself, plus what the reader saw in the workbook so the UI can tell the
 * user which worksheet was read and how many were left out.
 */
final readonly class SpreadsheetConversion
{
    /**
     * @param  resource  $csv  the converted CSV, rewound and ready to read — a
     *                         `php://temp` stream, so it lives in memory until it
     *                         grows past the spill threshold and PHP releases it
     *                         (and any private spill file) when it is closed
     * @param  string  $sheetName  the name of the worksheet that was read
     * @param  int  $sheetCount  worksheets in the workbook; only the first was read
     * @param  int  $rowCount  data rows written, the header row excluded
     */
    public function __construct(
        public mixed $csv,
        public string $sheetName,
        public int $sheetCount,
        public int $rowCount,
    ) {}

    /** More than one worksheet means the rest were left out and the user must be told. */
    public function hasUnreadSheets(): bool
    {
        return $this->sheetCount > 1;
    }
}
