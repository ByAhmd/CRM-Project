<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\Tables;

use App\Enums\RoutineFrequency;
use App\Filament\Resources\TaskTemplates\TaskTemplateActions;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The routine task listing (decision D-22): what each routine hands out,
 * when, to whom, and in whose name.
 */
final class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Phone budget: title, schedule, next run and the active switch
            // stay at every width; the assignees step in from `md`, the
            // assigner from `lg`. CSS breakpoints only — the cells stay in
            // the DOM.
            ->columns([
                TextColumn::make('title')
                    ->label(__('task_templates.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (TaskTemplate $record): ?string => $record->description === null ? null : Str::limit(Str::squish($record->description), 60)),

                TextColumn::make('schedule')
                    ->label(__('task_templates.fields.schedule'))
                    ->state(fn (TaskTemplate $record): string => self::scheduleSummary($record)),

                // The run day is a calendar day of the organisation, shown as
                // stored: a date-time formatter would shift it by the
                // timezone difference.
                TextColumn::make('next_run_on')
                    ->label(__('task_templates.fields.next_run_on'))
                    ->state(fn (TaskTemplate $record): ?string => $record->next_run_on?->toDateString())
                    ->placeholder(__('task_templates.placeholders.paused'))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('task_templates.fields.is_active'))
                    ->boolean(),

                TextColumn::make('assignees.name')
                    ->label(__('task_templates.fields.assignees'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->visibleFrom('md'),

                TextColumn::make('assigner.name')
                    ->label(__('task_templates.fields.assigned_by'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->visibleFrom('lg'),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('title')->orderBy('id'))
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('task_templates.filters.is_active')),

                SelectFilter::make('frequency')
                    ->label(__('task_templates.filters.frequency'))
                    ->options(RoutineFrequency::class),

                SelectFilter::make('assignee')
                    ->label(__('task_templates.filters.assignee'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Task::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('assignees', fn (Builder $assignees): Builder => $assignees->where('users.id', (int) $data['value'])))
                    ->searchable(),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    TaskTemplateActions::pause(),
                    TaskTemplateActions::resume(),
                    TaskTemplateActions::delete(),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    TaskTemplateActions::deleteBulk(),
                ]),
            ])
            ->emptyStateHeading(__('task_templates.empty.heading'))
            ->emptyStateDescription(__('task_templates.empty.description'));
    }

    /**
     * The schedule in one line: "Every Sunday · due 17:00", "Monthly on day 5
     * · due 2 days later at 09:00" — day names from the language files, the
     * day offset counted with trans_choice.
     */
    public static function scheduleSummary(TaskTemplate $template): string
    {
        $schedule = $template->frequency === RoutineFrequency::Weekly
            ? (string) __('task_templates.summary.weekly.'.(int) $template->weekday)
            : (string) __('task_templates.summary.monthly', ['day' => (int) $template->day_of_month]);

        $due = trans_choice('task_templates.summary.due', $template->due_in_days, [
            'time' => substr($template->due_time, 0, 5),
        ]);

        return (string) __('task_templates.summary.line', ['schedule' => $schedule, 'due' => $due]);
    }
}
