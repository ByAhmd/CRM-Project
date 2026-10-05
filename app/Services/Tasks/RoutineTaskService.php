<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\Permission;
use App\Enums\RecurrenceFrequency;
use App\Enums\RoutineFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\CauserResolver;
use Throwable;

/**
 * Routine tasks (decision D-22): saving, pausing, resuming and deleting a
 * routine, and producing its tasks on the scheduled days.
 *
 * - who may do any of it is TaskTemplatePolicy's (`task.assign`); the service
 *   checks the assignees itself: at least one, and every one of them within
 *   the actor's assignment reach (RecordVisibilityResolver::assignableUsers —
 *   active users holding `task.view_any`), exactly as when a task is created
 *   for someone (D-4, D-14, D-15), because every save hands the routine's
 *   future tasks out in the actor's name;
 * - the schedule is normalised: a weekly routine keeps its weekday only, a
 *   monthly one its day 1–28 only; the due time defaults to 17:00 and the
 *   due offset to 0 days (the scheduled day itself);
 * - every save stamps the actor as the routine's assigner (`assigned_by`) —
 *   the routine's assigner is whoever last saved it; pausing and resuming do
 *   not change it;
 * - `next_run_on` is the first organisation day on or after today that matches
 *   the schedule — "today" in the organisation timezone (SettingsRepository),
 *   never the server clock's day — recomputed whenever the schedule changes or
 *   the routine is resumed, and cleared while it is paused; a day whose tasks
 *   were already produced (`last_run_on`) is never chosen again, so a
 *   same-day schedule change or resume cannot hand the same day out twice;
 * - generateDue() (the hourly tasks:generate-routines) takes each active
 *   routine whose day has come, locks its row and re-checks it, produces the
 *   tasks of the latest matching day only — days missed while the server was
 *   down are not replayed — and advances `next_run_on` past today in the same
 *   transaction, so a second run the same day creates nothing; a routine
 *   that fails is reported and retried next hour, never holding up the rest;
 * - each task is an ordinary handed-out task (D-14 amendment): kind task,
 *   pending, the routine's title, description and priority, due on the day
 *   plus `due_in_days` at `due_time` organisation time, one per assignee who
 *   may still sign in and still holds `task.view_any`; it is created and
 *   assigned by the routine's assigner (as stored — a deleted assigner's
 *   routine still runs), linked to the routine, audited with the assigner as
 *   the causer, and announced to its assignee exactly as any assignment
 *   (RecordAssignmentService::announce(): bell + mail, after commit);
 * - a routine may carry a checklist (D-25, rules in TaskChecklist): a save
 *   that submits `checklist` replaces it and records what changed on the
 *   routine, one that leaves the key out keeps it; every task the routine
 *   creates gets its own copy, unticked and in order;
 * - deleting a routine never deletes its tasks (the foreign key clears their
 *   link) nor their checklists.
 */
final class RoutineTaskService
{
    /** The longest title — a routine's title becomes the title of each of its tasks. */
    public const int TITLE_MAX = 200;

    /** The longest description, in characters. */
    public const int DESCRIPTION_MAX = 2000;

    /** The last day of the month a monthly routine may run on, so every month has it. */
    public const int DAY_OF_MONTH_MAX = 28;

    /** The most days after its scheduled day a routine's task may be due. */
    public const int DUE_IN_DAYS_MAX = 30;

