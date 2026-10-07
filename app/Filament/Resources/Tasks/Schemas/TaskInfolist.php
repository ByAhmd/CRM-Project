<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\DueShortcut;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\TaskAttachmentsRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Filament\Support\InitialsAvatar;
use App\Filament\Support\TaskActions;
use App\Filament\Support\TaskVisuals;
use App\Livewire\Tasks\TaskComposer;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateMention;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\TaskChecklist;
use App\Support\Filament\PanelUrl;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * One task (decisions A-10, D-14, D-17, D-20, D-25): what it is, its checklist, how the work is going
 * (the thread of progress entries and comments), when it is due, the records it is linked to, who it is
 * assigned to and by whom, how it repeats and who created it. Links to a
 * linked record, and to the first task of its series, appear only when the
 * reader may open it — an employee (D-15) reads a handed-out task linked to
 * a lead as plain text, never as a link they would be refused.
 */
final class TaskInfolist
{
    /**
     * The task page of the Tasks workspace (TaskResource::infolist(),
     * ViewTask) in two columns (D-27). From `lg`: the content — description,
     * the checklist ticked with one click, the files — beside the properties
     * (status pill with its moves, priority, assignee, assigner, due with the
     * reschedule chips, linked records, routine, more details), and under
     * the content the activity: the composer, the filter and the thread. On
     * a phone the three stack in that order — content, properties, activity
     * — so the composer is reached without scrolling past the whole thread.
     * Every control is one of TaskActions' authorised actions or the page's
     * own (ViewTask); nothing here writes.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->extraAttributes(['class' => 'crm-task-page'])
                    ->schema([
                        Group::make([
                            self::pageContentSection(),
                            self::pageChecklistSection(),
                            self::pageFiles(),
                        ])
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->extraAttributes(['class' => 'crm-task-page__content']),

                        Group::make([
                            self::pagePropertiesSection(),
                            self::pageRelatedSection(),
                            self::pageDetailsSection(),
                        ])
                            ->extraAttributes(['class' => 'crm-task-page__aside']),

                        Group::make([
                            self::pageActivitySection(),
                        ])
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->extraAttributes(['class' => 'crm-task-page__activity']),
                    ]),
            ])
            ->columns(1);
    }

    /** The description: the task's own words, as plain text. */
    private static function pageContentSection(): Section
    {
        return Section::make(__('tasks.fields.description'))
            ->schema([
                TextEntry::make('description')
                    ->label(__('tasks.fields.description'))
                    ->hiddenLabel()
                    ->placeholder(__('common.placeholders.empty'))
                    ->extraAttributes(['class' => 'whitespace-pre-line']),
            ])
            ->columns(1);
    }

