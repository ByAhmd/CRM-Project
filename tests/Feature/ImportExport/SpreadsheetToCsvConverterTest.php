<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Services\ImportExport\SpreadsheetConversion;
use App\Services\ImportExport\SpreadsheetImportException;
use App\Services\ImportExport\SpreadsheetToCsvConverter;
use DateTimeImmutable;
use DateTimeInterface;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsUploadBytes;
use Tests\TestCase;
use ZipArchive;

/**
 * The conversion that lets a real Excel workbook through the import pipeline
 * (module 18).
 *
 * Every workbook here is written with OpenSpout inside the test, so the bytes
 * under test are a genuine container a reviewer can read the recipe for rather
 * than a committed binary nobody can check. The service turns bytes into bytes
 * and never touches the database.
 */
final class SpreadsheetToCsvConverterTest extends TestCase
{
    use BuildsUploadBytes;

    /** @var list<string> */
    private array $workbooks = [];

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $path) {
            @unlink($path);
        }

        $this->workbooks = [];

        parent::tearDown();
    }

    #[Test]
    public function a_workbook_becomes_the_csv_the_importer_reads(): void
    {
        $conversion = (new SpreadsheetToCsvConverter)->convert($this->workbook([
            'العملاء المحتملون' => [
                ['first_name', 'last_name', 'email', 'amount', 'close', 'is_primary', 'notes', '', ''],
                ['فهد', 'القحطاني', 'fahad@example.com', 1234.5, new DateTimeImmutable('2026-03-04'), true, null],
                ['', '', '', '', '', '', ''],
                ['Noura', 'Al-Otaibi', 'n@example.com', 0, null, false, 'a, comma'],
                ['', '', '', '', '', '', ''],
            ],
        ]), 'leads.xlsx', 5000);

        $this->assertSame('العملاء المحتملون', $conversion->sheetName);
        $this->assertSame(1, $conversion->sheetCount);
        $this->assertSame(2, $conversion->rowCount);
        $this->assertFalse($conversion->hasUnreadSheets());

        // The two empty columns Excel reports past the last header are gone;
        // Arabic survives as UTF-8; a date reads as ISO-8601; a number keeps no
        // locale formatting; a boolean uses the token the importer's boolean
        // cast accepts; a blank cell is an empty field; and the empty rows
        // between and after the data cost nothing.
        $this->assertSame(implode("\n", [
            'first_name,last_name,email,amount,close,is_primary,notes',
            'فهد,القحطاني,fahad@example.com,1234.5,2026-03-04,true,',
            'Noura,Al-Otaibi,n@example.com,0,,false,"a, comma"',
        ])."\n", $this->csvOf($conversion));
    }

    #[Test]
    public function a_timestamp_keeps_its_time_and_a_date_does_not_invent_one(): void
    {
        $conversion = (new SpreadsheetToCsvConverter)->convert($this->workbook([
            'Sheet1' => [
                ['due', 'seen'],
                [new DateTimeImmutable('2026-03-04'), new DateTimeImmutable('2026-03-04 05:06:07')],
            ],
        ]), 'tasks.xlsx', 5000);

        $this->assertStringContainsString('2026-03-04,2026-03-04T05:06:07', $this->csvOf($conversion));
    }

    #[Test]
    public function the_converted_csv_maps_its_header_the_way_the_import_action_reads_it(): void
    {
        $conversion = (new SpreadsheetToCsvConverter)->convert($this->workbook([
            'Sheet1' => [
                ['first_name', 'tags'],
                ['فهد', 'VIP|Enterprise'],
            ],
        ]), 'leads.xlsx', 5000);

        $csv = $this->streamOf($conversion);

        $reader = CsvReader::from($csv);
        $reader->setHeaderOffset(0);
        $records = iterator_to_array((new Statement)->process($reader)->getRecords(), false);

        $this->assertSame(['first_name', 'tags'], $reader->getHeader());
        $this->assertSame([['first_name' => 'فهد', 'tags' => 'VIP|Enterprise']], $records);

        fclose($csv);
    }

    #[Test]
    public function only_the_first_worksheet_is_converted_and_the_others_are_counted_for_the_notice(): void
    {
        $conversion = (new SpreadsheetToCsvConverter)->convert($this->workbook([
            'First' => [['a', 'b'], ['1', '2']],
            'Second' => [['x'], ['y']],
            'Third' => [['p'], ['q']],
        ]), 'many.xlsx', 5000);

        $this->assertSame('First', $conversion->sheetName);
        $this->assertSame(3, $conversion->sheetCount);
        $this->assertSame(1, $conversion->rowCount);
        $this->assertTrue($conversion->hasUnreadSheets(), 'the UI has nothing to warn about');
        $this->assertSame("a,b\n1,2\n", $this->csvOf($conversion));
    }

    #[Test]
    public function a_sheet_with_more_data_rows_than_the_action_allows_is_refused(): void
    {
        $rows = [['name']];

        for ($index = 0; $index < 4; $index++) {
            $rows[] = ['row '.$index];
        }

        try {
            (new SpreadsheetToCsvConverter)->convert($this->workbook(['Sheet1' => $rows]), 'big.xlsx', 3);

            $this->fail('an over-sized sheet was converted instead of being refused');
        } catch (SpreadsheetImportException $exception) {
            $this->assertSame(SpreadsheetImportException::TOO_MANY_ROWS, $exception->reason());
            $this->assertSame(3, $exception->rowLimit());
        }
    }

    #[Test]
    public function a_sheet_that_exactly_fills_the_allowance_is_converted(): void
    {
        $conversion = (new SpreadsheetToCsvConverter)->convert($this->workbook([
            'Sheet1' => [['name'], ['one'], ['two'], ['three']],
        ]), 'exact.xlsx', 3);

        $this->assertSame(3, $conversion->rowCount);
        $this->assertSame("name\none\ntwo\nthree\n", $this->csvOf($conversion));
    }

    #[Test]
    public function a_file_that_is_not_a_workbook_is_refused_cleanly(): void
    {
        $path = $this->fileWith($this->executableBytes());

        try {
            (new SpreadsheetToCsvConverter)->convert($path, 'malware.xlsx', 5000);

            $this->fail('a file that is not a workbook was accepted');
        } catch (SpreadsheetImportException $exception) {
            $this->assertSame(SpreadsheetImportException::UNREADABLE, $exception->reason());
        }
    }

    #[Test]
    public function a_workbook_whose_first_worksheet_holds_no_header_is_refused(): void
    {
        try {
            (new SpreadsheetToCsvConverter)->convert($this->workbook(['Sheet1' => [['']]]), 'empty.xlsx', 5000);

            $this->fail('an empty worksheet was accepted');
        } catch (SpreadsheetImportException $exception) {
            $this->assertSame(SpreadsheetImportException::EMPTY_SHEET, $exception->reason());
        }
    }

    #[Test]
    public function the_legacy_binary_excel_format_is_refused_before_the_file_is_opened(): void
    {
        $path = $this->workbook(['Sheet1' => [['a'], ['1']]]);

        try {
            // The bytes are a perfectly good workbook; the name says .xls, which
            // OpenSpout cannot read, so the importer never pretends it can.
            (new SpreadsheetToCsvConverter)->convert($path, 'legacy.xls', 5000);

            $this->fail('.xls was accepted by a reader that cannot parse it');
        } catch (SpreadsheetImportException $exception) {
            $this->assertSame(SpreadsheetImportException::UNSUPPORTED_FORMAT, $exception->reason());
        }

        $this->assertFalse(SpreadsheetToCsvConverter::handles('book.xls'));
        $this->assertTrue(SpreadsheetToCsvConverter::handles('book.XLSX'));
        $this->assertFalse(SpreadsheetToCsvConverter::handles('book.csv'));
    }

    #[Test]
    public function the_opendocument_format_is_refused_however_sound_the_workbook_is(): void
    {
        $path = $this->openDocumentWorkbook([
            'العملاء' => [
                ['first_name', 'close', 'is_primary'],
                ['نورة', new DateTimeImmutable('2026-03-04'), false],
            ],
            'Second' => [['x'], ['y']],
        ]);

        try {
            // Undamaged bytes OpenSpout's own ODS writer produced: the refusal
            // is by declared format, not by a container the reader choked on.
            (new SpreadsheetToCsvConverter)->convert($path, 'contacts.ods', 5000);

            $this->fail('.ods was converted although its boolean cells cannot be read faithfully');
        } catch (SpreadsheetImportException $exception) {
            $this->assertSame(SpreadsheetImportException::UNSUPPORTED_FORMAT, $exception->reason());
        }

        $this->assertFalse(SpreadsheetToCsvConverter::handles('book.ods'));
        $this->assertNotContains(
            'application/vnd.oasis.opendocument.spreadsheet',
            SpreadsheetToCsvConverter::MIME_TYPES,
            'the picker would offer a format the converter refuses',
        );
    }

    #[Test]
    public function the_opendocument_reader_reads_a_false_cell_as_true_which_is_why_the_format_is_refused(): void
    {
        $path = $this->openDocumentWorkbook([
            'Contacts' => [
                ['first_name', 'close', 'is_primary'],
                ['نورة', new DateTimeImmutable('2026-03-04'), false],
            ],
            'Second' => [['x'], ['y']],
        ]);

        $archive = new ZipArchive;
        $archive->open($path);
        $content = (string) $archive->getFromName('content.xml');
        $archive->close();

        // The workbook on disk is right: the writer recorded the false.
        $this->assertStringContainsString('office:boolean-value="false"', $content);

        $reader = new OdsReader;
        $reader->open($path);
        $sheetCount = 0;
        $dateCell = null;
        $booleanCell = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetCount++;

            if ($sheetCount > 1) {
                continue;
            }

            // The header row is read first and the data row overwrites it, so
            // what is left is the row carrying the date and the false.
            foreach ($sheet->getRowIterator() as $row) {
                $dateCell = $row->getCellAtIndex(1);
                $booleanCell = $row->getCellAtIndex(2);
            }
        }

        $reader->close();

        // A second worksheet is seen and a date still arrives typed rather than
        // as a string, so the reader is not simply broken.
        $this->assertSame(2, $sheetCount);
        $this->assertInstanceOf(DateTimeCell::class, $dateCell);
        $this->assertInstanceOf(BooleanCell::class, $booleanCell);

        // The day itself is not asserted: OpenSpout's ODS path round-trips a
        // date through UTC, so a midnight date written in Asia/Riyadh comes
        // back as the previous day. That is a second reason the format is
        // refused, and it is deliberately not pinned here — the XLSX tests
        // above assert that the format the CRM does accept keeps 2026-03-04
        // exactly, which is the property that ships.

        // OpenSpout casts the raw `office:boolean-value` attribute straight to
        // bool, and the non-empty string "false" casts to true. Every class on
        // that path is final and BooleanCell keeps nothing but the ruined bool,
        // so the conversion cannot correct it and does not accept .ods at all.
        //
        // When this assertion starts failing, the defect has been fixed
        // upstream and .ods may be offered again: add it back to
        // SpreadsheetToCsvConverter::EXTENSIONS and MIME_TYPES, to the helper
        // text `imports.helpers.file` in both locales and to the docs.
        $this->assertTrue(
            $booleanCell->getValue(),
            'OpenSpout now reads an ODS boolean faithfully — .ods can be accepted again',
        );
    }

    /**
     * @param  array<string, list<list<bool|DateTimeInterface|float|int|string|null>>>  $sheets
     */
    private function workbook(array $sheets): string
    {
        return $this->fileWith($this->xlsxBytes($sheets));
    }

    /**
     * @param  array<string, list<list<bool|DateTimeInterface|float|int|string|null>>>  $sheets
     */
    private function openDocumentWorkbook(array $sheets): string
    {
        return $this->fileWith($this->odsBytes($sheets));
    }

    /** Bytes under a path of their own, removed when the test ends. */
    private function fileWith(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crm-import');
        $this->workbooks[] = $path;

        file_put_contents($path, $bytes);

        return $path;
    }

    private function csvOf(SpreadsheetConversion $conversion): string
    {
        $csv = $this->streamOf($conversion);
        $contents = (string) stream_get_contents($csv);
        fclose($csv);

        return $contents;
    }

    /**
     * @return resource
     */
    private function streamOf(SpreadsheetConversion $conversion)
    {
        $csv = $conversion->csv;

        $this->assertTrue(is_resource($csv), 'the conversion carried no readable CSV stream');

        /** @var resource $csv */
        return $csv;
    }
}
