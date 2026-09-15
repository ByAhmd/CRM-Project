<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\ActivityKind;
use App\Enums\Permission;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The calendar read model (decision D-12): the tasks and the meeting / call
 * activities a viewer may see, inside a date range, as CalendarEvent DTOs.
 *
 * - a task with a start (meetings and calls) is a timed entry spanning
 *   starts_at..ends_at; any other task is an all-day entry on its due date;
 *   a task with neither is not on the calendar;
 * - an open task is painted with its priority colour, a completed or
 *   cancelled one with its status colour and the muted class, so a closed
 *   task stays visible but recedes;
 * - a meeting or a call activity is a timed entry at occurred_at, extended
 *   by its duration when one was logged, painted with its kind colour and
 *   never draggable — activities are immutable;
 * - visibility is the resources' own scoped queries (D-4): the feed never
 *   re-implements a scope. The queries default to TaskResource and
 *   ActivityResource, which scope to the authenticated user, so the default
 *   is refused when that user is not the viewer.
 *
 * One range returns at most MAX_EVENTS entries, the earliest across both
 * sources: each source reads at most MAX_EVENTS + 1 rows in the order of
 * its stored moment (a task's start, else its due date; an activity's
 * occurrence), the two are merged in that order and cut, and the range says
 * whether it was cut so the page can tell the viewer to narrow the view.
 * Whether a task is draggable is TaskPolicy::update resolved once for the
 * range — the `task.update` key and the viewer's reach over assignees from
 * RecordVisibilityResolver — never one policy call per entry.
 *
 * The range arrives in the organisation timezone (what the browser shows)
 * while every stored date is in the application timezone, so the bounds are
 * converted before they reach the query: a bound bound as-is is formatted
 * in its own offset and shifts the whole range by the difference.
 *
 * Colours are `var(--crm-color-<name>)` references to the calendar block of
 * the theme, so the same feed paints correctly in both themes.
 */
final class CalendarFeed
{
    /** The widest range one request may load (a six-week month grid is 42 days). */
    public const MAX_RANGE_DAYS = 62;

    /** The most entries one range returns; a busier range returns its earliest and says it was cut. */
    public const MAX_EVENTS = 500;

    public const MUTED_CLASS = 'crm-calendar-event-muted';

    /** The due hour of a task created from a whole day (no time picked): a working-morning default. */
    public const DEFAULT_DUE_HOUR = 9;

    private const TEXT_COLOR = 'var(--crm-color-on-event)';

    private const FALLBACK_COLOR = 'var(--fc-event-bg-color)';

