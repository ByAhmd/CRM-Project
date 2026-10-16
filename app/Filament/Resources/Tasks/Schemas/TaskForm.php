<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\Permission;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\SubjectPickers;
use App\Filament\Support\TaskVisuals;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Tasks\TaskChecklist;
use App\Services\Tasks\TaskService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Create / edit task (decision A-10). The status is not part of the form:
 * it changes through the complete, cancel and reopen actions so every
 * transition is audited; on edit it is shown read-only. Meetings and calls
 * carry a start and an end (the calendar span); the end may not precede the
 * start and TaskService applies the same rule server-side. The recurrence
 * fields appear once a frequency other than "does not repeat" is chosen.
 *
 * With $withSubjects the form carries the related-record pickers (all
 * optional: a task may be a personal to-do); on a relation manager the
 * owner record is the subject and the pickers are left out, as they are for
 * an actor who may list none of the subjects (the employee, D-15). The
 * checklist (D-25) is written here by whoever may edit the task; ticking it
 * is a separate action (TaskActions::checklist()).
 *
 * Who holds the task: in the Tasks workspace ($withSubjects — the task
 * pages and the calendar's modals) every assignee at once (D-28,
 * assigneeComponents(): `assignee_ids`, the first one first, for holders of
 * `task.assign`; a read-only list for everyone else). The Sales task tabs
 * (the relation managers) keep OwnerSelect's single `owner_id` exactly as
 * before (D-27, owner); TaskService maps either to the assignees.
 */
final class TaskForm
{
    /** The workspace form's field naming every assignee, the first one first (D-28); TaskService reads it as is. */
    public const string ASSIGNEES = 'assignee_ids';

