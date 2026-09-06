<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Support\ImportExportActions;
use App\Models\Deal;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of deals (module 18, decisions D-8, D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with the account, contact, pipeline, stage,
 * source and close reason by name in the actor's locale, enums as labels,
 * money with two decimals, the effective probability and weighted amount
 * as the panel shows them, tags joined, dates as Y-m-d H:i. Free-text cells
 * are protected against spreadsheet formula injection.
 */
final class DealExporter extends Exporter
{
    protected static ?string $model = Deal::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.deal.id')),
            ExportColumn::make('title')->label(__('exports.columns.deal.title'))->preventFormulaInjection(),
            ExportColumn::make('account.name')->label(__('exports.columns.deal.account'))->preventFormulaInjection(),
            ExportColumn::make('contact.full_name')
                ->label(__('exports.columns.deal.contact'))
                ->state(fn (Deal $record): ?string => $record->contact?->full_name)
                ->preventFormulaInjection(),
            ExportColumn::make('pipeline.display_name')->label(__('exports.columns.deal.pipeline')),
            ExportColumn::make('stage.display_name')->label(__('exports.columns.deal.stage')),
            ExportColumn::make('status')
                ->label(__('exports.columns.deal.status'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('amount')
                ->label(__('exports.columns.deal.amount'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::money($state)),
            ExportColumn::make('currency')->label(__('exports.columns.deal.currency')),
            ExportColumn::make('effective_probability')
                ->label(__('exports.columns.deal.probability'))
                ->state(fn (Deal $record): int => $record->effective_probability),
            ExportColumn::make('weighted_amount')
                ->label(__('exports.columns.deal.weighted_amount'))
                ->state(fn (Deal $record): string => $record->weighted_amount),
            ExportColumn::make('expected_close_date')
                ->label(__('exports.columns.deal.expected_close_date'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::date($state)),
            ExportColumn::make('forecast_category')
                ->label(__('exports.columns.deal.forecast_category'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('source.display_name')->label(__('exports.columns.deal.source')),
            ExportColumn::make('owner.name')->label(__('exports.columns.deal.owner')),
            ExportColumn::make('tags')
                ->label(__('exports.columns.deal.tags'))
                ->state(fn (Deal $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all()),
            ExportColumn::make('won_at')
                ->label(__('exports.columns.deal.won_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('lost_at')
                ->label(__('exports.columns.deal.lost_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('closeReason.display_name')->label(__('exports.columns.deal.close_reason')),
            ExportColumn::make('description')->label(__('exports.columns.deal.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.deal.created_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('updated_at')
                ->label(__('exports.columns.deal.updated_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
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

    public static function getCompletedNotificationBody(Export $export): string
    {
        ImportExportActions::applyLocale($export->getOptions());

        return __('exports.notifications.completed', [
            'successful' => (string) $export->successful_rows,
            'failed' => (string) $export->getFailedRowsCount(),
        ]);
    }
}
