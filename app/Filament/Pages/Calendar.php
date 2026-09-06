<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\CalendarEvent;
use App\Services\Tasks\CalendarFeed;
use App\Services\Tasks\TaskService;
use BackedEnum;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The calendar (decision D-12): month, week, day and list views of the
 * viewer's tasks, meetings and calls, on FullCalendar inside a custom page.
 *
 * The browser asks for one visible range at a time through events(); the
 * entries come from CalendarFeed over the resources' scoped queries (D-4).
 * Clicking a day opens the create-task modal, clicking a task opens the
 * edit modal (with "complete" in its footer), and dragging or resizing a
 * task calls moveTask(), which reschedules through TaskService after the
 * policy check. Activities are immutable: their entries only link to the
 * activity page. Every write dispatches REFRESH_EVENT so the calendar
 * refetches its range.
 */
final class Calendar extends Page
{
    /** The browser event the calendar listens for to refetch its range. */
    public const REFRESH_EVENT = 'crm-calendar-refresh';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.calendar';

    /** The task form keys an edit modal is filled from (the assignee arrives as OwnerSelect's owner_id). */
    private const FORM_ATTRIBUTES = [
        'title', 'description', 'kind', 'priority', 'due_at', 'reminder_at', 'starts_at', 'ends_at',
        'lead_id', 'contact_id', 'account_id', 'deal_id',
        'recurrence_frequency', 'recurrence_interval', 'recurrence_ends_at',
    ];

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Activities;
    }

    public static function getNavigationLabel(): string
    {
        return __('calendar.navigation');
    }

    public function getTitle(): string
    {
        return __('calendar.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Task::class);
    }

    /**
     * The entries of one visible range, as FullCalendar event objects. The
     * range is bounded so a crafted request cannot ask for years at once.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $start, string $end): array
    {
        $timezone = $this->settings()->timezone();
        $from = self::parse($start, $timezone);
        $to = self::parse($end, $timezone);

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['end' => __('calendar.validation.invalid_date')]);
        }

        if ($from->diffInDays($to) > CalendarFeed::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['end' => __('calendar.validation.range_too_large', ['days' => CalendarFeed::MAX_RANGE_DAYS])]);
        }

        return app(CalendarFeed::class)
            ->events($this->actor(), $from, $to, TaskResource::getEloquentQuery(), ActivityResource::getEloquentQuery())
            ->map(static fn (CalendarEvent $event): array => $event->toArray())
            ->all();
    }

    /**
     * Called by the drag-and-drop and resize handlers with the dragged task
     * and its new position. Returns whether the task moved, so the browser
     * can put the entry back when it did not.
     *
     * A task keeps its time of day when dropped on a day cell (an all-day
     * drop), and its span (meetings and calls) follows the drop: the end the
     * drop carries, else the previous duration from the new start.
     */
    public function moveTask(int $taskId, string $start, ?string $end = null, bool $allDay = false): bool
    {
        $task = TaskResource::getEloquentQuery()->find($taskId);

        if (! $task instanceof Task) {
            abort(404);
        }

        $user = $this->actor();

        abort_unless($user->can('update', $task), 403);

        // A closed task cannot be dragged anywhere: the same refusal the
        // status actions give, rather than a policy 403.
        if ($task->isClosed()) {
            $this->refuse(__('tasks.validation.not_open'));

            return false;
        }

        $timezone = $this->settings()->timezone();

        try {
            $droppedAt = self::parse($start, $timezone);
            $droppedEnd = $end === null ? null : self::parse($end, $timezone);
        } catch (ValidationException $exception) {
            $this->refuse((string) Arr::first(Arr::flatten($exception->errors())));

            return false;
        }

        if ($allDay && $task->due_at !== null) {
            $droppedAt = $droppedAt->setTimeFrom($task->due_at->copy()->setTimezone($timezone));
        }

        $dueAt = self::inAppTimezone($droppedAt);
        $spans = TaskService::kindHasTimeSpan($task->kind) && ($task->starts_at !== null || $droppedEnd !== null);
        $startsAt = $spans ? $dueAt->copy() : null;
        $endsAt = null;

        if ($spans && $droppedEnd !== null) {
            $endsAt = self::inAppTimezone($droppedEnd);
        } elseif ($spans && $task->starts_at !== null && $task->ends_at !== null) {
            $endsAt = $dueAt->copy()->addSeconds($task->ends_at->getTimestamp() - $task->starts_at->getTimestamp());
        }

        try {
            app(TaskService::class)->reschedule($task, $dueAt, $startsAt, $endsAt, $user);
        } catch (ValidationException $exception) {
            $this->refuse((string) Arr::first(Arr::flatten($exception->errors())));

            return false;
        }

        Notification::make()
            ->title(__('calendar.notifications.moved', [
                'task' => $task->title,
                'date' => $dueAt->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            ]))
            ->success()
            ->send();

        $this->dispatch(self::REFRESH_EVENT);

        return true;
    }

    /**
     * Opened by a click on a day or a time slot: the task form, due at the
     * clicked moment (or the default hour of the clicked day).
     */
    public function createTaskAction(): Action
    {
        return Action::make('createTask')
            ->label(__('calendar.actions.create_task'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('calendar.actions.create_task_heading'))
            ->modalSubmitActionLabel(__('calendar.actions.create_task_submit'))
            ->schema(static fn (Schema $schema): Schema => TaskForm::configure($schema))
            ->fillForm(fn (array $arguments): array => [
                'kind' => TaskKind::Task->value,
                'priority' => TaskPriority::Medium->value,
                'due_at' => $this->dueAtFromArgument($arguments['start'] ?? null),
                'owner_id' => $this->actor()->getKey(),
                'recurrence_frequency' => RecurrenceFrequency::None->value,
                'recurrence_interval' => 1,
            ])
            ->authorize(fn (): bool => $this->actor()->can('create', Task::class))
            ->action(function (array $data): void {
                app(TaskService::class)->create($data, $this->actor());

                Notification::make()->title(__('calendar.notifications.created'))->success()->send();
            })
            ->after(fn () => $this->dispatch(self::REFRESH_EVENT));
    }

    /**
     * Opened by a click on a task entry: the task form over the record, with
     * the "complete" action in the modal footer while the task is open.
     */
    public function editTaskAction(): Action
    {
        return Action::make('editTask')
            ->label(__('calendar.actions.edit_task'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('calendar.actions.edit_task_heading'))
            ->modalSubmitActionLabel(__('calendar.actions.edit_task_submit'))
            ->record(fn (array $arguments): ?Task => $this->taskFromArguments($arguments))
            ->schema(static fn (Schema $schema): Schema => TaskForm::configure($schema))
            ->fillForm(static fn (?Task $record): array => $record instanceof Task ? self::formData($record) : [])
            ->authorize(fn (?Task $record): bool => $record instanceof Task && $this->actor()->can('update', $record))
            ->action(function (Task $record, array $data): void {
                app(TaskService::class)->update($record, $data, $this->actor());

                Notification::make()->title(__('calendar.notifications.updated'))->success()->send();
            })
            ->extraModalFooterActions([
                TaskActions::complete()
                    ->label(__('calendar.actions.complete'))
                    ->cancelParentActions()
                    ->after(fn () => $this->dispatch(self::REFRESH_EVENT)),
            ])
            ->after(fn () => $this->dispatch(self::REFRESH_EVENT));
    }

    /**
     * Everything the browser-side calendar needs: locale, direction, week
     * start and timezone from the settings, the translated labels, and what
     * the viewer may do — so no label and no rule lives in JavaScript.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $locale = app()->getLocale();
        $settings = $this->settings();
        $user = auth()->user();

        return [
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'firstDay' => $settings->weekStartsOn(),
            'timeZone' => $settings->timezone(),
            'initialView' => 'dayGridMonth',
            'views' => [
                'month' => __('calendar.views.month'),
                'week' => __('calendar.views.week'),
                'day' => __('calendar.views.day'),
                'list' => __('calendar.views.list'),
            ],
            'buttonText' => [
                'today' => __('calendar.buttons.today'),
                'prev' => __('calendar.buttons.prev'),
                'next' => __('calendar.buttons.next'),
                'month' => __('calendar.views.month'),
                'week' => __('calendar.views.week'),
                'day' => __('calendar.views.day'),
                'list' => __('calendar.views.list'),
            ],
            'allDayText' => __('calendar.texts.all_day'),
            'noEventsText' => __('calendar.texts.no_events'),
            'moreLinkText' => __('calendar.texts.more'),
            'nowIndicator' => true,
            'slotMinTime' => '06:00:00',
            'slotMaxTime' => '22:00:00',
            'canCreate' => $user instanceof User && $user->can('create', Task::class),
            'canMove' => $user instanceof User && $user->can('update', Task::class),
            'methods' => [
                'events' => 'events',
                'moveTask' => 'moveTask',
                'createTask' => 'createTask',
                'editTask' => 'editTask',
            ],
            'refreshEvent' => self::REFRESH_EVENT,
        ];
    }

    /**
     * The form state of a task: its editable attributes only, the assignee
     * under OwnerSelect's name — the same mapping as the edit page.
     *
     * @return array<string, mixed>
     */
    private static function formData(Task $task): array
    {
        return [
            ...Arr::only($task->attributesToArray(), self::FORM_ATTRIBUTES),
            'owner_id' => $task->assignee_id,
        ];
    }

    /**
     * The due date for a task created from the calendar: the clicked moment,
     * or the default hour when a whole day was clicked. Null when the click
     * carried nothing usable, so the form simply starts empty.
     */
    private function dueAtFromArgument(mixed $start): ?string
    {
        if (! is_string($start) || trim($start) === '') {
            return null;
        }

        try {
            $moment = self::parse($start, $this->settings()->timezone());
        } catch (ValidationException) {
            return null;
        }

        if (! str_contains($start, 'T') && ! str_contains($start, ' ')) {
            $moment = CalendarFeed::defaultDueAt($moment);
        }

        return self::inAppTimezone($moment)->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function taskFromArguments(array $arguments): ?Task
    {
        $taskId = $arguments['task'] ?? null;

        if (! is_numeric($taskId)) {
            return null;
        }

        $task = TaskResource::getEloquentQuery()->find((int) $taskId);

        return $task instanceof Task ? $task : null;
    }

    /**
     * A date the browser sent, read in the organisation timezone when it
     * carries no offset of its own.
     */
    private static function parse(string $value, string $timezone): Carbon
    {
        $value = trim($value);

        if ($value === '') {
            throw ValidationException::withMessages(['start' => __('calendar.validation.invalid_date')]);
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages(['start' => __('calendar.validation.invalid_date')]);
        }
    }

    /** Stored dates are in the application timezone, whatever the organisation displays. */
    private static function inAppTimezone(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone((string) config('app.timezone'));
    }

    private function refuse(string $reason): void
    {
        Notification::make()
            ->title(__('calendar.notifications.refused'))
            ->body($reason)
            ->danger()
            ->send();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }
}
