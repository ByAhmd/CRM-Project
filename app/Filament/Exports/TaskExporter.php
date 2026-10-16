<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Support\ImportExportActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskChecklist;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV / XLSX export of tasks (module 18, decisions A-10, D-13).
 *
 * Exports the rows of the table query it is launched from — already inside
 * the actor's visible scope — with enums as labels in the actor's locale,
 * the assignee by name — and, for a shared task, the colleagues sharing it
 * in a column of their own (D-28) — the linked records by their labels, dates as
 * Y-m-d H:i, the checklist progress as "done of total" (D-25) — in words,
 * since a spreadsheet opening the CSV turns a bare 2/3 into a date. All
 * text cells are protected against spreadsheet formula injection.
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
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
            ExportColumn::make('status')
                ->label(__('exports.columns.task.status'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
            ExportColumn::make('priority')
                ->label(__('exports.columns.task.priority'))
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
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
            ExportColumn::make('assignee.name')->label(__('exports.columns.task.assignee'))->preventFormulaInjection(),
            ExportColumn::make('co_assignees')
                ->label(__('exports.columns.task.co_assignees'))
                ->state(fn (Task $record): ?string => self::coAssigneeNames($record))
                ->preventFormulaInjection(),
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
                ->formatStateUsing(fn (mixed $state): ?string => ImportExportActions::enumLabel($state))
                ->preventFormulaInjection(),
            ExportColumn::make('checklist')
                ->label(__('exports.columns.task.checklist'))
                ->state(fn (Task $record): ?string => self::checklistProgress($record)),
            ExportColumn::make('description')->label(__('exports.columns.task.description'))->preventFormulaInjection(),
            ExportColumn::make('created_at')
                ->label(__('exports.columns.task.created_at'))
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => ImportExportActions::dateTime($state)),
        ];
    }

    /**
     * "2 of 3" — empty for a task without a checklist.
     */
    private static function checklistProgress(Task $record): ?string
    {
        ['done' => $done, 'total' => $total] = TaskChecklist::counts($record);

        return $total === 0 ? null : __('exports.values.checklist_progress', ['done' => $done, 'total' => $total]);
    }

    /**
     * The colleagues sharing the task beside its first assignee (D-28), in
     * the order they were added — empty for a task held by one person. Read
     * from the co-assignees the chunk loaded (modifyQuery()).
     */
    private static function coAssigneeNames(Task $record): ?string
    {
        $names = $record->coAssignees
            ->reject(static fn (User $user): bool => $record->assignee_id !== null && (int) $user->getKey() === (int) $record->assignee_id)
            ->map(static fn (User $user): string => $user->name)
            ->all();

        return $names === [] ? null : implode(__('exports.values.list_separator'), $names);
    }

    /**
     * The checklist progress (D-25) is counted with the chunk instead of
     * once per row, and the co-assignees (D-28) are loaded with it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with('coAssignees')
            ->withCount([
                'checklistItems as '.TaskChecklist::TOTAL_ALIAS,
                'checklistItems as '.TaskChecklist::DONE_ALIAS => static fn (Builder $items): Builder => $items->whereNotNull('completed_at'),
            ]);
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

        $body = trans_choice('exports.notifications.completed', (int) $export->successful_rows);
        $failed = $export->getFailedRowsCount();

        return $failed > 0
            ? $body.' '.trans_choice('exports.notifications.failed', $failed)
            : $body;
    }
}
