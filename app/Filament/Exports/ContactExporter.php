<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\CustomFieldEntity;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Contact;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of contacts (module 18, decision D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with the account by name, the owner by name,
 * tags joined, booleans as yes/no in the actor's locale, dates as Y-m-d H:i.
 * All text cells are protected against spreadsheet formula injection.
 */
final class ContactExporter extends Exporter
{
    protected static ?string $model = Contact::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.contact.id')),
            ExportColumn::make('first_name')->label(__('exports.columns.contact.first_name'))->preventFormulaInjection(),
            ExportColumn::make('last_name')->label(__('exports.columns.contact.last_name'))->preventFormulaInjection(),
            ExportColumn::make('account.name')->label(__('exports.columns.contact.account'))->preventFormulaInjection(),
            ExportColumn::make('job_title')->label(__('exports.columns.contact.job_title'))->preventFormulaInjection(),
            ExportColumn::make('department')->label(__('exports.columns.contact.department'))->preventFormulaInjection(),
            ExportColumn::make('email')->label(__('exports.columns.contact.email'))->preventFormulaInjection(),
            ExportColumn::make('mobile')->label(__('exports.columns.contact.mobile'))->preventFormulaInjection(),
            ExportColumn::make('phone')->label(__('exports.columns.contact.phone'))->preventFormulaInjection(),
            ExportColumn::make('preferred_locale')->label(__('exports.columns.contact.preferred_locale'))->preventFormulaInjection(),
            ExportColumn::make('linkedin_url')->label(__('exports.columns.contact.linkedin_url'))->preventFormulaInjection(),
            ExportColumn::make('is_primary')
                ->label(__('exports.columns.contact.is_primary'))
                ->formatStateUsing(fn (mixed $state): string => ImportExportActions::yesNo($state))
                ->preventFormulaInjection(),
            ExportColumn::make('address_line')->label(__('exports.columns.contact.address_line'))->preventFormulaInjection(),
            ExportColumn::make('city')->label(__('exports.columns.contact.city'))->preventFormulaInjection(),
            ExportColumn::make('region')->label(__('exports.columns.contact.region'))->preventFormulaInjection(),
            ExportColumn::make('country')
                ->label(__('exports.columns.contact.country'))
                ->formatStateUsing(fn (?string $state): ?string => ImportExportActions::country($state))
                ->preventFormulaInjection(),
            ExportColumn::make('postal_code')->label(__('exports.columns.contact.postal_code'))->preventFormulaInjection(),
            ExportColumn::make('owner.name')->label(__('exports.columns.contact.owner'))->preventFormulaInjection(),
            ExportColumn::make('tags')
                ->label(__('exports.columns.contact.tags'))
                ->state(fn (Contact $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                ->preventFormulaInjection(),
            ExportColumn::make('description')->label(__('exports.columns.contact.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.contact.created_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('updated_at')
                ->label(__('exports.columns.contact.updated_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),

            // One column per active definition of the entity, rendered the
            // way the record page shows it (D-9).
            ...CustomFieldsSchema::exportColumns(CustomFieldEntity::Contact),
        ];
    }

    /**
     * @return array<mixed>
     */
    public function __invoke(Model $record): array
    {
        ImportExportActions::applyLocale($this->options);

        return parent::__invoke($record);
    }

    /**
     * Every custom column reads the record's values, so the relation is
     * loaded with the chunk instead of once per cell (D-9).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return CustomFieldsSchema::eagerLoad($query);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        ImportExportActions::applyLocale($export->getOptions());

        $body = trans_choice('exports.notifications.completed', (int) $export->successful_rows);
        $failed = $export->getFailedRowsCount();

        return $failed > 0
            ? $body.' '.trans_choice('exports.notifications.failed', $failed)
            : $body;
    }
}