    /** Filament colour names to the calendar tokens declared in theme.css. */
    private const COLORS = [
        'primary' => 'var(--crm-color-primary)',
        'gray' => 'var(--crm-color-gray)',
        'info' => 'var(--crm-color-info)',
        'success' => 'var(--crm-color-success)',
        'warning' => 'var(--crm-color-warning)',
        'danger' => 'var(--crm-color-danger)',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * The viewer's entries overlapping [$start, $end), ordered by start, at
     * most MAX_EVENTS of them.
     *
     * @param  Builder<Task>|null  $tasks  The viewer-scoped task query; TaskResource's when omitted.
     * @param  Builder<Activity>|null  $activities  The viewer-scoped activity query; ActivityResource's when omitted.
     * @return Collection<int, CalendarEvent>
     */
    public function events(User $viewer, Carbon $start, Carbon $end, ?Builder $tasks = null, ?Builder $activities = null): Collection
    {
        return $this->range($viewer, $start, $end, $tasks, $activities)->events;
    }

    /**
     * The viewer's entries overlapping [$start, $end), ordered by start: the
     * earliest MAX_EVENTS of them, and whether the range held more.
     *
     * @param  Builder<Task>|null  $tasks  The viewer-scoped task query; TaskResource's when omitted.
     * @param  Builder<Activity>|null  $activities  The viewer-scoped activity query; ActivityResource's when omitted.
     */
    public function range(User $viewer, Carbon $start, Carbon $end, ?Builder $tasks = null, ?Builder $activities = null): CalendarRange
    {
        $tasks ??= self::resourceQuery($viewer, static fn (): Builder => TaskResource::getEloquentQuery());
        $activities ??= self::resourceQuery($viewer, static fn (): Builder => ActivityResource::getEloquentQuery());

        $timezone = $this->settings->timezone();
        $start = self::inAppTimezone($start);
        $end = self::inAppTimezone($end);

        // Each source is read in the order of its stored moment and the sort
        // below is stable, so the merge keeps both orders: every entry of the
        // earliest MAX_EVENTS is among the MAX_EVENTS + 1 rows its source
        // returned. Base collections: an Eloquent collection merges by model
        // key, and the two sources share key values.
        $candidates = $this->tasks($tasks, $start, $end)
            ->toBase()
            ->map(static fn (Task $task): array => ['at' => self::taskMoment($task), 'record' => $task])
            ->merge($this->activities($activities, $start, $end)
                ->toBase()
                ->map(static fn (Activity $activity): array => ['at' => $activity->occurred_at->getTimestamp(), 'record' => $activity]))
            ->sortBy('at');

        $editable = $this->taskEditability($viewer);

        $events = $candidates
            ->take(self::MAX_EVENTS)
            ->map(fn (array $candidate): CalendarEvent => $candidate['record'] instanceof Task
                ? $this->taskEvent($candidate['record'], $editable, $timezone)
                : $this->activityEvent($candidate['record'], $timezone))
            ->sortBy(static fn (CalendarEvent $event): int => Carbon::parse($event->start, $timezone)->getTimestamp())
            ->values();

        return new CalendarRange($events, $candidates->count() > self::MAX_EVENTS);
    }

    /**
     * Tasks whose span (meetings and calls with a start) or due date falls
     * inside the range.
     *
     * @param  Builder<Task>  $query
     * @return Collection<int, Task>
     */
    private function tasks(Builder $query, Carbon $start, Carbon $end): Collection
    {
        return $query
            ->where(static function (Builder $overlap) use ($start, $end): void {
                $overlap
                    ->where(static function (Builder $timed) use ($start, $end): void {
                        $timed
                            ->whereNotNull('tasks.starts_at')
                            ->where('tasks.starts_at', '<', $end)
                            ->where(static function (Builder $endsAfterStart) use ($start): void {
                                $endsAfterStart
                                    ->where('tasks.ends_at', '>=', $start)
                                    ->orWhere(static function (Builder $openEnded) use ($start): void {
                                        $openEnded->whereNull('tasks.ends_at')->where('tasks.starts_at', '>=', $start);
                                    });
                            });
                    })
                    ->orWhere(static function (Builder $due) use ($start, $end): void {
                        $due
                            ->whereNull('tasks.starts_at')
                            ->where('tasks.due_at', '>=', $start)
                            ->where('tasks.due_at', '<', $end);
                    });
            })
            // The stored moment taskMoment() reads: the start, else the due date.
            ->orderByRaw('COALESCE(tasks.starts_at, tasks.due_at)')
            ->orderBy('tasks.id')
            ->limit(self::MAX_EVENTS + 1)
            ->get();
    }

    /**
     * Meetings and calls that occurred inside the range.
     *
     * @param  Builder<Activity>  $query
     * @return Collection<int, Activity>
     */
    private function activities(Builder $query, Carbon $start, Carbon $end): Collection
    {
        return $query
            ->whereIn('activities.kind', [ActivityKind::Meeting->value, ActivityKind::Call->value])
            ->where('activities.occurred_at', '>=', $start)
            ->where('activities.occurred_at', '<', $end)
            ->orderBy('activities.occurred_at')
            ->orderBy('activities.id')
            ->limit(self::MAX_EVENTS + 1)
            ->get();
    }

    /** The moment a task is ordered and cut by: its start, else its due date (the query guarantees one). */
    private static function taskMoment(Task $task): int
    {
        return ($task->starts_at ?? $task->due_at)?->getTimestamp() ?? 0;
    }

    /**
     * TaskPolicy::update for every task of the range, resolved once: a
     * trashed task is frozen, the viewer needs `task.update`, and the
     * assignee must be inside the viewer's write reach.
     *
     * @return Closure(Task): bool
     */
    private function taskEditability(User $viewer): Closure
    {
        if (! $viewer->can(Permission::TaskUpdate->value)) {
            return static fn (Task $task): bool => false;
        }

        $writableOwner = $this->visibility->writableOwner($viewer, Task::class);

        return static fn (Task $task): bool => ! $task->trashed()
            && $writableOwner($task->assignee_id === null ? null : (int) $task->assignee_id);
    }

    /**
     * @param  Closure(Task): bool  $editable
     */
    private function taskEvent(Task $task, Closure $editable, string $timezone): CalendarEvent
    {
        $timed = $task->starts_at !== null;
        $open = $task->isOpen();
        $color = self::color($open ? $task->priority->getColor() : $task->status->getColor());

        if ($timed) {
            $start = self::iso($task->starts_at, $timezone);
            $end = $task->ends_at === null ? null : self::iso($task->ends_at, $timezone);
        } else {
            // The due-only branch of the query guarantees a due date here.
            $start = $task->due_at === null ? '' : self::date($task->due_at, $timezone);
            $end = null;
        }

        return new CalendarEvent(
            id: 'task-'.$task->getKey(),
            title: $task->title,
            start: $start,
            end: $end,
            allDay: ! $timed,
            backgroundColor: $color,
            borderColor: $color,
            textColor: self::TEXT_COLOR,
            url: TaskResource::getUrl('view', ['record' => $task]),
            editable: $open && $editable($task),
            classNames: $open ? [] : [self::MUTED_CLASS],
            extendedProps: [
                'type' => 'task',
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'kind' => $task->kind->value,
                'subject' => $task->subjectLabel(),
            ],
        );
    }

    private function activityEvent(Activity $activity, string $timezone): CalendarEvent
    {
        $color = self::color($activity->kind->getColor());
        $duration = (int) ($activity->duration_minutes ?? 0);

        return new CalendarEvent(
            id: 'activity-'.$activity->getKey(),
            title: $activity->subject,
            start: self::iso($activity->occurred_at, $timezone),
            end: $duration > 0 ? self::iso($activity->occurred_at->copy()->addMinutes($duration), $timezone) : null,
            allDay: false,
            backgroundColor: $color,
            borderColor: $color,
            textColor: self::TEXT_COLOR,
            url: ActivityResource::getUrl('view', ['record' => $activity]),
            editable: false,
            classNames: [],
            extendedProps: [
                'type' => 'activity',
                'status' => null,
                'priority' => null,
                'kind' => $activity->kind->value,
                'subject' => $activity->subjectLabel(),
            ],
        );
    }

    /**
     * The resources scope their queries to auth()->user(); handing them to a
     * different viewer would leak that user's records, so the default is
     * refused unless the authenticated user is the viewer.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function resourceQuery(User $viewer, Closure $query): Builder
    {
        $current = auth()->user();

        if (! $current instanceof User || ! $current->is($viewer)) {
            throw new LogicException('CalendarFeed resolves the resource queries for the authenticated user only; pass viewer-scoped queries for anyone else.');
        }

        return $query();
    }

    /**
     * The due moment of a task placed on a whole day: the default hour of
     * that day, in the day's own timezone.
     */
    public static function defaultDueAt(CarbonInterface $day): Carbon
    {
        return Carbon::instance($day)->setTime(self::DEFAULT_DUE_HOUR, 0);
    }

    /** Stored dates are in the application timezone, whatever the organisation displays. */
    private static function inAppTimezone(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone((string) config('app.timezone'));
    }

    private static function color(string $name): string
    {
        return self::COLORS[$name] ?? self::FALLBACK_COLOR;
    }

    private static function iso(Carbon $moment, string $timezone): string
    {
        return $moment->copy()->setTimezone($timezone)->toIso8601String();
    }

    private static function date(Carbon $moment, string $timezone): string
    {
        return $moment->copy()->setTimezone($timezone)->toDateString();
    }
}
