<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The task listing (decision A-10), shared by the resource and the relation
 * managers on the four subject records. On a relation manager the subject
 * is the owner record, so the subject column and filter are left out and
 * the manager supplies its own record actions (modal edit through
 * TaskService).
 */
final class TasksTable
{
    public static function configure(Table $table, bool $withSubject = true): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('tasks.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Task $record): ?string => $record->description === null ? null : Str::limit(Str::squish($record->description), 60)),

                TextColumn::make('kind')
                    ->label(__('tasks.fields.kind'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label(__('tasks.fields.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('priority')
                    ->label(__('tasks.fields.priority'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('due_at')
                    ->label(__('tasks.fields.due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->color(fn (Task $record): ?string => TaskInfolist::dueColor($record))
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label(__('tasks.fields.assignee'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),

                ...$withSubject ? [
                    TextColumn::make('subject_record')
                        ->label(__('tasks.fields.related'))
                        ->state(fn (Task $record): ?string => $record->subjectLabel())
                        ->url(function (Task $record): ?string {
                            $subject = $record->subjectRecord();

                            return $subject === null ? null : TaskResource::urlForSubject($subject);
                        })
                        ->placeholder(__('common.placeholders.empty')),
                ] : [],

                IconColumn::make('recurrence_frequency')
                    ->label(__('tasks.fields.recurrence_frequency'))
                    ->icon(fn (Task $record): ?Heroicon => $record->repeats() ? Heroicon::OutlinedArrowPath : null)
                    ->tooltip(fn (Task $record): ?string => $record->repeats() ? TaskInfolist::recurrenceSummary($record) : null)
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label(__('tasks.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('due_at')->orderBy('id'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tasks.filters.status'))
                    ->options(TaskStatus::class)
                    ->multiple(),

                SelectFilter::make('priority')
                    ->label(__('tasks.filters.priority'))
                    ->options(TaskPriority::class)
                    ->multiple(),

                SelectFilter::make('kind')
                    ->label(__('tasks.filters.kind'))
                    ->options(TaskKind::class)
                    ->multiple(),

                SelectFilter::make('assignee_id')
                    ->label(__('tasks.filters.assignee'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Task::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                Filter::make('due_at')
                    ->schema([
                        DatePicker::make('from')->label(__('tasks.filters.due_from'))->native(false),
                        DatePicker::make('until')->label(__('tasks.filters.due_until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('due_at', '>=', app(SettingsRepository::class)->startOfOrganisationDay((string) $data['from'])))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('due_at', '<=', app(SettingsRepository::class)->endOfOrganisationDay((string) $data['until']))))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('tasks.filters.due_from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('tasks.filters.due_until').': '.$data['until'] : null,
                    ])),

                TrashedFilter::make()->label(__('tasks.filters.trashed')),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    TaskActions::complete(),
                    TaskActions::cancel(),
                    TaskActions::reopen(),
                    OwnershipActions::assign(Task::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ...$withSubject ? [ImportExportActions::export(TaskExporter::class, Task::class)] : [],
                BulkActionGroup::make([
                    ...$withSubject ? [ImportExportActions::exportBulk(TaskExporter::class, Task::class)] : [],
                    TaskActions::completeBulk(),
                    OwnershipActions::assignBulk(Task::permissionGroup()),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('tasks.empty.heading'))
            ->emptyStateDescription(__('tasks.empty.description'));
    }
}
