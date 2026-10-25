<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\User;
use App\Services\ImportExport\SpreadsheetConversion;
use App\Services\ImportExport\SpreadsheetImportException;
use App\Services\ImportExport\SpreadsheetToCsvConverter;
use App\Support\Filament\PanelUrl;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament's import action, taught to accept a real spreadsheet and to say
 * when the rows arrive (module 18).
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
 *
 * The rows themselves are written by the queue, not by the request: Filament
 * batches them in chunks of 100 on the database connection, and the scheduler
 * drains that connection every minute for at most 50 seconds (D-1). Filament's
 * own «import started» notice names the row count and nothing else, and a user
 * who expects the rows at once reads the quiet list that follows as a failed
 * import — so sendSuccessNotification() completes the notice (A-7 amendment).
 * A check-only run (D-29, the importers' «تحقق فقط دون كتابة» option) gets the
 * matching sentence instead: the file is read in the background and nothing is
 * written.
 */
final class SpreadsheetImportAction extends ImportAction
{
    /** How long the completed «started» notice stays on screen: two sentences and a button need more than Filament's six seconds. */
    public const STARTED_NOTICE_SECONDS = 10;

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

    /**
     * The «import started» notice, said in full: Filament's sentence (the row
     * count), then when the work begins and where its result lands — or, for
     * a check-only run (D-29), that the file is read in the background and
     * nothing is written — with a button to the import history, which
     * refreshes itself while a run is processing (ImportsTable). The history
     * button goes through PanelUrl like every link between panels (D-23) and
     * is left out for a reader the ImportPolicy refuses; the notice itself
     * never is.
     *
     * Filament sets the notice inside its action closure, on the queued
     * connections only: on the sync connection (tests) the rows are already
     * written and the completion notice has already been shown, so it sets
     * none, and this method adds none.
     */
    public function sendSuccessNotification(): static
    {
        if ($this->successNotification instanceof Notification && ! $this->isSuccessNotificationDisabled) {
            $this->describeQueuedRun($this->successNotification);
        }

        return parent::sendSuccessNotification();
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

    private function describeQueuedRun(Notification $notification): void
    {
        $sentence = $this->isCheckOnlyRun()
            ? __('imports.notifications.dry_run.started_body')
            : __('imports.notifications.started.body');

        $notification
            ->body(trim(((string) $notification->getBody()).' '.$sentence))
            ->seconds(self::STARTED_NOTICE_SECONDS);

        $user = auth()->user();

        if (! $user instanceof User || ! ImportResource::canViewAny()) {
            return;
        }

        $url = PanelUrl::forReader($user, ImportResource::class, static fn (): string => ImportResource::getUrl());

        if ($url === null) {
            return;
        }

        $notification->actions([
            Action::make('history')
                ->label(__('imports.actions.track_progress'))
                ->button()
                ->url($url),
        ]);
    }

    /**
     * Whether the modal that was just submitted asked for a check-only run
     * (D-29). The option travels in the action's validated data like the
     * duplicate strategy, and that data is still in hand when the success
     * notice is sent: Filament resets it only when the action is unmounted.
     */
    private function isCheckOnlyRun(): bool
    {
        return (bool) ($this->getData()[ImportExportActions::DRY_RUN_OPTION] ?? false);
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
