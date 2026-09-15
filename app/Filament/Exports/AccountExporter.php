<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\CustomFieldEntity;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of accounts (module 18, decision D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with the industry and enums rendered as labels
 * in the actor's locale, the parent and owner by name, tags joined, dates
 * as Y-m-d H:i. All text cells are protected against spreadsheet formula
 * injection.
 */
final class AccountExporter extends Exporter
{
    protected static ?string $model = Account::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.account.id')),
            ExportColumn::make('name')->label(__('exports.columns.account.name'))->preventFormulaInjection(),
            ExportColumn::make('type')
                ->label(__('exports.columns.account.type'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
            ExportColumn::make('industry.display_name')->label(__('exports.columns.account.industry'))->preventFormulaInjection(),
            ExportColumn::make('size')
                ->label(__('exports.columns.account.size'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
            ExportColumn::make('website')->label(__('exports.columns.account.website'))->preventFormulaInjection(),
            ExportColumn::make('email')->label(__('exports.columns.account.email'))->preventFormulaInjection(),
            ExportColumn::make('phone')->label(__('exports.columns.account.phone'))->preventFormulaInjection(),
            ExportColumn::make('address_line')->label(__('exports.columns.account.address_line'))->preventFormulaInjection(),
            ExportColumn::make('city')->label(__('exports.columns.account.city'))->preventFormulaInjection(),
            ExportColumn::make('region')->label(__('exports.columns.account.region'))->preventFormulaInjection(),
            ExportColumn::make('country')
                ->label(__('exports.columns.account.country'))
                ->formatStateUsing(fn (?string $state): ?string => ImportExportActions::country($state))
                ->preventFormulaInjection(),
            ExportColumn::make('postal_code')->label(__('exports.columns.account.postal_code'))->preventFormulaInjection(),
            ExportColumn::make('parent.name')->label(__('exports.columns.account.parent'))->preventFormulaInjection(),
            ExportColumn::make('owner.name')->label(__('exports.columns.account.owner'))->preventFormulaInjection(),
            ExportColumn::make('customer_since')
                ->label(__('exports.columns.account.customer_since'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::date($state)),
            ExportColumn::make('tags')
                ->label(__('exports.columns.account.tags'))
                ->state(fn (Account $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                ->preventFormulaInjection(),
            ExportColumn::make('description')->label(__('exports.columns.account.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.account.created_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('updated_at')
                ->label(__('exports.columns.account.updated_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),

            // One column per active definition of the entity, rendered the
            // way the record page shows it (D-9).
            ...CustomFieldsSchema::exportColumns(CustomFieldEntity::Account),
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
