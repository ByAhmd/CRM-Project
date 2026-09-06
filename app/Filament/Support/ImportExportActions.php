<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonInterface;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\Importer;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

/**
 * The import and export actions every entity list registers with one line
 * (module 18, decisions D-1, D-13).
 *
 * Filament's actions are the engine; this class adds what the CRM needs on
 * top: the policy gate (`import` / `export` verb of the entity's policy), the
 * size limits, the private disk for export files and the locale the run is
 * rendered in. Filament queues the jobs; production drains the database
 * queue through the scheduler (D-1) and tests run them inline (sync).
 *
 * Scope (D-13): the export action exports the table's own query, which every
 * Resource has already passed through RecordVisibilityResolver — a rep
 * exports the rows they can see and nothing else. The bulk action exports
 * the selected rows, each re-authorised through the policy's `view`.
 */
final class ImportExportActions
{
    /** Export files are written here and served only through authenticated, policy-checked downloads. */
    public const FILE_DISK = 'local';

    /** The option carrying the actor's locale into the queued job. */
    public const LOCALE_OPTION = 'locale';

    /**
     * @param  class-string<Importer>  $importer
     * @param  class-string<Model>  $model
     */
    public static function import(string $importer, string $model): ImportAction
    {
        return ImportAction::make()
            ->importer($importer)
            ->label(fn (ImportAction $action): string => __('imports.actions.import', ['label' => $action->getPluralModelLabel()]))
            ->authorize(fn (): bool => auth()->user()?->can('import', $model) ?? false)
            ->options(fn (): array => [self::LOCALE_OPTION => app()->getLocale()])
            ->chunkSize(100)
            ->maxRows(5000);
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<Model>  $model
     */
    public static function export(string $exporter, string $model): ExportAction
    {
        $action = ExportAction::make()
            ->label(fn (ExportAction $action): string => __('exports.actions.export', ['label' => $action->getPluralModelLabel()]));

        return self::configureExport($action, $exporter, $model);
    }

    /**
     * The selected rows only. Filament's ExportBulkAction deliberately fetches
     * keys rather than models (fetchSelectedRecords(false)) and resolves
     * those keys through the table's own scoped query — a key outside the
     * actor's reach is dropped before the job is queued, and the job loads
     * the rows through that same serialised query. That is the per-record
     * scope check here; authorizeIndividualRecords() cannot apply because it
     * needs model instances this action never loads.
     *
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<Model>  $model
     */
    public static function exportBulk(string $exporter, string $model): ExportBulkAction
    {
        $action = ExportBulkAction::make()
            ->label(__('exports.actions.export_selected'))
            ->deselectRecordsAfterCompletion();

        return self::configureExport($action, $exporter, $model);
    }

    /**
     * Switches the process to the locale the run was started in, so a queued
     * job renders lookups, enum labels and failure reasons the way the actor
     * reads them (D-5). Unknown or missing locales leave the default.
     *
     * @param  array<string, mixed>  $options
     */
    public static function applyLocale(array $options): void
    {
        $locale = $options[self::LOCALE_OPTION] ?? null;

        if (is_string($locale) && in_array($locale, (array) config('app.locales'), true)) {
            app()->setLocale($locale);
        }
    }

    // --- Cell formatters shared by the exporters ---

    /** A code enum as its translated label; anything else exports empty. */
    public static function enumLabel(mixed $state): ?string
    {
        return $state instanceof HasLabel ? (string) $state->getLabel() : null;
    }

    public static function dateTime(?CarbonInterface $state): ?string
    {
        return $state?->format('Y-m-d H:i');
    }

    public static function date(?CarbonInterface $state): ?string
    {
        return $state?->format('Y-m-d');
    }

    /** Money with two decimals, a dot and no thousands separator, so spreadsheets read it as a number. */
    public static function money(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        return number_format((float) $state, 2, '.', '');
    }

    /** A country code as its name in the actor's locale. */
    public static function country(?string $state): ?string
    {
        return $state === null ? null : (AddressSchema::countryOptions()[$state] ?? $state);
    }

    public static function yesNo(mixed $state): string
    {
        return (bool) $state ? __('exports.values.yes') : __('exports.values.no');
    }

    /**
     * @template TAction of ExportAction|ExportBulkAction
     *
     * @param  TAction  $action
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<Model>  $model
     * @return TAction
     */
    private static function configureExport(ExportAction|ExportBulkAction $action, string $exporter, string $model): ExportAction|ExportBulkAction
    {
        return $action
            ->exporter($exporter)
            ->authorize(fn (): bool => auth()->user()?->can('export', $model) ?? false)
            ->options(fn (): array => [self::LOCALE_OPTION => app()->getLocale()])
            ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
            ->fileDisk(self::FILE_DISK)
            ->chunkSize(500)
            ->maxRows(20000);
    }
}
