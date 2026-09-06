<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Support\ImportExportActions;
use App\Models\Task;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of tasks (module 18, decisions A-10, D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with enums as labels in the actor's locale,
 * the assignee by name, the linked records by their labels, dates as
 * Y-m-d H:i. Free-text cells are protected against spreadsheet formula
 * injection.
 */
final class TaskExporter extends Exporter
{
    protected static ?string $model = Task::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('exports.columns.task.id')),
            ExportColumn::make('title')->label(__('exports.columns.task.title'))->preventFormulaInjection(),
            ExportColumn::make('kind')
                ->label(__('exports.columns.task.kind'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('status')
                ->label(__('exports.columns.task.status'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('priority')
                ->label(__('exports.columns.task.priority'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('due_at')
                ->label(__('exports.columns.task.due_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('starts_at')
                ->label(__('exports.columns.task.starts_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('ends_at')
                ->label(__('exports.columns.task.ends_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('completed_at')
                ->label(__('exports.columns.task.completed_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
            ExportColumn::make('assignee.name')->label(__('exports.columns.task.assignee')),
            ExportColumn::make('related')
                ->label(__('exports.columns.task.related'))
                ->state(fn (Task $record): ?string => $record->subjectLabel())
                ->preventFormulaInjection(),
            ExportColumn::make('lead.full_name')
                ->label(__('exports.columns.task.lead'))
                ->state(fn (Task $record): ?string => $record->lead?->full_name)
                ->preventFormulaInjection(),
            ExportColumn::make('contact.full_name')
                ->label(__('exports.columns.task.contact'))
                ->state(fn (Task $record): ?string => $record->contact?->full_name)
                ->preventFormulaInjection(),
            ExportColumn::make('account.name')->label(__('exports.columns.task.account'))->preventFormulaInjection(),
            ExportColumn::make('deal.title')->label(__('exports.columns.task.deal'))->preventFormulaInjection(),
            ExportColumn::make('recurrence_frequency')
                ->label(__('exports.columns.task.recurrence_frequency'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state)),
            ExportColumn::make('description')->label(__('exports.columns.task.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.task.created_at'))
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