    public static function configure(Schema $schema, bool $withSubjects = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('tasks.sections.details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('tasks.fields.title'))
                            ->placeholder(__('tasks.placeholders.title'))
                            ->required()
                            ->maxLength(200)
                            ->columnSpanFull(),

                        Select::make('kind')
                            ->label(__('tasks.fields.kind'))
                            ->options(TaskKind::class)
                            ->default(TaskKind::Task->value)
                            ->required()
                            ->live()
                            ->native(false),

                        Select::make('priority')
                            ->label(__('tasks.fields.priority'))
                            ->options(TaskPriority::class)
                            ->default(TaskPriority::Medium->value)
                            ->required()
                            ->native(false),

                        Textarea::make('description')
                            ->label(__('tasks.fields.description'))
                            ->placeholder(__('tasks.placeholders.description'))
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                self::checklistSection(),

                Section::make(__('tasks.sections.schedule'))
                    ->schema([
                        DateTimePicker::make('due_at')
                            ->label(__('tasks.fields.due_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable(),

                        DateTimePicker::make('reminder_at')
                            ->label(__('tasks.fields.reminder_at'))
                            ->helperText(__('tasks.helpers.reminder_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable(),

                        DateTimePicker::make('starts_at')
                            ->label(__('tasks.fields.starts_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::hasTimeSpan($get)),

                        DateTimePicker::make('ends_at')
                            ->label(__('tasks.fields.ends_at'))
                            ->helperText(__('tasks.helpers.ends_at_after_starts_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::hasTimeSpan($get))
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $startsAt = $get('starts_at');

                                    if (blank($value) || blank($startsAt)) {
                                        return;
                                    }

                                    if (Carbon::parse((string) $value)->lessThan(Carbon::parse((string) $startsAt))) {
                                        $fail(__('tasks.validation.ends_before_starts'));
                                    }
                                },
                            ]),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                ...$withSubjects ? [
                    Section::make(__('tasks.sections.related'))
                        ->description(__('tasks.helpers.related'))
                        ->schema(SubjectPickers::components(required: false))
                        ->visible(fn (): bool => SubjectPickers::anyAvailable())
                        ->columns(['default' => 1, 'lg' => 2]),
                ] : [],

                Section::make(__('tasks.sections.assignment'))
                    ->schema([
                        // The Sales task tabs keep their single owner field (D-27); the workspace names every assignee (D-28).
                        ...$withSubjects ? self::assigneeComponents() : OwnerSelect::components(Task::permissionGroup()),

                        Placeholder::make('status_display')
                            ->label(__('tasks.fields.status'))
                            ->content(fn (?Task $record): string => $record?->status->getLabel() ?? '')
                            ->helperText(__('tasks.helpers.status_readonly'))
                            ->visible(fn (?Task $record): bool => $record !== null),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('tasks.sections.recurrence'))
                    ->description(__('tasks.helpers.recurrence'))
                    ->schema([
                        Select::make('recurrence_frequency')
                            ->label(__('tasks.fields.recurrence_frequency'))
                            ->options(RecurrenceFrequency::class)
                            ->default(RecurrenceFrequency::None->value)
                            ->required()
                            ->live()
                            ->native(false),

                        TextInput::make('recurrence_interval')
                            ->label(__('tasks.fields.recurrence_interval'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(255)
                            ->default(1)
                            ->required(fn (Get $get): bool => self::repeats($get))
                            ->visible(fn (Get $get): bool => self::repeats($get))
                            ->extraInputAttributes(['dir' => 'ltr']),

                        DatePicker::make('recurrence_ends_at')
                            ->label(__('tasks.fields.recurrence_ends_at'))
                            ->native(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::repeats($get)),
                    ])
                    ->columns(['default' => 1, 'lg' => 2])
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * The checklist (D-25): a plain repeater whose items carry their stored
     * id, synced by TaskService — never a relationship repeater, which would
     * write the rows itself, outside the service's transaction, lock and
     * audit. Every fill path of this form loads the items
     * (TaskChecklist::formState()), so a save never submits an empty list
     * over a checklist it did not show. On a closed or deleted task the
     * repeater is disabled — and so not submitted at all — because the
     * checklist is frozen; the service refuses a change there anyway.
     */
    private static function checklistSection(): Section
    {
        return Section::make(__('tasks.sections.checklist'))
            ->description(fn (?Task $record): string => self::checklistFrozen($record) ? __('tasks.helpers.checklist_frozen') : __('tasks.helpers.checklist'))
            ->schema([
                Repeater::make('checklist')
                    ->label(__('tasks.fields.checklist'))
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('id'),

                        TextInput::make('body')
                            ->label(__('tasks.fields.checklist_item'))
                            ->hiddenLabel()
                            ->placeholder(__('tasks.placeholders.checklist_item'))
                            ->required()
                            ->maxLength(TaskChecklist::BODY_MAX),
                    ])
                    ->addActionLabel(__('tasks.actions.add_checklist_item'))
                    ->reorderable()
                    ->maxItems(TaskChecklist::MAX_ITEMS)
                    ->defaultItems(0)
                    ->default([])
                    ->disabled(fn (?Task $record): bool => self::checklistFrozen($record)),
            ])
            ->columns(1)
            ->collapsible();
    }

    private static function checklistFrozen(?Task $record): bool
    {
        return $record instanceof Task && ($record->isClosed() || $record->trashed());
    }

    /**
     * Who holds the task, in the Tasks workspace (D-28). For a holder of
     * `task.assign`: one multiple select, `assignee_ids` — the first chosen is
     * the one the task is filed under (`assignee_id`), everyone chosen holds
     * the same task — offering the active users within the actor's reach
     * (RecordVisibilityResolver::assignableUsers, as OwnerSelect does) plus
     * the task's current assignees whatever their state, so an unrelated edit
     * keeps validating; a new task starts with the actor. Everyone else sees
     * every assignee as a read-only list and submits nothing: the field is
     * not rendered, so it is never part of the state they save, and
     * TaskService refuses a change of assignees without `task.assign` anyway.
     *
     * @return list<Component>
     */
    private static function assigneeComponents(): array
    {
        return [
            Select::make(self::ASSIGNEES)
                ->label(__('tasks.fields.assignees'))
                ->helperText(__('tasks.helpers.assignees'))
                ->multiple()
                ->options(static fn (?Model $record): array => self::assigneeOptions($record))
                ->default(static fn (): array => auth()->id() === null ? [] : [(int) auth()->id()])
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(static fn (): bool => self::canAssign())
                ->columnSpanFull(),

            Placeholder::make('assignees_display')
                ->label(__('tasks.fields.assignees'))
                ->content(static fn (?Model $record): string|HtmlString => self::assigneesDisplay($record))
                ->visible(static fn (): bool => ! self::canAssign()),
        ];
    }

    /**
     * The assignee options: the actor's reach, then any current assignee
     * outside it (a disabled or removed colleague still holding the task).
     *
     * @return array<int, string>
     */
    private static function assigneeOptions(?Model $record): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        /** @var array<int, string> $options */
        $options = app(RecordVisibilityResolver::class)->assignableUsers($actor, Task::permissionGroup())->pluck('name', 'id')->all();

        if ($record instanceof Task && $record->exists) {
            $missing = array_values(array_diff($record->assigneeIds(), array_keys($options)));

            if ($missing !== []) {
                /** @var array<int, string> $current */
                $current = User::withTrashed()->whereKey($missing)->pluck('name', 'id')->all();
                $options += $current;
            }
        }

        return $options;
    }

    /** The read-only list: the task's assignees, or the actor on a new task (whom TaskService assigns it to). */
    private static function assigneesDisplay(?Model $record): string|HtmlString
    {
        if ($record instanceof Task && $record->exists) {
            return TaskVisuals::assigneeList($record->loadMissing(Task::ASSIGNEE_RELATIONS)) ?? __('assignment.placeholders.unassigned');
        }

        $actor = auth()->user();

        return $actor instanceof User ? (TaskVisuals::peopleList([$actor]) ?? $actor->name) : __('assignment.placeholders.unassigned');
    }

    private static function canAssign(): bool
    {
        return auth()->user()?->can(Permission::TaskAssign->value) ?? false;
    }

    /**
     * Runs a save of this form through TaskService. The service's refusal of
     * the checklist — a task closed while the form was open, or a request
     * the form's own rules would not have let through — has no field to show
     * under on every path this form is used (the edit page, the relation
     * manager's modal, the calendar's modals, quick add), so it becomes a
     * danger notification and the save halts with the form still open,
     * rolling back whatever the save had begun. A refused assignment (D-4,
     * D-28: someone outside the actor's reach, or a change of assignees from
     * someone without `task.assign`) is reported the same way, in the words
     * OwnerSelect uses for it.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $save
     * @return TResult
     */
    public static function saving(Closure $save): mixed
    {
        try {
            return $save();
        } catch (UnassignableUserException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            if (! array_key_exists('checklist', $errors)) {
                throw $exception;
            }

            Notification::make()->title((string) Arr::first(Arr::flatten($errors['checklist'])))->danger()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    /** Meetings and calls carry a start and an end — the same rule TaskService applies. */
    private static function hasTimeSpan(Get $get): bool
    {
        $kind = self::kindFor($get);

        return $kind !== null && TaskService::kindHasTimeSpan($kind);
    }

    private static function kindFor(Get $get): ?TaskKind
    {
        $kind = $get('kind');

        if ($kind instanceof TaskKind) {
            return $kind;
        }

        return is_string($kind) && $kind !== '' ? TaskKind::tryFrom($kind) : null;
    }

    private static function repeats(Get $get): bool
    {
        $frequency = $get('recurrence_frequency');

        if ($frequency instanceof RecurrenceFrequency) {
            return $frequency->repeats();
        }

        return is_string($frequency) && $frequency !== '' && (RecurrenceFrequency::tryFrom($frequency)?->repeats() ?? false);
    }
}