    /** The time of day a routine's tasks are due unless another is chosen (organisation time). */
    public const string DEFAULT_DUE_TIME = '17:00';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecordVisibilityResolver $resolver,
        private readonly RecordAssignmentService $assignment,
        private readonly SettingsRepository $settings,
        private readonly CauserResolver $causers,
        private readonly TaskChecklist $checklist,
    ) {}

    /**
     * Creates a routine (no $template) or saves an existing one. The keys the
     * caller leaves out keep their stored values on an existing routine and
     * take the defaults on a new one; `assignees` is the full list of user ids.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $actor, ?TaskTemplate $template = null): TaskTemplate
    {
        // The stored row is the baseline the omitted keys and the schedule
        // change are judged against, even when the given instance is stale.
        $template?->refresh();

        $attributes = $this->attributes($data, $template);
        $assigneeIds = $this->assigneeIds($data, $template, $actor);

        // Absent means unchanged on an edit and empty on a create (D-25).
        $checklist = array_key_exists('checklist', $data)
            ? array_map(static fn (array $item): string => $item['body'], TaskChecklist::normalise($data['checklist']))
            : null;

        return $this->asCauser($actor, fn (): TaskTemplate => DB::transaction(function () use ($template, $attributes, $assigneeIds, $actor, $checklist): TaskTemplate {
            // A save by someone other than the stored assigner takes the
            // routine over: its tasks are handed out in the saver's name from
            // now on, which the ledger records even when nothing else changed.
            $previousAssignerId = null;
            $takenOver = false;

            if ($template === null) {
                $template = new TaskTemplate;
                $wasActive = false;
            } else {
                $this->lockInto($template);
                $wasActive = $template->is_active;
                $previousAssignerId = $template->assigned_by === null ? null : (int) $template->assigned_by;
                $takenOver = $previousAssignerId !== (int) $actor->getKey();
            }

            $template->fill($attributes);
            $scheduleChanged = ! $template->exists || $template->isDirty(['frequency', 'weekday', 'day_of_month']);

            // Not fillable: written here only (D-22, "whoever last saved it").
            $template->forceFill(['assigned_by' => $actor->getKey()]);
            $this->schedule($template, recompute: $scheduleChanged || ! $wasActive);
            $template->save();

            $this->syncAssignees($template, $assigneeIds, $actor, $takenOver, $previousAssignerId);

            if ($checklist !== null) {
                $this->syncChecklist($template, $checklist, $actor);
            }

            return $template;
        }));
    }

    /** Stops the routine: it produces nothing until it is resumed. */
    public function pause(TaskTemplate $template, User $actor): TaskTemplate
    {
        return $this->asCauser($actor, fn (): TaskTemplate => DB::transaction(function () use ($template): TaskTemplate {
            $this->lockInto($template);

            if (! $template->is_active) {
                return $template;
            }

            $template->forceFill(['is_active' => false]);
            $this->schedule($template, recompute: true);
            $template->save();

            return $template;
        }));
    }

    /** Starts the routine again: its next day is computed from today. */
    public function resume(TaskTemplate $template, User $actor): TaskTemplate
    {
        return $this->asCauser($actor, fn (): TaskTemplate => DB::transaction(function () use ($template): TaskTemplate {
            $this->lockInto($template);

            if ($template->is_active) {
                return $template;
            }

            $template->forceFill(['is_active' => true]);
            $this->schedule($template, recompute: true);
            $template->save();

            return $template;
        }));
    }

    /** Removes the routine; the tasks it created stay (their link is cleared). */
    public function delete(TaskTemplate $template, User $actor): void
    {
        $this->asCauser($actor, function () use ($template): void {
            DB::transaction(function () use ($template): void {
                $this->lockInto($template);
                $template->delete();
            });
        });
    }

    /**
     * Produces the tasks of every active routine whose day has come, as of
     * $now (default: now). Returns how many tasks were created.
     */
    public function generateDue(?CarbonInterface $now = null): int
    {
        $today = $this->organisationToday($now);

        $ids = TaskTemplate::query()
            ->where('is_active', true)
            ->where('next_run_on', '<=', $today->toDateString())
            ->orderBy('next_run_on')
            ->orderBy('id')
            ->pluck('id');

        $created = 0;

        foreach ($ids as $id) {
            try {
                $created += $this->produce((int) $id, $today);
            } catch (Throwable $exception) {
                // One routine that cannot be produced (its transaction rolls
                // back, so it stays due and is retried next hour) never holds
                // up the others: the failure is reported and the pass carries
                // on, as the reminder and stale-lead passes do.
                report($exception);
            }
        }

        return $created;
    }

    /** Today's date in the organisation timezone (midnight, in that timezone). */
    public function organisationToday(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())
            ->setTimezone($this->settings->timezone())
            ->startOfDay();
    }

    /** The first day on or after $day that matches the routine's schedule. */
    public function firstRunOnOrAfter(TaskTemplate $template, CarbonInterface $day): CarbonImmutable
    {
        $day = CarbonImmutable::instance($day)->startOfDay();

        if ($template->frequency === RoutineFrequency::Weekly) {
            return $day->addDays(((int) $template->weekday - $day->dayOfWeek + 7) % 7);
        }

        $dayOfMonth = (int) $template->day_of_month;

        return $day->day <= $dayOfMonth
            ? $day->setDay($dayOfMonth)
            : $day->startOfMonth()->addMonth()->setDay($dayOfMonth);
    }

    /** The latest day on or before $day that matches the routine's schedule. */
    public function latestRunOnOrBefore(TaskTemplate $template, CarbonInterface $day): CarbonImmutable
    {
        $day = CarbonImmutable::instance($day)->startOfDay();

        if ($template->frequency === RoutineFrequency::Weekly) {
            return $day->subDays(($day->dayOfWeek - (int) $template->weekday + 7) % 7);
        }

        $dayOfMonth = (int) $template->day_of_month;

        return $day->day >= $dayOfMonth
            ? $day->setDay($dayOfMonth)
            : $day->startOfMonth()->subMonth()->setDay($dayOfMonth);
    }

    /**
     * When a task produced on $day is due: `due_in_days` later at `due_time`,
     * organisation time, returned in the application timezone the datetime
     * columns are stored in.
     */
    public function dueAt(TaskTemplate $template, CarbonInterface $day): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $template->due_time) + [1 => 0]);

        return CarbonImmutable::create($day->year, $day->month, $day->day, $hour, $minute, 0, $this->settings->timezone())
            ->addDays($template->due_in_days)
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * One routine's day, in its own transaction with the row locked: the
     * state is re-checked under the lock, so two overlapping runs cannot both
     * produce it.
     */
    private function produce(int $id, CarbonImmutable $today): int
    {
        return DB::transaction(function () use ($id, $today): int {
            $template = TaskTemplate::query()->whereKey($id)->lockForUpdate()->first();

            if (! $template instanceof TaskTemplate
                || ! $template->is_active
                || $template->next_run_on === null
                || $template->next_run_on->toDateString() > $today->toDateString()) {
                return 0;
            }

            // Only the latest matching day is produced: a routine that missed
            // days (the server was down) catches up once, not with a backlog.
            $day = $this->latestRunOnOrBefore($template, $today);
            $created = 0;

            if ($day->toDateString() >= $template->next_run_on->toDateString() && ! $this->produced($template, $day)) {
                $created = $this->createTasks($template, $day);
                $template->forceFill(['last_run_on' => $day->toDateString()]);
            }

            $template->forceFill(['next_run_on' => $this->firstRunOnOrAfter($template, $today->addDay())->toDateString()]);

            // Bookkeeping, not an edit: updated_at keeps the last save by a person.
            TaskTemplate::withoutTimestamps(static fn (): bool => $template->save());

            return $created;
        });
    }

    /**
     * The tasks of one day, one per eligible assignee, created and assigned in
     * the assigner's name. Returns how many were created.
     */
    private function createTasks(TaskTemplate $template, CarbonImmutable $day): int
    {
        $assigner = $template->assigner()->first();

        $assignees = $template->assignees()->get()->filter(
            static fn (User $assignee): bool => $assignee->status->canAuthenticate() && $assignee->can(Permission::TaskViewAny->value),
        );

        if ($assignees->isEmpty()) {
            return 0;
        }

        $dueAt = $this->dueAt($template, $day);
        $checklist = TaskChecklist::templateBodies($template);

        return $this->asCauser($assigner, function () use ($template, $assignees, $assigner, $dueAt, $checklist): int {
            foreach ($assignees as $assignee) {
                $task = new Task([
                    'title' => $template->title,
                    'description' => $template->description,
                    'kind' => TaskKind::Task,
                    'status' => TaskStatus::Pending,
                    'priority' => $template->priority,
                    'due_at' => $dueAt,
                    'starts_at' => null,
                    'ends_at' => null,
                    'reminder_at' => null,
                    'assignee_id' => $assignee->getKey(),
                    'lead_id' => null,
                    'contact_id' => null,
                    'account_id' => null,
                    'deal_id' => null,
                    'recurrence_frequency' => RecurrenceFrequency::None,
                    'recurrence_interval' => null,
                    'recurrence_ends_at' => null,
                    'series_id' => null,
                    'created_by' => $template->assigned_by,
                ]);

                // Guarded columns — trusted services write them directly. The
                // assigner is the routine's as stored, even when that account
                // has since been deleted (D-14: never replaced).
                $task->forceFill([
                    'assigned_by' => $template->assigned_by,
                    'template_id' => $template->getKey(),
                ]);

                $task->save();

                // Each task works through its own copy of the steps (D-25).
                $this->checklist->copyInto($task, $checklist);

                // Bell + mail as for any assignment (D-14), after commit. With
                // no assigner left at all there is nobody to name as sender.
                if ($assigner instanceof User) {
                    $this->assignment->announce($task, $assignee, $assigner);
                }
            }

            return $assignees->count();
        });
    }

    /** Whether the routine already produced the given day (or a later one). */
    private function produced(TaskTemplate $template, CarbonImmutable $day): bool
    {
        return $template->last_run_on !== null && $template->last_run_on->toDateString() >= $day->toDateString();
    }

    /**
     * Sets `next_run_on` for the routine's state: none while it is paused; the
     * first matching day from today (or from the day after the last produced
     * one) when it is recomputed or has none yet; otherwise kept.
     */
    private function schedule(TaskTemplate $template, bool $recompute): void
    {
        if (! $template->is_active) {
            $template->forceFill(['next_run_on' => null]);

            return;
        }

        if (! $recompute && $template->next_run_on !== null) {
            return;
        }

        $from = $this->organisationToday();

        if ($this->produced($template, $from)) {
            $from = $from->addDay();
        }

        $template->forceFill(['next_run_on' => $this->firstRunOnOrAfter($template, $from)->toDateString()]);
    }

    /**
     * Replaces the assignee list and writes the change to the ledger — the
     * attribute diff LogsActivity records sees neither the pivot nor the
     * assigner stamp — together with a takeover of the routine by the saver.
     *
     * @param  list<int>  $assigneeIds
     */
    private function syncAssignees(TaskTemplate $template, array $assigneeIds, User $actor, bool $takenOver, ?int $previousAssignerId): void
    {
        $changes = $template->assignees()->sync($assigneeIds);
        $template->unsetRelation('assignees');

        $attached = array_map('intval', $changes['attached']);
        $detached = array_map('intval', $changes['detached']);

        if ($attached === [] && $detached === [] && ! $takenOver) {
            return;
        }

        $names = static fn (array $ids): array => $ids === []
            ? []
            : User::withTrashed()->whereKey($ids)->orderBy('name')->pluck('name')->all();

        $this->audit->record(ActivityLogEvent::LookupUpdated, $template, $actor, array_filter([
            'subject_label' => $template->title,
            'added' => $names($attached),
            'removed' => $names($detached),
            'assigner' => $takenOver ? $actor->name : null,
            'previous_assigner' => $takenOver && $previousAssignerId !== null
                ? User::withTrashed()->whereKey($previousAssignerId)->value('name')
                : null,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null));
    }

    /**
     * Replaces the routine's checklist (D-25) and writes the change to the
     * ledger — the attribute diff LogsActivity records does not see the child
     * rows: the lines added and removed, or that the same lines were
     * reordered.
     *
     * @param  list<string>  $bodies
     */
    private function syncChecklist(TaskTemplate $template, array $bodies, User $actor): void
    {
        $changes = $this->checklist->replaceTemplate($template, $bodies);

        if ($changes['added'] === [] && $changes['removed'] === [] && ! $changes['reordered']) {
            return;
        }

        $this->audit->record(ActivityLogEvent::LookupUpdated, $template, $actor, array_filter([
            'subject_label' => $template->title,
            'checklist_added' => $changes['added'],
            'checklist_removed' => $changes['removed'],
            'checklist_reordered' => $changes['reordered'] ? true : null,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null));
    }

    /**
     * The attributes a save writes, validated and normalised, with the stored
     * routine (on an edit) or the defaults (on a create) filling the keys the
     * caller left out.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?TaskTemplate $existing): array
    {
        $value = static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $data)
            ? $data[$key]
            : ($existing === null ? $default : $existing->getAttribute($key));

        $errors = [];

        $title = trim((string) $value('title', ''));

        if ($title === '') {
            $errors['title'] = __('task_templates.validation.title_required');
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = __('task_templates.validation.title_too_long', ['max' => self::TITLE_MAX]);
        }

        $description = trim((string) $value('description', ''));

        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'] = __('task_templates.validation.description_too_long', ['max' => self::DESCRIPTION_MAX]);
        }

        $priority = self::enum(TaskPriority::class, $value('priority', TaskPriority::Medium));

        if ($priority === null) {
            $errors['priority'] = __('task_templates.validation.priority_required');
        }

        $frequency = self::enum(RoutineFrequency::class, $value('frequency'));
        $weekday = null;
        $dayOfMonth = null;

        if ($frequency === null) {
            $errors['frequency'] = __('task_templates.validation.frequency_required');
        } elseif ($frequency === RoutineFrequency::Weekly) {
            // A day left out of the data keeps the stored one only while the
            // routine stays weekly; switching frequency needs the new day.
            $weekday = self::integer(array_key_exists('weekday', $data)
                ? $data['weekday']
                : ($existing?->frequency === RoutineFrequency::Weekly ? $existing->weekday : null));

            if ($weekday === null || $weekday > 6) {
                $errors['weekday'] = __('task_templates.validation.weekday_required');
            }
        } else {
            $dayOfMonth = self::integer(array_key_exists('day_of_month', $data)
                ? $data['day_of_month']
                : ($existing?->frequency === RoutineFrequency::Monthly ? $existing->day_of_month : null));

            if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > self::DAY_OF_MONTH_MAX) {
                $errors['day_of_month'] = __('task_templates.validation.day_of_month_range', ['max' => self::DAY_OF_MONTH_MAX]);
            }
        }

        $dueTimeInput = $value('due_time');
        $dueTime = self::time(blank($dueTimeInput) ? self::DEFAULT_DUE_TIME : $dueTimeInput);

        if ($dueTime === null) {
            $errors['due_time'] = __('task_templates.validation.due_time_invalid');
        }

        $dueInDaysInput = $value('due_in_days');
        $dueInDays = blank($dueInDaysInput) ? 0 : self::integer($dueInDaysInput);

        if ($dueInDays === null || $dueInDays > self::DUE_IN_DAYS_MAX) {
            $errors['due_in_days'] = __('task_templates.validation.due_in_days_range', ['max' => self::DUE_IN_DAYS_MAX]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'priority' => $priority,
            'frequency' => $frequency,
            'weekday' => $weekday,
            'day_of_month' => $dayOfMonth,
            'due_time' => $dueTime,
            'due_in_days' => $dueInDays,
            'is_active' => filter_var($value('is_active', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * The routine's assignees: at least one, each within the actor's
     * assignment reach — every save hands the routine's tasks out in the
     * actor's name, so whoever saves must be able to hand work to all of them.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function assigneeIds(array $data, ?TaskTemplate $existing, User $actor): array
    {
        $raw = array_key_exists('assignees', $data)
            ? $data['assignees']
            : ($existing === null ? [] : $existing->assignees()->pluck('users.id')->all());

        $ids = array_values(array_unique(array_filter(
            array_map(self::integer(...), is_array($raw) ? array_values($raw) : [$raw]),
            static fn (?int $id): bool => $id !== null && $id > 0,
        )));

        if ($ids === []) {
            throw ValidationException::withMessages(['assignees' => __('task_templates.validation.assignees_required')]);
        }

        $reachable = $this->resolver->assignableUsers($actor, Task::permissionGroup())
            ->whereKey($ids)
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $unreachable = array_values(array_diff($ids, $reachable));

        if ($unreachable !== []) {
            // Named, so the saver knows whom to take off: an assignee who was
            // disabled since, or one outside the saver's reach.
            $names = User::withTrashed()->whereKey($unreachable)->orderBy('name')->pluck('name');

            throw ValidationException::withMessages(['assignees' => $names->isEmpty()
                ? UnassignableUserException::make()->getMessage()
                : trans_choice('task_templates.validation.assignees_unassignable', $names->count(), [
                    'names' => $names->implode((string) __('common.separators.list')),
                ])]);
        }

        return $ids;
    }

    /**
     * Locks the routine's row for the write and loads the stored state into
     * the caller's instance, so the change is judged against what is stored
     * and an hourly run cannot interleave with it.
     */
    private function lockInto(TaskTemplate $template): void
    {
        $stored = TaskTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

        $template->setRawAttributes($stored->getAttributes(), sync: true);
    }

    /**
     * Runs the callback with the given user as the ledger causer — the actor
     * for a write by a person, the routine's assigner for the tasks a run
     * creates — and restores the default resolution afterwards. A run whose
     * routine has no assigner left records no causer (the system).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asCauser(?User $causer, Closure $callback): mixed
    {
        $this->causers->setCauser($causer);

        try {
            return $callback();
        } finally {
            $this->causers->setCauser(null);
        }
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private static function enum(string $enum, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return $enum::tryFrom($value);
        }

        return null;
    }

    /** A whole, non-negative number from form or API input; null for anything else. */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /** A time of day as stored (`HH:MM:00`) from `H:i`, `H:i:s` or a date-time; null when invalid. */
    private static function time(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:00');
        }

        if (is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $match) === 1) {
            return sprintf('%02d:%02d:00', (int) $match[1], (int) $match[2]);
        }

        return null;
    }
}