    /**
     * The checklist (D-25) with a box per item: ticked with one click by
     * whoever may report on the open task (the page action
     * `tickChecklistItem`, ViewTask), a plain mark for everyone else and on a
     * closed or deleted task. The progress meter sits beside the heading;
     * «تعديل القائمة» opens the edit form for whoever may edit the task (the
     * structure stays there, D-25). Shown only when the task has a checklist.
     */
    private static function pageChecklistSection(): Section
    {
        return Section::make(__('tasks.sections.checklist'))
            ->description(static fn (?Model $record): ?HtmlString => $record instanceof Task ? TaskVisuals::checklistMeterFor($record) : null)
            ->visible(static fn (?Model $record): bool => $record instanceof Task && self::checklistItems($record)->isNotEmpty())
            ->afterHeader([
                Action::make('editChecklist')
                    ->label(__('tasks.page_ux.actions.edit_checklist'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->link()
                    ->url(static fn (?Model $record): ?string => $record instanceof Task ? TaskResource::getUrl('edit', ['record' => $record]) : null)
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
                    ->authorize(static fn (?Model $record): bool => $record instanceof Task && (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->schema([
                ViewEntry::make('page_checklist')
                    ->label(__('tasks.fields.checklist'))
                    ->hiddenLabel()
                    ->view('filament.tasks.page-checklist')
                    ->state(static fn (?Model $record): ?array => $record instanceof Task ? self::pageChecklistState($record) : null),
            ])
            ->columns(1);
    }

    /**
     * What the checklist view draws: the items in order with who ticked
     * them, whether the reader may tick (the policy's `tickChecklist`: an
     * open task, someone who may report on it) and the note for a reader who
     * may not.
     *
     * @return array{items: list<array{id: int, body: string, done: bool, ticked: ?string}>, canTick: bool, note: ?string}
     */
    private static function pageChecklistState(Task $task): array
    {
        $user = auth()->user();
        $canTick = $user instanceof User && ! $task->trashed() && $user->can('tickChecklist', $task);

        $items = [];

        foreach (self::checklistItems($task) as $item) {
            $items[] = [
                'id' => (int) $item->getKey(),
                'body' => (string) $item->body,
                'done' => $item->isDone(),
                'ticked' => $item->isDone() ? self::tickedBy($item) : null,
            ];
        }

        $note = match (true) {
            $canTick => null,
            $task->isClosed() || $task->trashed() => __('tasks.helpers.checklist_frozen'),
            default => __('tasks.page_ux.helpers.checklist_view_only'),
        };

        return ['items' => $items, 'canTick' => $canTick, 'note' => $note];
    }

    /**
     * The task's files (D-15): the attachments relation manager itself, in
     * the content column — gated, as on every page, by the task's `view`
     * (ViewTask keeps it out of the list below the page).
     */
    private static function pageFiles(): LivewireComponent
    {
        return LivewireComponent::make(
            TaskAttachmentsRelationManager::class,
            static fn (?Model $record): array => ['ownerRecord' => $record, 'pageClass' => ViewTask::class],
        )
            ->key('taskFiles')
            ->visible(static fn (?Model $record): bool => $record instanceof Task && TaskAttachmentsRelationManager::canViewForRecord($record, ViewTask::class));
    }

    /**
     * The properties: the status as a pill that opens the moves the reader
     * may make (TaskActions::statusMenu(); a plain pill for a reader with
     * none), the priority flag, who the task is assigned to and by whom, and
     * the due date in relative words with the reschedule chips for whoever
     * may edit the task (D-14: never the handed-out assignee).
     */
    private static function pagePropertiesSection(): Section
    {
        return Section::make(__('tasks.page_ux.sections.properties'))
            ->schema([
                // An ActionGroup, unlike an Action, never falls back to the schema's
                // record, so its label, colour and icon would read the generic
                // «الحالة»: hand it the page's task explicitly.
                Actions::make([
                    TaskActions::statusMenu()
                        ->record(static fn (?Actions $schemaComponent): ?Model => $schemaComponent?->getRecord()),
                ])
                    ->key('statusControl')
                    ->label(__('tasks.fields.status'))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && TaskActions::transitionsFor($record) !== []),

                TaskVisuals::statusEntry(TextEntry::make('status'))
                    ->label(__('tasks.fields.status'))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && TaskActions::transitionsFor($record) === []),

                TaskVisuals::priorityEntry(TextEntry::make('priority'))
                    ->label(__('tasks.fields.priority')),

                TextEntry::make('assignee.name')
                    ->label(__('tasks.fields.assignee'))
                    ->formatStateUsing(static fn (?string $state): string|HtmlString => self::person($state))
                    ->placeholder(__('assignment.placeholders.unassigned')),

                TextEntry::make('assigner.name')
                    ->label(__('tasks.fields.assigned_by'))
                    ->formatStateUsing(static fn (?string $state): string|HtmlString => self::person($state))
                    ->placeholder(__('common.placeholders.empty')),

                TaskVisuals::dueEntry(TextEntry::make('due_at'))
                    ->label(__('tasks.fields.due_at')),

                Actions::make([
                    TaskActions::rescheduleTo(DueShortcut::Today)->size(Size::Small),
                    TaskActions::rescheduleTo(DueShortcut::Tomorrow)->size(Size::Small),
                    TaskActions::rescheduleTo(DueShortcut::NextWeek)->size(Size::Small),
                    TaskActions::reschedulePick()->size(Size::Small),
                ])
                    ->key('rescheduleChips')
                    ->label(__('tasks.actions.reschedule')),
            ])
            ->columns(1);
    }

    /**
     * Where the task belongs: the linked lead, contact, account or deal
     * (links only where the reader may open the record and enter its
     * workspace, D-23 — plain text otherwise), the routine it came from
     * (D-22; a link for whoever manages routines), its repetition and the
     * first task of its series. Shown when there is any.
     */
    private static function pageRelatedSection(): Section
    {
        return Section::make(__('tasks.sections.related'))
            ->visible(static fn (?Model $record): bool => $record instanceof Task
                && ($record->linkedRecords() !== [] || $record->template_id !== null || $record->series_id !== null || $record->repeats()))
            ->schema([
                TextEntry::make('deal.title')
                    ->label(__('tasks.fields.deal'))
                    ->url(static fn (Task $record): ?string => $record->deal === null ? null : TaskResource::urlForSubject($record->deal))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->deal !== null),
                TextEntry::make('lead.full_name')
                    ->label(__('tasks.fields.lead'))
                    ->state(static fn (Task $record): ?string => $record->lead?->full_name)
                    ->url(static fn (Task $record): ?string => $record->lead === null ? null : TaskResource::urlForSubject($record->lead))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->lead !== null),
                TextEntry::make('contact.full_name')
                    ->label(__('tasks.fields.contact'))
                    ->state(static fn (Task $record): ?string => $record->contact?->full_name)
                    ->url(static fn (Task $record): ?string => $record->contact === null ? null : TaskResource::urlForSubject($record->contact))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->contact !== null),
                TextEntry::make('account.name')
                    ->label(__('tasks.fields.account'))
                    ->url(static fn (Task $record): ?string => $record->account === null ? null : TaskResource::urlForSubject($record->account))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->account !== null),
                TextEntry::make('template.title')
                    ->label(__('tasks.page_ux.fields.routine'))
                    ->url(static fn (Task $record): ?string => self::routineUrl($record))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->template !== null),
                TextEntry::make('recurrence_summary')
                    ->label(__('tasks.fields.recurrence_frequency'))
                    ->state(static fn (Task $record): string => self::recurrenceSummary($record))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->repeats()),
                TextEntry::make('series.title')
                    ->label(__('tasks.fields.series'))
                    ->url(static fn (Task $record): ?string => self::seriesUrl($record))
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->series !== null),
            ])
            ->columns(1);
    }

    /** The rest, folded away: kind, the other moments, who created the task. Short values pair from `lg`. */
    private static function pageDetailsSection(): Section
    {
        return Section::make(__('tasks.page_ux.sections.details'))
            ->schema([
                TextEntry::make('kind')
                    ->label(__('tasks.fields.kind'))
                    ->badge()
                    ->color('gray'),
                TextEntry::make('reminder_at')
                    ->label(__('tasks.fields.reminder_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
                TextEntry::make('starts_at')
                    ->label(__('tasks.fields.starts_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
                TextEntry::make('ends_at')
                    ->label(__('tasks.fields.ends_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
                TextEntry::make('completed_at')
                    ->label(__('tasks.fields.completed_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
                TextEntry::make('creator.name')
                    ->label(__('tasks.fields.created_by'))
                    ->placeholder(__('common.placeholders.empty')),
                TextEntry::make('created_at')
                    ->label(__('tasks.fields.created_at'))
                    ->dateTime('Y-m-d H:i'),
                TextEntry::make('updated_at')
                    ->label(__('tasks.fields.updated_at'))
                    ->dateTime('Y-m-d H:i'),
            ])
            ->columns(['default' => 1, 'lg' => 2])
            ->collapsible()
            ->collapsed();
    }

    /**
     * The activity: the composer (App\Livewire\Tasks\TaskComposer — a
     * comment for everyone who may comment, a progress update for whoever
     * may report), the filter (all · comments · progress updates, the
     * reader's choice on ViewTask) and the thread newest first (D-17), the
     * names a comment mentions highlighted (D-20). The thread is the one
     * loaded with the record; the filter only picks from it.
     */
    private static function pageActivitySection(): Section
    {
        return Section::make(__('tasks.sections.thread'))
            ->description(static function (?Model $record): ?string {
                $user = auth()->user();

                return $record instanceof Task && $user instanceof User && $user->can('progress', $record) && ! $user->can('update', $record)
                    ? __('tasks.helpers.handed_out')
                    : null;
            })
            ->schema([
                LivewireComponent::make(TaskComposer::class)
                    ->key('taskComposer')
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && (auth()->user()?->can('comment', $record) ?? false)),

                View::make('filament.tasks.thread-filter')
                    ->viewData(static fn (mixed $livewire): array => [
                        'current' => $livewire instanceof ViewTask ? $livewire->threadFilter : ViewTask::THREAD_ALL,
                        'filters' => [
                            ViewTask::THREAD_ALL => __('tasks.page_ux.filters.all'),
                            ViewTask::THREAD_COMMENTS => __('tasks.page_ux.filters.comments'),
                            ViewTask::THREAD_UPDATES => __('tasks.page_ux.filters.updates'),
                        ],
                    ]),

                RepeatableEntry::make('pageThread')
                    // Hidden on screen, still announced: never Filament's English headline of the name.
                    ->label(__('tasks.sections.thread'))
                    ->hiddenLabel()
                    ->state(static fn (?Model $record, mixed $livewire): ?Collection => $record instanceof Task
                        ? self::filteredThread($record, $livewire instanceof ViewTask ? $livewire->threadFilter : ViewTask::THREAD_ALL)
                        : null)
                    ->placeholder(static fn (mixed $livewire): string => match ($livewire instanceof ViewTask ? $livewire->threadFilter : ViewTask::THREAD_ALL) {
                        ViewTask::THREAD_COMMENTS => __('tasks.page_ux.empty.thread_comments'),
                        ViewTask::THREAD_UPDATES => __('tasks.page_ux.empty.thread_updates'),
                        default => __('tasks.empty.thread'),
                    })
                    ->schema([
                        Grid::make(['default' => 2, 'lg' => 4])->schema([
                            TextEntry::make('kind')
                                ->label(__('tasks.fields.update_kind'))
                                ->badge(),
                            TextEntry::make('author_name')
                                ->label(__('tasks.fields.update_author'))
                                ->state(static fn (?Model $record): ?string => $record instanceof TaskUpdate ? self::authorName($record) : null)
                                ->formatStateUsing(static fn (?string $state, ?Model $record): string|HtmlString => $record instanceof TaskUpdate && $record->author !== null ? self::person($state) : (string) $state),
                            TextEntry::make('created_at')
                                ->label(__('tasks.fields.update_created_at'))
                                ->dateTime('Y-m-d H:i'),
                            TaskVisuals::statusEntry(TextEntry::make('status'))
                                ->label(__('tasks.fields.update_status'))
                                ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && $record->status !== null),
                        ]),
                        TextEntry::make('body')
                            ->label(static fn (?Model $record): string => $record instanceof TaskUpdate && $record->isComment() ? __('tasks.fields.comment_body') : __('tasks.fields.update_body'))
                            ->formatStateUsing(static fn (?string $state, ?Model $record): string|HtmlString => $record instanceof TaskUpdate ? self::body($record) : (string) $state)
                            ->extraAttributes(['class' => 'whitespace-pre-line'])
                            ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && filled($record->body))
                            ->columnSpanFull(),
                    ])
                    ->extraAttributes(['class' => 'crm-thread']),
            ])
            ->columns(1);
    }

    /**
     * The loaded thread, newest first, narrowed to comments or to progress
     * entries (a start or a completion is progress too) — in PHP, so the
     * filter costs no query.
     *
     * @return Collection<int, TaskUpdate>
     */
    private static function filteredThread(Task $task, string $filter): Collection
    {
        $updates = $task->loadMissing(['updates.author', 'updates.mentions.user'])->updates;

        return match ($filter) {
            ViewTask::THREAD_COMMENTS => $updates->filter(static fn (TaskUpdate $update): bool => $update->isComment())->values(),
            ViewTask::THREAD_UPDATES => $updates->reject(static fn (TaskUpdate $update): bool => $update->isComment())->values(),
            default => $updates,
        };
    }

    /** A person with their initials avatar (decorative: the name beside it is read). */
    private static function person(?string $name): string|HtmlString
    {
        if (blank($name)) {
            return '';
        }

        $avatar = InitialsAvatar::forName($name);

        if ($avatar === null) {
            return $name;
        }

        return new HtmlString('<span class="crm-person">'.$avatar->toHtml().'<span class="crm-person__name">'.e($name).'</span></span>');
    }

    /**
     * The routine's edit page (D-22) for whoever may manage routines and
     * enter its workspace (D-23); plain text for everyone else.
     */
    private static function routineUrl(Task $record): ?string
    {
        $template = $record->template;
        $user = auth()->user();

        if ($template === null || ! $user instanceof User || ! $user->can('update', $template)) {
            return null;
        }

        return PanelUrl::forReader($user, TaskTemplateResource::class, static fn (): string => TaskTemplateResource::getUrl('edit', ['record' => $template]));
    }

    /**
     * The task modal of a lead, contact, account or deal page
     * (BaseTasksRelationManager): the owner keeps the Sales side as it is
     * (D-27), so it stays the classic layout, pinned by
     * SalesTaskTabsRegressionTest. Change the workspace page through
     * configure(), never classicComponents() or the sections it builds.
     */
    public static function configureForSubjectTab(Schema $schema): Schema
    {
        return $schema
            ->components(self::classicComponents())
            ->columns(1);
    }

    /**
     * The layout both entry points had before D-27.
     *
     * @return list<Component>
     */
    private static function classicComponents(): array
    {
        return [
            Section::make(__('tasks.sections.details'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')
                            ->label(__('tasks.fields.status'))
                            ->badge(),
                        TextEntry::make('priority')
                            ->label(__('tasks.fields.priority'))
                            ->badge(),
                        TextEntry::make('kind')
                            ->label(__('tasks.fields.kind'))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('assignee.name')
                            ->label(__('tasks.fields.assignee'))
                            ->placeholder(__('assignment.placeholders.unassigned')),
                    ]),
                    TextEntry::make('title')
                        ->label(__('tasks.fields.title'))
                        ->weight('semibold')
                        ->columnSpanFull(),
                    TextEntry::make('description')
                        ->label(__('tasks.fields.description'))
                        ->placeholder(__('common.placeholders.empty'))
                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                        ->columnSpanFull(),
                ])
                ->columns(1),

            self::checklistSection(),

            self::threadSection(),

            Section::make(__('tasks.sections.schedule'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('due_at')
                            ->label(__('tasks.fields.due_at'))
                            ->dateTime('Y-m-d H:i')
                            ->color(fn (Task $record): ?string => self::dueColor($record))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('reminder_at')
                            ->label(__('tasks.fields.reminder_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('starts_at')
                            ->label(__('tasks.fields.starts_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('ends_at')
                            ->label(__('tasks.fields.ends_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('completed_at')
                            ->label(__('tasks.fields.completed_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder(__('common.placeholders.empty')),
                    ]),
                ])
                ->columns(1),

            Section::make(__('tasks.sections.related'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('deal.title')
                            ->label(__('tasks.fields.deal'))
                            ->url(fn (Task $record): ?string => $record->deal === null ? null : TaskResource::urlForSubject($record->deal))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('lead.full_name')
                            ->label(__('tasks.fields.lead'))
                            ->state(fn (Task $record): ?string => $record->lead?->full_name)
                            ->url(fn (Task $record): ?string => $record->lead === null ? null : TaskResource::urlForSubject($record->lead))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('contact.full_name')
                            ->label(__('tasks.fields.contact'))
                            ->state(fn (Task $record): ?string => $record->contact?->full_name)
                            ->url(fn (Task $record): ?string => $record->contact === null ? null : TaskResource::urlForSubject($record->contact))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('account.name')
                            ->label(__('tasks.fields.account'))
                            ->url(fn (Task $record): ?string => $record->account === null ? null : TaskResource::urlForSubject($record->account))
                            ->placeholder(__('common.placeholders.empty')),
                    ]),
                ])
                ->columns(1),

            Section::make(__('tasks.sections.recurrence'))
                ->visible(fn (Task $record): bool => $record->repeats() || $record->series_id !== null)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('recurrence_summary')
                            ->label(__('tasks.fields.recurrence_frequency'))
                            ->state(fn (Task $record): string => self::recurrenceSummary($record)),
                        TextEntry::make('recurrence_ends_at')
                            ->label(__('tasks.fields.recurrence_ends_at'))
                            ->date('Y-m-d')
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('series.title')
                            ->label(__('tasks.fields.series'))
                            ->url(fn (Task $record): ?string => self::seriesUrl($record))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('occurrences_count')
                            ->label(__('tasks.fields.occurrences_count'))
                            ->state(fn (Task $record): int => $record->occurrences()->count()),
                    ]),
                ])
                ->columns(1),

            Section::make(__('tasks.sections.audit'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('activities_count')
                            ->label(__('tasks.fields.activities_count'))
                            ->state(fn (Task $record): int => $record->activities()->count()),
                        TextEntry::make('creator.name')
                            ->label(__('tasks.fields.created_by'))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('assigner.name')
                            ->label(__('tasks.fields.assigned_by'))
                            ->placeholder(__('common.placeholders.empty')),
                        TextEntry::make('created_at')
                            ->label(__('tasks.fields.created_at'))
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('tasks.fields.updated_at'))
                            ->dateTime('Y-m-d H:i'),
                    ]),
                ])
                ->columns(1)
                ->collapsible(),
        ];
    }

    /**
     * The checklist (D-25), shown when the task has one: the progress as
     * "done/total" and each item in order — ticked or not, its text as plain
     * text, and for a ticked item who ticked it and when, in the
     * organisation's timezone. The items and who ticked them are loaded in a
     * fixed number of queries whatever the length; ticking is the
     * "Checklist" action's.
     */
    private static function checklistSection(): Section
    {
        return Section::make(__('tasks.sections.checklist'))
            ->description(static fn (?Model $record): ?string => $record instanceof Task ? TaskChecklist::progressLabel($record) : null)
            ->visible(static fn (?Model $record): bool => $record instanceof Task && self::checklistItems($record)->isNotEmpty())
            ->schema([
                RepeatableEntry::make('checklistItems')
                    ->label(__('tasks.fields.checklist'))
                    ->hiddenLabel()
                    ->state(static fn (?Model $record): ?Collection => $record instanceof Task ? self::checklistItems($record) : null)
                    ->schema([
                        TextEntry::make('body')
                            ->label(__('tasks.fields.checklist_item'))
                            ->hiddenLabel()
                            ->icon(static fn (?Model $record): Heroicon => $record instanceof TaskChecklistItem && $record->isDone() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedStop)
                            ->iconColor(static fn (?Model $record): string => $record instanceof TaskChecklistItem && $record->isDone() ? 'success' : 'gray')
                            ->tooltip(static fn (?Model $record): string => $record instanceof TaskChecklistItem && $record->isDone() ? __('tasks.fields.checklist_item_done') : __('tasks.fields.checklist_item_open')),
                        TextEntry::make('ticked')
                            ->label(__('tasks.fields.checklist_ticked_by'))
                            ->hiddenLabel()
                            ->state(static fn (?Model $record): ?string => $record instanceof TaskChecklistItem ? self::tickedBy($record) : null)
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->visible(static fn (?Model $record): bool => $record instanceof TaskChecklistItem && $record->isDone()),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * The task's checklist with who ticked each item, loaded once.
     *
     * @return Collection<int, TaskChecklistItem>
     */
    private static function checklistItems(Task $task): Collection
    {
        return $task->loadMissing('checklistItems.completer')->checklistItems;
    }

    /** "Ticked by Sara, 2026-10-05 09:30" — the moment in the organisation's timezone. */
    private static function tickedBy(TaskChecklistItem $item): ?string
    {
        if ($item->completed_at === null) {
            return null;
        }

        $date = $item->completed_at->copy()->setTimezone(app(SettingsRepository::class)->timezone())->format('Y-m-d H:i');
        $completer = $item->completer;

        if ($completer === null) {
            return __('tasks.fields.checklist_ticked_at', ['date' => $date]);
        }

        $name = $completer->trashed()
            ? __('tasks.empty.deleted_author_named', ['name' => $completer->name])
            : $completer->name;

        return __('tasks.fields.checklist_ticked', ['name' => $name, 'date' => $date]);
    }

    /**
     * The thread (D-14 amendment, 2026-09-28; D-17): progress entries and
     * comments together, newest first — each marked as an update or a
     * comment, with its author, its time in the organisation's timezone, the
     * status a progress entry moved the task to as a badge, and the author's
     * text as plain text, the names a comment mentions highlighted (D-20).
     * The thread, its authors and its mentions are loaded in a fixed number
     * of queries whatever its length; the reader who may report on the task
     * but not edit it is told where their actions are.
     */
    private static function threadSection(): Section
    {
        return Section::make(__('tasks.sections.thread'))
            ->description(static function (?Model $record): ?string {
                $user = auth()->user();

                return $record instanceof Task && $user instanceof User && $user->can('progress', $record) && ! $user->can('update', $record)
                    ? __('tasks.helpers.handed_out')
                    : null;
            })
            ->schema([
                RepeatableEntry::make('updates')
                    ->label(__('tasks.sections.thread'))
                    ->hiddenLabel()
                    ->state(static fn (?Model $record): ?Collection => $record instanceof Task ? $record->loadMissing(['updates.author', 'updates.mentions.user'])->updates : null)
                    ->placeholder(__('tasks.empty.thread'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('kind')
                                ->label(__('tasks.fields.update_kind'))
                                ->badge(),
                            TextEntry::make('author_name')
                                ->label(__('tasks.fields.update_author'))
                                ->state(static fn (?Model $record): ?string => $record instanceof TaskUpdate ? self::authorName($record) : null),
                            TextEntry::make('created_at')
                                ->label(__('tasks.fields.update_created_at'))
                                ->dateTime('Y-m-d H:i'),
                            TextEntry::make('status')
                                ->label(__('tasks.fields.update_status'))
                                ->badge()
                                ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && $record->status !== null),
                        ]),
                        TextEntry::make('body')
                            ->label(static fn (?Model $record): string => $record instanceof TaskUpdate && $record->isComment() ? __('tasks.fields.comment_body') : __('tasks.fields.update_body'))
                            ->formatStateUsing(static fn (?string $state, ?Model $record): string|HtmlString => $record instanceof TaskUpdate ? self::body($record) : (string) $state)
                            ->extraAttributes(['class' => 'whitespace-pre-line'])
                            ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && filled($record->body))
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * The entry's text, with each "@Name" of a colleague the comment mentions
     * set in the mention highlight (D-20). The text is escaped first and the
     * names are matched in their escaped form, so nothing the author or a
     * user's name holds is ever read as markup; the longest name wins where
     * one starts another, and a name is not marked inside a longer word —
     * neither one that runs on after it nor one it ends ("omar@Sara" is an
     * address, not a mention; the editor itself opens its "@" list only at
     * the start of a word). An entry that mentions nobody stays a plain
     * string, escaped by the entry.
     */
    private static function body(TaskUpdate $update): string|HtmlString
    {
        $body = (string) $update->body;

        $names = $update->mentions
            ->map(static fn (TaskUpdateMention $mention): ?string => $mention->user?->name)
            ->filter(static fn (?string $name): bool => filled($name))
            ->map(static fn (?string $name): string => e('@'.$name))
            ->unique()
            ->sortByDesc(static fn (string $name): int => mb_strlen($name))
            ->values()
            ->all();

        if ($names === [] || ! str_contains($body, '@')) {
            return $body;
        }

        // A letter, a combining mark (an Arabic diacritic belongs to the letter before it), a digit or "_" continues a word.
        $pattern = '/(?<![\p{L}\p{M}\p{N}_])(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names)).')(?![\p{L}\p{M}\p{N}_])/u';

        $highlighted = preg_replace_callback(
            $pattern,
            static fn (array $match): string => '<span class="crm-mention">'.$match[0].'</span>',
            e($body),
        );

        return new HtmlString($highlighted ?? e($body));
    }

    /** The author's name, marked when their account was deleted, or a fallback when it is gone. */
    private static function authorName(TaskUpdate $update): string
    {
        $author = $update->author;

        if ($author === null) {
            return __('tasks.empty.deleted_author');
        }

        return $author->trashed()
            ? __('tasks.empty.deleted_author_named', ['name' => $author->name])
            : $author->name;
    }

    /**
     * The first task of the series, when the reader may open it and may
     * enter the Tasks workspace (D-23): this infolist also renders in the
     * task modals of sales record pages.
     */
    private static function seriesUrl(Task $record): ?string
    {
        $series = $record->series;
        $user = auth()->user();

        if ($series === null || ! $user instanceof User || ! $user->can('view', $series)) {
            return null;
        }

        return PanelUrl::forReader($user, TaskResource::class, static fn (): string => TaskResource::getUrl('view', ['record' => $series]));
    }

    /** "Every 2 weeks", "Every day" — or "does not repeat", in the reader's language. */
    public static function recurrenceSummary(Task $record): string
    {
        $frequency = $record->recurrence_frequency;

        if (! $frequency->repeats()) {
            return $frequency->getLabel();
        }

        $interval = max(1, (int) ($record->recurrence_interval ?? 1));

        return trans_choice('tasks.recurrence.'.$frequency->value, $interval, ['count' => $interval]);
    }

    /** Danger once overdue, warning on the due day, nothing otherwise. */
    public static function dueColor(Task $record): ?string
    {
        if ($record->isOverdue()) {
            return 'danger';
        }

        return $record->isDueToday() ? 'warning' : null;
    }
}
