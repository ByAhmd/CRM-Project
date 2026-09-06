<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Support\ImportExportActions;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of leads (module 18, decision D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with lookups and enums rendered as labels in
 * the actor's locale, the owner by name, tags joined, dates as Y-m-d H:i.
 * Free-text cells are protected against spreadsheet formula injection.
 */
final class LeadExporter extends Exporter
{
    protected static ?string $model = Lead::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.lead.id')),
            ExportColumn::make('first_name')->label(__('exports.columns.lead.first_name'))->preventFormulaInjection(),
            ExportColumn::make('last_name')->label(__('exports.columns.lead.last_name'))->preventFormulaInjection(),
            ExportColumn::make('company_name')->label(__('exports.columns.lead.company_name'))->preventFormulaInjection(),
            ExportColumn::make('job_title')->label(__('exports.columns.lead.job_title'))->preventFormulaInjection(),
            ExportColumn::make('email')->label(__('exports.columns.lead.email')),
            ExportColumn::make('phone')->label(__('exports.columns.lead.phone')),
            ExportColumn::make('website')->label(__('exports.columns.lead.website')),
            ExportColumn::make('address_line')->label(__('exports.columns.lead.address_line'))->preventFormulaInjection(),
            ExportColumn::make('city')->label(__('exports.columns.lead.city'))->preventFormulaInjection(),
            ExportColumn::make('region')->label(__('exports.columns.lead.region'))->preventFormulaInjection(),
            ExportColumn::make('country')
                ->label(__('exports.columns.lead.country'))
                ->formatStateUsing(fn (?string $state): ?string => ImportExportActions::country($state)),
            ExportColumn::make('postal_code')->label(__('exports.columns.lead.postal_code')),
            ExportColumn::make('status.display_name')->label(__('exports.columns.lead.status')),
            ExportColumn::make('source.display_name')->label(__('exports.columns.lead.source')),
            ExportColumn::make('priority')
                ->label(__('exports.columns.lead.priority'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('effective_score')
                ->label(__('exports.columns.lead.score'))
                ->state(fn (Lead $record): int => $record->effective_score),
            ExportColumn::make('owner.name')->label(__('exports.columns.lead.owner')),
            ExportColumn::make('tags')
                ->label(__('exports.columns.lead.tags'))
                ->state(fn (Lead $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all()),
            ExportColumn::make('qualified_at')
                ->label(__('exports.columns.lead.qualified_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('converted_at')
                ->label(__('exports.columns.lead.converted_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('description')->label(__('exports.columns.lead.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.lead.created_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('updated_at')
                ->label(__('exports.columns.lead.updated_at'))
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
