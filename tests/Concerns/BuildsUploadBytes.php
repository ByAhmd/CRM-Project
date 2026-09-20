<?php

declare(strict_types=1);

namespace Tests\Concerns;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Minimal, real file contents for upload tests: each builder returns bytes
 * that finfo sniffs as the named type, so the attachment MIME allowlist
 * (A-6) is exercised against content rather than against a client-supplied
 * extension.
 */
trait BuildsUploadBytes
{
    /** A well-formed PDF with an empty page tree (application/pdf). */
    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A 1x1 transparent PNG (image/png). */
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    /** The header of a Windows PE executable (application/x-dosexec), which the allowlist refuses. */
    protected function executableBytes(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 100).'This program cannot be run in DOS mode.';
    }

    /**
     * A genuine Excel workbook
     * (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet),
     * written with the same OpenSpout writer the exports use, so an import
     * test reads a real container instead of a committed binary nobody can
     * review.
     *
     * A DateTimeInterface cell is given the number format that makes Excel — and
     * OpenSpout's reader — treat it as a date rather than as the serial number
     * underneath, which is what a spreadsheet a user saved would carry.
     *
     * @param  array<string, list<list<bool|DateTimeInterface|float|int|string|null>>>  $sheets  worksheet name => its rows, the first row the header
     */
    protected function xlsxBytes(array $sheets): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crm-xlsx');

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $isFirstSheet = true;

        foreach ($sheets as $name => $rows) {
            if (! $isFirstSheet) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($name);
            $isFirstSheet = false;

            foreach ($rows as $row) {
                $values = $row;
                $styles = [];

                foreach ($values as $index => $value) {
                    if ($value instanceof DateTimeInterface) {
                        $styles[$index] = (new Style)->setFormat(
                            $value->format('H:i:s') === '00:00:00' ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm:ss',
                        );
                    }
                }

                $writer->addRow(Row::fromValuesWithStyles($values, null, $styles));
            }
        }

        $writer->close();

        return $this->bytesAt($path);
    }

    /**
     * A genuine OpenDocument spreadsheet
     * (application/vnd.oasis.opendocument.spreadsheet), written with OpenSpout's
     * own ODS writer.
     *
     * The importer refuses .ods, so this builds the workbook a refusal test has
     * to be given: real, undamaged bytes, so the refusal is proven to rest on
     * the declared format and not on a file the reader could not have opened
     * anyway. Dates need no number format here — ODS stores a date cell with its
     * own value type rather than as a styled serial number the way XLSX does.
     *
     * @param  array<string, list<list<bool|DateTimeInterface|float|int|string|null>>>  $sheets  worksheet name => its rows, the first row the header
     */
    protected function odsBytes(array $sheets): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crm-ods');

        $writer = new OdsWriter;
        $writer->openToFile($path);
        $isFirstSheet = true;

        foreach ($sheets as $name => $rows) {
            if (! $isFirstSheet) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($name);
            $isFirstSheet = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $this->bytesAt($path);
    }

    /** The bytes a writer just produced, the temporary file it used removed behind them. */
    private function bytesAt(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
