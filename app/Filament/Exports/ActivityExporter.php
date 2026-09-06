<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Support\ImportExportActions;
use App\Models\Activity;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of activities (module 18, decisions A-10, D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with the type by name and enums as labels in
 * the actor's locale, the owner by name, the linked records by their
 * labels, dates as Y-m-d H:i. Free-text cells are protected against
 * spreadsheet formula injection.
 */
final class ActivityExporter extends Exporter
{
    protected static ?string $model = Activity::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.activity.id')),
            ExportColumn::make('occurred_at')
                ->label(__('exports.columns.activity.occurred_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('kind')
                ->label(__('exports.columns.activity.kind'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('type.display_name')->label(__('exports.columns.activity.type')),
            ExportColumn::make('subject')->label(__('exports.columns.activity.subject'))->preventFormulaInjection(),
            ExportColumn::make('body')->label(__('exports.columns.activity.body'))->preventFormulaInjection(),
            ExportColumn::make('direction')
                ->label(__('exports.columns.activity.direction'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('duration_minutes')->label(__('exports.columns.activity.duration_minutes')),
            ExportColumn::make('outcome')->label(__('exports.columns.activity.outcome'))->preventFormulaInjection(),
            ExportColumn::make('related')
                ->label(__('exports.columns.activity.related'))
                ->state(fn (Activity $record): ?string => $record->subjectLabel())
                ->preventFormulaInjection(),
            ExportColumn::make('lead.full_name')
                ->label(__('exports.columns.activity.lead'))
                ->state(fn (Activity $record): ?string => $record->lead?->full_name)
                ->preventFormulaInjection(),
            ExportColumn::make('contact.full_name')
                ->label(__('exports.columns.activity.contact'))
                ->state(fn (Activity $record): ?string => $record->contact?->full_name)
                ->preventFormulaInjection(),
            ExportColumn::make('account.name')->label(__('exports.columns.activity.account'))->preventFormulaInjection(),
            ExportColumn::make('deal.title')->label(__('exports.columns.activity.deal'))->preventFormulaInjection(),
            ExportColumn::make('owner.name')->label(__('exports.columns.activity.owner')),
            ExportColumn::make('creator.name')->label(__('exports.columns.activity.created_by')),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.activity.created_at'))
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
