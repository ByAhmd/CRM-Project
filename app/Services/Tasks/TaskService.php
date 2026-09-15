<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Enums\Permission;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Support\SubjectPickers;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\CauserResolver;

/**
 * Every write to a task (decisions A-10, D-4): create, edit, complete,
 * cancel, reopen and reschedule.
 *
 * - the assignee defaults to the actor and the creator is always the actor;
 *   a task created for someone else (or for no one) needs `task.assign` and
 *   a user within the actor's assignment reach (D-4), exactly as a later
 *   reassignment does — the form offers only those users, and the service
 *   re-checks rather than trusting it;
 * - a task may be linked to a lead, a contact, an account or a deal, or to
 *   nothing (a personal to-do) — the pickers only offer records the actor
 *   may read, and the ids are re-checked there;
 * - a start and an end are kept for meetings and calls only, and an end
 *   before the start is refused; recurrence settings are kept only when the
 *   task repeats (interval at least 1);
 * - `status` and `completed_at` change here only, inside the workflow guard;
 *   each transition is written to the audit ledger as its own event on top
 *   of the attribute diff LogsActivity records;
 * - completing a task linked to a record writes a system `task` activity on
 *   that record's timeline (Activity.task_id points back at the task) and
 *   schedules the next occurrence when the task repeats;
 * - the reminder and overdue stamps are re-armed when the dates they belong
 *   to move (a reminder moved after it was sent is sent again) and when a
 *   task is reopened; a deleted task has no transitions until it is restored.
 *
 * Reassignment is RecordAssignmentService's job (audit + notification), so
 * the `assign` verb is not duplicated here: an edit that changes the
 * assignee hands that change to the assignment service.
 */
