<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TaskActions;
use App\Filament\Support\TaskVisuals;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\TaskChecklist;
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
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * The task listings (decision A-10), in two entry points (D-27):
 *
 * - configure() — the Tasks workspace's task list (TaskResource). The D-27
 *   screens build here: grouping, the completion check, the status pill,
 *   the relative dates (TaskVisuals, TaskActions' quick controls).
 * - configureForSubjectTab() — the task tab on a lead, contact, account or
 *   deal page (BaseTasksRelationManager). The owner keeps the Sales side as
 *   it is (D-27): its columns, filters and actions stay exactly these,
 *   pinned by SalesTaskTabsRegressionTest. The subject is the owner record,
 *   so the subject column and filter are left out and the manager supplies
 *   its own record actions (modal edit through TaskService).
 *
 * Both start from classic(), the listing as it stood before D-27; change
 * the workspace list by changing configure(), never classic().
 */
final class TasksTable
{
    /** The statuses in the order the workspace list's groups read (D-27): the work in hand first, the closed work last. */
    public const array STATUS_ORDER = [
        TaskStatus::InProgress,
        TaskStatus::Pending,
        TaskStatus::Completed,
        TaskStatus::Cancelled,
    ];

    /**
     * The Tasks workspace's task list (TaskResource::table(), D-27): grouped
     * by status — In progress first, then Pending, then the closed groups,
     * which start collapsed — each row with a one-click completion check
     * (the D-25 warning first when checklist items are open; «تراجع» in the
     * toast), the status pill that changes status in place (only the moves
     * the reader's policy allows), the title, the due date in relative words
     * (a click reschedules, for those who may edit the dates), the priority
     * flag, who holds the task (TaskVisuals::assignees(), D-28 — the
     * colleagues sharing it as «+N») and the checklist meter. «مهامي», the
     * «مهامي فقط» and assignee filters match anyone holding a task, and «أسندتُها
     * أنا» every task the reader handed to someone, first or added later.
     * Every control is a TaskActions action: authorised by TaskPolicy,
     * written by TaskService.
     *
     * Phone budget: the check, the title, the pill and the due date stay at
     * every width; the flag, the meter and the assignee step in from `md`.
     * The kind, the related record and the repeat mark are one muted line
     * under the title (metaLine()), not columns: with them as columns the
     * title was squeezed to a word per line on a wide screen (the owner's
     * screenshot, 2026-10-07). The title keeps a minimum width (theme.css,
     * .crm-ta-col-title) and wraps beyond it.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('completion_check')
                    ->label(__('tasks.list_ux.columns.check'))
                    ->state(static fn (Task $record): string => $record->status->value)
                    ->formatStateUsing(static fn (mixed $state, Task $record): HtmlString => self::completionCheck($record))
                    ->action(TaskActions::quickComplete())
                    ->disabledClick(static fn (Task $record): bool => ! TaskActions::mayComplete($record)),

                TextColumn::make('title')
                    ->label(__('tasks.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->wrap()
                    // Never squeezed to a word per line by the columns beside it.
                    ->extraHeaderAttributes(['class' => 'crm-ta-col-title'])
                    ->extraCellAttributes(['class' => 'crm-ta-col-title'])
                    // ClickUp keeps the list to one line of facts per task: the kind, the
                    // related record and the repeat mark sit under the title instead of
                    // three more columns (the description is on the task page).
                    ->description(fn (Task $record): string => self::metaLine($record)),

                TaskVisuals::statusColumn(TextColumn::make('status'))
                    ->label(__('tasks.fields.status'))
                    // The cell is a button when the reader may move the task: its name says what it does.
                    ->formatStateUsing(static fn (mixed $state, Task $record): HtmlString|string => TaskActions::transitionsFor($record) === []
                        ? $record->status->getLabel()
                        : TaskVisuals::statusPillName($record))
                    ->tooltip(static fn (Task $record): ?string => TaskActions::transitionsFor($record) === [] ? null : __('tasks.actions.change_status'))
                    ->action(TaskActions::changeStatus())
                    ->disabledClick(static fn (Task $record): bool => TaskActions::transitionsFor($record) === [])
                    ->sortable(),

                TaskVisuals::dueColumn(TextColumn::make('due_at'))
                    // The short header: a relative date («غداً») needs no «تاريخ الاستحقاق» above it.
                    ->label(__('tasks.list_ux.columns.due'))
                    ->action(TaskActions::reschedule())
                    ->disabledClick(static fn (Task $record): bool => ! TaskActions::mayReschedule($record))
                    ->sortable(),

                // Below 2xl the flag and the avatar stand alone, as in ClickUp's list: their
                // words stay for screen readers and in the tooltip (theme.css, .crm-ta-compact),
                // so a laptop-width list keeps the title readable without sideways scrolling.
                TaskVisuals::priorityColumn(TextColumn::make('priority'))
                    ->label(__('tasks.fields.priority'))
                    ->tooltip(static fn (Task $record): string => $record->priority->getLabel())
                    ->extraCellAttributes(['class' => 'crm-ta-compact'])
                    ->sortable()
                    ->visibleFrom('md'),

                TaskVisuals::checklistColumn(TextColumn::make('checklist_progress'))
                    ->label(__('tasks.fields.checklist'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->visibleFrom('md'),

                // Who holds the task (D-28): avatars, the first assignee's name and «+N»; below
                // 2xl the first avatar and the chip stand alone, every name in the tooltip.
                // Sorted by the first assignee; a search finds a task by anyone holding it.
                TaskVisuals::assigneeColumn(TextColumn::make('assignee.name'))
                    ->label(__('tasks.fields.assignees'))
                    ->extraCellAttributes(['class' => 'crm-ta-compact'])
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable()
                    ->searchable(query: static fn (Builder $query, string $search): Builder => self::searchAssignees($query, $search))
                    ->visibleFrom('md'),

                TextColumn::make('created_at')
                    ->label(__('tasks.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // The checklist counts ride on the table query, not on the
            // resource's base query (the calendar and the tab badges share
            // it, and it stays a plain `select * from tasks`).
            ->modifyQueryUsing(fn (Builder $query): Builder => TaskChecklist::withCounts($query))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('due_at')->orderBy('id'))
            ->groups([
                Group::make('status')
                    ->label(__('tasks.list_ux.groups.status'))
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
                    ->orderQueryUsing(static fn (Builder $query, string $direction): Builder => self::orderByStatus($query, $direction)),
            ])
            ->defaultGroup('status')
            ->groupingSettingsInDropdownOnDesktop()
            // The closed groups (Completed, Cancelled) start collapsed: the
            // table's own Alpine state keeps the collapsed group titles, so
            // they are seeded once when the table first renders.
            ->extraAttributes(['x-init' => self::collapseClosedGroupsScript()])
            ->filters([
                // The tasks the reader holds, first assignee or sharing them (D-28).
                Filter::make('mine')
                    ->label(__('tasks.filters.mine'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => self::assignedTo($query, (int) auth()->id())),

                // The tasks the reader handed out to someone else — My Work's «أسندتُها» view (D-27 amendment).
                Filter::make('handed_out_by_me')
                    ->label(__('tasks.filters.handed_out_by_me'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => self::handedOutBy($query, (int) auth()->id())),

                Filter::make('no_due')
                    ->label(__('tasks.filters.no_due'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->whereNull('tasks.due_at')),

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

                // Anyone holding the task, first assignee or sharing it (D-28).
                SelectFilter::make('assignee_id')
                    ->label(__('tasks.filters.assignee'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Task::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->query(static fn (Builder $query, array $data): Builder => is_numeric($data['value'] ?? null)
                        ? self::assignedTo($query, (int) $data['value'])
                        : $query)
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
            // One three-dot menu per row; every action keeps its own
            // authorisation, and the menu hides itself when the policy
            // refuses every action in it. The quick controls live on the
            // cells (check, pill, due date), so the menu keeps the dialogs.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    TaskActions::start(),
                    TaskActions::postUpdate(),
                    TaskActions::checklist(),
                    TaskActions::comment(),
                    TaskActions::complete(),
                    TaskActions::cancel(),
                    TaskActions::reopen(),
                    OwnershipActions::assign(Task::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ImportExportActions::export(TaskExporter::class, Task::class),
                BulkActionGroup::make([
                    ImportExportActions::exportBulk(TaskExporter::class, Task::class),
                    TaskActions::completeBulk(),
                    OwnershipActions::assignBulk(Task::permissionGroup()),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(static fn (HasTable $livewire): string => __('tasks.list_ux.empty.'.self::emptyStateKey($livewire).'.heading'))
            // One next step: quick add for whoever may add tasks; for the others (no quick add) the words point elsewhere.
            ->emptyStateDescription(static fn (HasTable $livewire): string => __('tasks.list_ux.empty.'.self::emptyStateKey($livewire).'.'
                .((auth()->user()?->can('create', Task::class) ?? false) ? 'description' : 'description_view')))
            ->emptyStateActions([
                TaskActions::quickAdd(),
            ]);
    }

    /** The task tab on a Sales record page (BaseTasksRelationManager) — frozen by D-27. */
    public static function configureForSubjectTab(Table $table): Table
    {
        return self::classic($table, withSubject: false);
    }

    /** The listing as both entry points had it before D-27; the Sales tab depends on it unchanged. */
    private static function classic(Table $table, bool $withSubject): Table
    {
        return $table
            // Phone budget: title, status and due date stay at every width;
            // kind, priority, the checklist progress and the recurrence marker step in from `md`, the
            // assignee and the related record from `lg`. CSS breakpoints only —
            // the cells stay in the DOM.
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
                    ->color('gray')
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->label(__('tasks.fields.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('priority')
                    ->label(__('tasks.fields.priority'))
                    ->badge()
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('due_at')
                    ->label(__('tasks.fields.due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->color(fn (Task $record): ?string => TaskInfolist::dueColor($record))
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label(__('tasks.fields.assignee'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable()
                    ->visibleFrom('lg'),

                ...$withSubject ? [
                    TextColumn::make('subject_record')
                        ->label(__('tasks.fields.related'))
                        ->state(fn (Task $record): ?string => $record->subjectLabel())
                        ->url(function (Task $record): ?string {
                            $subject = $record->subjectRecord();

                            return $subject === null ? null : TaskResource::urlForSubject($subject);
                        })
                        ->placeholder(__('common.placeholders.empty'))
                        ->visibleFrom('lg'),
                ] : [],

                // Checklist progress (D-25), "done/total", from the counts the
                // query below adds — no query per row.
                LtrText::column(
                    TextColumn::make('checklist_progress')
                        ->label(__('tasks.fields.checklist'))
                        ->state(fn (Task $record): ?string => TaskChecklist::progressLabel($record))
                        ->color(fn (Task $record): ?string => self::checklistColor($record))
                        ->placeholder(__('common.placeholders.empty'))
                        ->visibleFrom('md'),
                ),

                IconColumn::make('recurrence_frequency')
                    ->label(__('tasks.fields.recurrence_frequency'))
                    ->icon(fn (Task $record): ?Heroicon => $record->repeats() ? Heroicon::OutlinedArrowPath : null)
                    ->tooltip(fn (Task $record): ?string => $record->repeats() ? TaskInfolist::recurrenceSummary($record) : null)
                    ->color('gray')
                    ->visibleFrom('md'),

                TextColumn::make('created_at')
                    ->label(__('tasks.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // The checklist counts ride on the table query, not on the
            // resource's base query, which the calendar and the tab badges
            // share and which stays a plain `select * from tasks`.
            ->modifyQueryUsing(fn (Builder $query): Builder => TaskChecklist::withCounts($query))
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
                    TaskActions::start(),
                    TaskActions::postUpdate(),
                    TaskActions::checklist(),
                    TaskActions::comment(),
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

    /** Success once every item is ticked, nothing otherwise. */
    private static function checklistColor(Task $record): ?string
    {
        ['done' => $done, 'total' => $total] = TaskChecklist::counts($record);

        return $total > 0 && $done === $total ? 'success' : null;
    }

    /* ---------------------------------------------------------------------
     | The workspace list (D-27)
     * ------------------------------------------------------------------- */

    /**
     * The tasks the user holds, as the first assignee or sharing them
     * (D-28) — Task::assignedTo(), the one meaning of "assigned to".
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private static function assignedTo(Builder $query, int $userId): Builder
    {
        return $query->assignedTo($userId);
    }

    /**
     * The tasks the user handed out to someone else (D-27 amendment): those
     * they gave a first assignee other than themselves, and those they added
     * a colleague to (D-28, the co-assignee row remembers who added them).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private static function handedOutBy(Builder $query, int $userId): Builder
    {
        return $query->where(static function (Builder $handed) use ($userId): void {
            $handed
                ->where(static function (Builder $first) use ($userId): void {
                    $first->where('tasks.assigned_by', $userId)
                        ->whereNotNull('tasks.assignee_id')
                        ->where('tasks.assignee_id', '!=', $userId);
                })
                ->orWhereIn('tasks.id', TaskCoAssignee::query()
                    ->select('task_id')
                    ->where('assigned_by', $userId)
                    ->where('user_id', '!=', $userId));
        });
    }

    /**
     * The list's search over who holds a task (D-28): the first assignee's
     * name or the name of a colleague sharing it.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private static function searchAssignees(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(static function (Builder $holders) use ($like): void {
            $holders
                ->whereHas('assignee', static fn (Builder $user): Builder => $user->where('name', 'like', $like))
                ->orWhereHas('coAssignees', static fn (Builder $user): Builder => $user->where('users.name', 'like', $like));
        });
    }

    /** The row's completion check (TaskVisuals::completionCheck), a button only when the reader may complete the task now. */
    public static function completionCheck(Task $record): HtmlString
    {
        return TaskVisuals::completionCheck($record, TaskActions::mayComplete($record));
    }

    /**
     * The muted line under a task's title: its kind, the record it is about
     * and, for a repeating task, how often it repeats — «متابعة · سلطان الشهري
     * · يتكرر أسبوعياً». Plain text (escaped by the column); the record's link
     * is on the task page, where PanelUrl decides whether the reader may follow it.
     */
    private static function metaLine(Task $record): string
    {
        $parts = [$record->kind->getLabel()];

        if (filled($subject = $record->subjectLabel())) {
            $parts[] = $subject;
        }

        if ($record->repeats()) {
            $parts[] = __('tasks.list_ux.meta.repeats', ['frequency' => $record->recurrence_frequency->getLabel()]);
        }

        return implode(' · ', $parts);
    }

    /**
     * Orders the status groups by STATUS_ORDER rather than by the stored
     * value (which would read cancelled, completed, in progress, pending).
     * The values are bound, never spliced into the SQL.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private static function orderByStatus(Builder $query, string $direction): Builder
    {
        $cases = implode(' ', array_fill(0, count(self::STATUS_ORDER), 'WHEN ? THEN ?'));
        $bindings = [];

        foreach (self::STATUS_ORDER as $position => $status) {
            $bindings[] = $status->value;
            $bindings[] = $position;
        }

        return $query->orderByRaw(
            "CASE `tasks`.`status` {$cases} ELSE ".count(self::STATUS_ORDER).' END '.(strtolower($direction) === 'desc' ? 'desc' : 'asc'),
            $bindings,
        );
    }

    /**
     * Seeds the table's collapsed groups with the closed statuses' titles
     * (the group titles are the TaskStatus labels), once, on first render.
     */
    private static function collapseClosedGroupsScript(): string
    {
        $titles = [TaskStatus::Completed->getLabel(), TaskStatus::Cancelled->getLabel()];

        return 'groupVisibility.push(...'.Js::from($titles)->toHtml().')';
    }

    /** Which friendly empty state the list shows: one per tab, a generic one for a search or filter that found nothing. */
    private static function emptyStateKey(HasTable $livewire): string
    {
        if ($livewire->hasTableSearch() || $livewire->getTable()->isFiltered()) {
            return 'filtered';
        }

        $tab = $livewire instanceof ListTasks ? $livewire->activeTab : null;

        return is_string($tab) && in_array($tab, ListTasks::TABS, true) ? $tab : 'all';
    }
}
