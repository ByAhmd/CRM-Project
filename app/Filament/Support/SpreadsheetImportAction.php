<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\ImportExport\SpreadsheetConversion;
use App\Services\ImportExport\SpreadsheetImportException;
use App\Services\ImportExport\SpreadsheetToCsvConverter;
use Closure;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament's import action, taught to accept a real spreadsheet (module 18).
 *
 * Filament reads every upload with league/csv. This subclass converts an Excel
 * (.xlsx) workbook to CSV the moment the upload is read, so the column
 * mapping, the validation, the duplicate handling, the failed-rows report, the
 * history and the notifications downstream never learn that the file was not a
 * CSV. The conversion itself is a service
 * (App\Services\ImportExport\SpreadsheetToCsvConverter); this class only
 * presents it — what the picker advertises, when the reader runs, and how a
 * refusal reads.
 *
 * .xls (Excel 97-2003) and .ods (OpenDocument) are not among the accepted
 * types: OpenSpout cannot read the first at all and reads a boolean cell of the
 * second wrongly (the converter's docblock carries the detail), and a format
 * the importer cannot read faithfully is never offered. The modal's helper text
 * names .xlsx as the workbook format to save, so a user holding either one
 * knows what to do rather than guessing from a rejected upload.
 */
final class SpreadsheetImportAction extends ImportAction
{
    /**
     * What the file picker offers and the `mimetypes` rule accepts: Filament's
     * own CSV list, minus `application/vnd.ms-excel` (that is .xls), plus the
     * workbook container OpenSpout reads faithfully.
     */
    public const ACCEPTED_FILE_TYPES = [
        'text/csv',
        'text/x-csv',
        'application/csv',
        'application/x-csv',
        'text/comma-separated-values',
        'text/x-comma-separated-values',
        'text/plain',
        ...SpreadsheetToCsvConverter::MIME_TYPES,
    ];

    /** The extensions that replace Filament's hardcoded `extensions:csv,txt` rule. */
    public const ACCEPTED_EXTENSIONS = ['csv', 'txt', ...SpreadsheetToCsvConverter::EXTENSIONS];

    /**
     * One conversion per uploaded file per request: Filament reads the upload
     * to guess the column map, again for every validation pass and again to
     * queue the rows, and a workbook is not worth parsing four times.
     *
     * @var array<string, SpreadsheetConversion>
     */
    private array $conversions = [];

    /** @var array<string, SpreadsheetImportException> */
    private array $failures = [];

    /**
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        if (! SpreadsheetToCsvConverter::handles($file->getClientOriginalName())) {
            return parent::getUploadedFileStream($file);
        }

        $conversion = $this->convert($file);

        if (! $conversion instanceof SpreadsheetConversion) {
            // The reason reaches the user through the validation rule below;
            // Filament reads a false stream as "nothing to do" and stops.
            return false;
        }

        $stream = $conversion->csv;

        if (! is_resource($stream)) {
            return false;
        }

        // The converted CSV is comma separated. Filament otherwise guesses the
        // delimiter from the data, and a narrow sheet whose cells hold pipes or
        // semicolons can out-vote the real separator. Every Filament code path
        // asks for the stream before it asks for the delimiter, so this lands in
        // time; a genuine CSV upload leaves Filament's guess untouched.
        $this->csvDelimiter(',');

        rewind($stream);

        return $stream;
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $rules = [];

        foreach (parent::getFileValidationRules() as $rule) {
            // Filament hardcodes `extensions:csv,txt`, which refuses the very
            // workbooks this action exists to read.
            $rules[] = $rule === 'extensions:csv,txt'
                ? 'extensions:'.implode(',', self::ACCEPTED_EXTENSIONS)
                : $rule;
        }

        $rules[] = fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            if (! SpreadsheetToCsvConverter::handles($value->getClientOriginalName())) {
                return;
            }

            $this->convert($value);

            $failure = $this->failures[$this->keyFor($value)] ?? null;

            if ($failure instanceof SpreadsheetImportException) {
                $fail($this->reasonFor($failure));
            }
        };

        return $rules;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $inherited = $this->schema;

        $this->schema(function () use ($inherited): array {
            $components = $this->evaluate($inherited);

            if (! is_array($components)) {
                return [];
            }

            foreach ($components as $component) {
                if (! $component instanceof FileUpload) {
                    continue;
                }

                $component
                    ->acceptedFileTypes(self::ACCEPTED_FILE_TYPES)
                    ->helperText(__('imports.helpers.file'))
                    ->afterStateUpdated(function (?TemporaryUploadedFile $state): void {
                        $this->announceUnreadSheets($state);
                    });
            }

            return $components;
        });
    }

    /**
     * A workbook built with several worksheets loses all but the first one;
     * saying so as the file is picked beats a silent half import.
     */
    private function announceUnreadSheets(?TemporaryUploadedFile $state): void
    {
        if (! $state instanceof TemporaryUploadedFile) {
            return;
        }

        if (! SpreadsheetToCsvConverter::handles($state->getClientOriginalName())) {
            return;
        }

        $conversion = $this->convert($state);

        if (! $conversion instanceof SpreadsheetConversion || ! $conversion->hasUnreadSheets()) {
            return;
        }

        Notification::make()
            ->title(__('imports.notifications.multiple_sheets.title'))
            ->body(trans_choice('imports.notifications.multiple_sheets.body', $conversion->sheetCount, [
                'count' => Number::format($conversion->sheetCount),
                'sheet' => $conversion->sheetName,
            ]))
            ->warning()
            ->send();
    }

    private function convert(TemporaryUploadedFile $file): ?SpreadsheetConversion
    {
        $key = $this->keyFor($file);

        if (array_key_exists($key, $this->conversions)) {
            return $this->conversions[$key];
        }

        if (array_key_exists($key, $this->failures)) {
            return null;
        }

        try {
            // The upload already lies on the private disk under `livewire-tmp`,
            // where the CSV path reads it too; OpenSpout opens the workbook there
            // rather than through a second copy that would have to be cleaned up.
            return $this->conversions[$key] = app(SpreadsheetToCsvConverter::class)->convert(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $this->getMaxRows(),
            );
        } catch (SpreadsheetImportException $exception) {
            $this->failures[$key] = $exception;

            return null;
        }
    }

    /** The sentence the user reads for a workbook the importer had to refuse. */
    private function reasonFor(SpreadsheetImportException $failure): string
    {
        $limit = $failure->rowLimit() ?? 0;

        return match ($failure->reason()) {
            // The ceiling is the action's own, so the wording is the one a CSV
            // of the same size already gets.
            SpreadsheetImportException::TOO_MANY_ROWS => trans_choice(
                'filament-actions::import.notifications.max_rows.body',
                $limit,
                ['count' => Number::format($limit)],
            ),
            SpreadsheetImportException::EMPTY_SHEET => __('imports.validation.empty_spreadsheet'),
            default => __('imports.validation.unreadable_spreadsheet'),
        };
    }

    /** Livewire's temporary file name, unique to one upload. */
    private function keyFor(TemporaryUploadedFile $file): string
    {
        return $file->getFilename();
    }
}