final class TaskService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityRecorder $activities,
        private readonly TaskRecurrence $recurrence,
        private readonly RecordAssignmentService $assignment,
        private readonly CauserResolver $causers,
        private readonly RecordVisibilityResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Task
    {
        $attributes = $this->attributes($data, null, $actor);
        $this->assertAssignableOnCreate($attributes['assignee_id'], $actor);

        return $this->asCauser($actor, fn (): Task => DB::transaction(function () use ($attributes, $actor): Task {
            $task = new Task([
                ...$attributes,
                'status' => TaskStatus::Pending,
                'series_id' => null,
                'created_by' => $actor->getKey(),
            ]);
            $task->save();

            return $task;
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data, User $actor): Task
    {
        // The stored row is the baseline: the keys the caller left out keep
        // their stored values and the stamps are judged against them, even
        // when the given instance is stale (a reminder pass may have stamped
        // the row since it was loaded).
        $task->refresh();

        $attributes = $this->attributes($data, $task, $actor);

        // A change of assignee is an assignment (audit row + notification),
        // so it leaves the attribute diff and goes through the assignment
        // service after the other attributes are saved.
        $newAssigneeId = $attributes['assignee_id'];
        $reassigned = $newAssigneeId !== ($task->assignee_id === null ? null : (int) $task->assignee_id);
        unset($attributes['assignee_id']);

        return $this->asCauser($actor, fn (): Task => DB::transaction(function () use ($task, $attributes, $reassigned, $newAssigneeId, $actor): Task {
            $this->saveRearming($task, $attributes);

            if ($reassigned) {
                $this->assignment->assign($task, $newAssigneeId === null ? null : User::query()->findOrFail($newAssigneeId), $actor);
            }

            return $task;
        }));
    }

    public function complete(Task $task, User $actor, ?string $note = null): Task
    {
        $note = trim((string) $note);

        $this->asCauser($actor, function () use ($task, $actor, $note): void {
            DB::transaction(function () use ($task, $actor, $note): void {
                $current = $this->lock($task);

                if (! $current->isOpen()) {
                    throw InvalidTaskTransitionException::notOpen();
                }

                $completedAt = now();

                Task::withoutWorkflowGuard(static function () use ($current, $completedAt): void {
                    $current->status = TaskStatus::Completed;
                    $current->completed_at = $completedAt;
                    $current->save();
                });

                $this->audit->record(ActivityLogEvent::TaskCompleted, $current, $actor, array_filter([
                    'subject_label' => $current->title,
                    'completed_at' => $completedAt->toIso8601String(),
                    'note' => $note === '' ? null : $note,
                    'assignee_id' => $current->assignee_id,
                    'assignee_name' => $current->assignee?->name,
                ], static fn (mixed $value): bool => $value !== null));

                $subject = $current->subjectRecord();

                if ($subject !== null) {
                    $this->activities->recordSystem(
                        ActivitySubject::for($subject),
                        ActivityKind::Task,
                        $actor,
                        $current->title,
                        array_filter(['task_id' => (int) $current->getKey(), 'note' => $note === '' ? null : $note]),
                        taskId: (int) $current->getKey(),
                    );
                }

                $this->recurrence->next($current);
            });
        });

        return $task->refresh();
    }

    public function cancel(Task $task, User $actor): Task
    {
        $this->asCauser($actor, function () use ($task, $actor): void {
            DB::transaction(function () use ($task, $actor): void {
                $current = $this->lock($task);

                if (! $current->isOpen()) {
                    throw InvalidTaskTransitionException::notOpen();
                }

                Task::withoutWorkflowGuard(static function () use ($current): void {
                    $current->status = TaskStatus::Cancelled;
                    $current->save();
                });

                $this->audit->record(ActivityLogEvent::TaskCancelled, $current, $actor, [
                    'subject_label' => $current->title,
                ]);
            });
        });

        return $task->refresh();
    }

    public function reopen(Task $task, User $actor): Task
    {
        $this->asCauser($actor, function () use ($task, $actor): void {
            DB::transaction(function () use ($task, $actor): void {
                $current = $this->lock($task);

                if ($current->isOpen()) {
                    throw InvalidTaskTransitionException::notClosed();
                }

                $previous = $current->status;

                // A reopened task is due and remindable again, so both
                // notification stamps are re-armed with the status.
                Task::withoutWorkflowGuard(static function () use ($current): void {
                    $current->status = TaskStatus::Pending;
                    $current->completed_at = null;
                    $current->reminder_sent_at = null;
                    $current->overdue_notified_at = null;
                    $current->save();
                });

                $this->audit->record(ActivityLogEvent::TaskReopened, $current, $actor, [
                    'subject_label' => $current->title,
                    'previous_status' => $previous->value,
                ]);
            });
        });

        return $task->refresh();
    }

    /**
     * Moves the task in time (the calendar drags call this). The attribute
     * diff reaches the ledger as `task.updated` through LogsActivity.
     */
    public function reschedule(Task $task, Carbon $dueAt, ?Carbon $startsAt, ?Carbon $endsAt, User $actor): Task
    {
        $this->assertSpan($startsAt, $endsAt);
        $task->refresh();

        return $this->asCauser($actor, fn (): Task => DB::transaction(function () use ($task, $dueAt, $startsAt, $endsAt): Task {
            $this->saveRearming($task, [
                'due_at' => $dueAt,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            return $task;
        }));
    }

    /**
     * Saves the given attributes and re-arms the notification stamps the
     * edit invalidates: a reminder moved after it was sent is sent again at
     * its new time, and a task pushed to a later due date after it was
     * reported overdue is reported again once it is late again. Both stamps
     * are guarded columns, so the save runs inside the workflow guard lift.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveRearming(Task $task, array $attributes): void
    {
        $task->fill($attributes);

        $stamps = [];

        if ($task->isDirty('reminder_at') && $task->reminder_sent_at !== null) {
            $stamps['reminder_sent_at'] = null;
        }

        if ($task->isDirty('due_at') && $task->overdue_notified_at !== null) {
            $previousDueAt = $task->getOriginal('due_at');

            if ($task->due_at !== null && ($previousDueAt === null || $task->due_at->greaterThan($previousDueAt))) {
                $stamps['overdue_notified_at'] = null;
            }
        }

        if ($stamps === []) {
            $task->save();

            return;
        }

        Task::withoutWorkflowGuard(static fn (): bool => $task->forceFill($stamps)->save());
    }

    /**
     * A new task handed to anyone but its creator is an assignment (D-4): the
     * actor needs `task.assign`, and a named assignee must be within the
     * actor's reach (RecordVisibilityResolver::assignableUsers — own team for
     * a manager, everyone for an admin). Creating a task for oneself needs
     * neither.
     */
    private function assertAssignableOnCreate(mixed $assigneeId, User $actor): void
    {
        if ($assigneeId !== null && (int) $assigneeId === (int) $actor->getKey()) {
            return;
        }

        if (! $actor->can(Permission::TaskAssign->value)) {
            throw UnassignableUserException::make();
        }

        if ($assigneeId !== null && ! $this->resolver->assignableUsers($actor, Task::permissionGroup())->whereKey((int) $assigneeId)->exists()) {
            throw UnassignableUserException::make();
        }
    }

    /**
     * The row locked for the transition, so two concurrent completions cannot
     * both spawn the next occurrence. A deleted task has no transitions until
     * it is restored.
     */
    private function lock(Task $task): Task
    {
        /** @var Task $current */
        $current = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

        if ($current->trashed()) {
            throw InvalidTaskTransitionException::trashed();
        }

        return $current;
    }

    /**
     * The attributes a create or an edit writes, resolved from the submitted
     * data with the existing task (on edit) or the defaults (on create) filling
     * the keys the caller left out. The form's `owner_id` (OwnerSelect) is an
     * alias of `assignee_id`; the guarded workflow columns are never read.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Task $existing, User $actor): array
    {
        $value = static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $data)
            ? $data[$key]
            : ($existing === null ? $default : $existing->getAttribute($key));

        $title = trim((string) $value('title', ''));
        $description = trim((string) $value('description', ''));
        $kind = self::enum(TaskKind::class, $value('kind')) ?? TaskKind::Task;
        $startsAt = self::kindHasTimeSpan($kind) ? self::date($value('starts_at')) : null;
        $endsAt = self::kindHasTimeSpan($kind) ? self::date($value('ends_at')) : null;

        $this->assertSpan($startsAt, $endsAt);

        $frequency = self::enum(RecurrenceFrequency::class, $value('recurrence_frequency')) ?? RecurrenceFrequency::None;
        $interval = $value('recurrence_interval');

        $assigneeId = array_key_exists('assignee_id', $data)
            ? $data['assignee_id']
            : (array_key_exists('owner_id', $data) ? $data['owner_id'] : ($existing === null ? $actor->getKey() : $existing->assignee_id));

        $attributes = [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'kind' => $kind,
            'priority' => self::enum(TaskPriority::class, $value('priority')) ?? TaskPriority::Medium,
            'due_at' => self::date($value('due_at')),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reminder_at' => self::date($value('reminder_at')),
            'assignee_id' => self::id($assigneeId),
            'recurrence_frequency' => $frequency,
            'recurrence_interval' => $frequency->repeats() ? max(1, (int) ($interval ?? 1)) : null,
            'recurrence_ends_at' => $frequency->repeats() ? self::date($value('recurrence_ends_at'))?->startOfDay() : null,
        ];

        foreach (SubjectPickers::COLUMNS as $column) {
            $attributes[$column] = self::id($value($column));
        }

        return $attributes;
    }

    /**
     * Meetings and calls carry a start and an end (the calendar span); the
     * other kinds keep none, whatever a form or an importer submits.
     */
    public static function kindHasTimeSpan(TaskKind $kind): bool
    {
        return $kind === TaskKind::Meeting || $kind === TaskKind::Call;
    }

    private function assertSpan(?Carbon $startsAt, ?Carbon $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThan($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => __('tasks.validation.ends_before_starts')]);
        }
    }

    /**
     * Runs the callback with the actor as the ledger causer, so the rows
     * LogsActivity writes name who acted even outside a web request, and
     * restores the default resolution afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asCauser(User $actor, Closure $callback): mixed
    {
        $this->causers->setCauser($actor);

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

    private static function date(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
