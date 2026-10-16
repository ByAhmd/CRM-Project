<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Schedules the next occurrence of a recurring task (decision A-10).
 *
 * Called by TaskService once a task is completed: the next due date is the
 * completed task's due date moved forward by `recurrence_interval` units of
 * the frequency (months without overflow, so the 31st becomes the 28th or
 * 29th in February), and the start, end and reminder times move by the same
 * amount so a "one hour before" reminder stays one hour before. The series
 * stops once the next due date falls after `recurrence_ends_at`. The new
 * task is a copy of the completed one — same title, kind, priority,
 * assignee, co-assignees (D-28), assigner, linked records and recurrence settings — pointing at
 * the first task of the series; its creation is audited by LogsActivity as
 * the causer TaskService has set. Its checklist is the completed task's,
 * copied unticked and in order (D-25).
 *
 * An occurrence is scheduled once: completing a task whose next occurrence
 * already exists (a completion reopened by hand and completed again) adds
 * no second copy. The «تراجع» of a completion (D-27) withdraws the
 * occurrence that completion scheduled while nobody has touched it
 * (untouchedSuccessor()).
 */
final class TaskRecurrence
{
    public function __construct(
        private readonly TaskChecklist $checklist,
    ) {}

    public function next(Task $completed): ?Task
    {
        $frequency = $completed->recurrence_frequency;
        $due = $completed->due_at;
        $nextDue = self::nextDue($completed);

        if ($nextDue === null || $due === null) {
            return null;
        }

        // Already scheduled (the task was reopened and completed again): never a second copy.
        if (self::occurrences($completed, $nextDue)->exists()) {
            return null;
        }

        $endsAt = $completed->recurrence_ends_at;

        $shiftSeconds = (int) round($due->diffInSeconds($nextDue));
        $shift = static fn (?Carbon $date): ?Carbon => $date?->copy()->addSeconds($shiftSeconds);

        $next = new Task([
            'title' => $completed->title,
            'description' => $completed->description,
            'kind' => $completed->kind,
            'status' => TaskStatus::Pending,
            'priority' => $completed->priority,
            'due_at' => $nextDue,
            'starts_at' => $shift($completed->starts_at),
            'ends_at' => $shift($completed->ends_at),
            'reminder_at' => $shift($completed->reminder_at),
            'assignee_id' => $completed->assignee_id,
            'lead_id' => $completed->lead_id,
            'contact_id' => $completed->contact_id,
            'account_id' => $completed->account_id,
            'deal_id' => $completed->deal_id,
            'recurrence_frequency' => $frequency,
            'recurrence_interval' => $completed->recurrence_interval,
            'recurrence_ends_at' => $endsAt,
            'series_id' => $completed->series_id ?? $completed->getKey(),
            'created_by' => $completed->created_by,
        ]);

        // The occurrence keeps the series' assigner (D-14): completing it
        // must tell the person who handed the series out, not fall back to
        // the creator. Guarded column — trusted services write it directly.
        $next->assigned_by = $completed->assigned_by;

        $next->save();

        // A shared series stays shared (D-28): each co-assignee holds the
        // next occurrence too, added by whoever added them to the series and
        // reached by it now. Nobody is told — the series simply continues.
        foreach (TaskCoAssignee::query()->where('task_id', $completed->getKey())->orderBy('id')->get() as $row) {
            if ($next->assignee_id !== null && (int) $row->user_id === (int) $next->assignee_id) {
                continue;
            }

            TaskCoAssignee::query()->create([
                'task_id' => $next->getKey(),
                'user_id' => $row->user_id,
                'assigned_by' => $row->assigned_by,
                'assigned_at' => $next->freshTimestamp(),
            ]);
        }

        $this->checklist->copyInto($next, TaskChecklist::bodies($completed));

        return $next;
    }

    /**
     * The occurrence that completing $completed at $completedAt scheduled,
     * locked, while it is exactly as scheduled: still pending, not edited
     * since (its `updated_at` is its `created_at` — a change of who holds it,
     * co-assignees included, moves it too, D-28), with no entry in its
     * progress log or thread, no ticked checklist item and no file. Null
     * when there is none, or when someone has already started on it — that
     * work is never thrown away.
     */
    public function untouchedSuccessor(Task $completed, Carbon $completedAt): ?Task
    {
        $nextDue = self::nextDue($completed);

        if ($nextDue === null) {
            return null;
        }

        $task = new Task;

        return self::occurrences($completed, $nextDue)
            ->where('status', TaskStatus::Pending->value)
            ->where('created_at', '>=', $completedAt)
            ->whereColumn('updated_at', 'created_at')
            ->whereDoesntHave('updates')
            ->whereDoesntHave('checklistItems', static fn (Builder $items): Builder => $items->whereNotNull('completed_at'))
            ->whereNotExists(static fn (QueryBuilder $files): QueryBuilder => $files
                ->selectRaw('1')
                ->from('attachments')
                ->where('attachable_type', $task->getMorphClass())
                ->whereColumn('attachable_id', $task->qualifyColumn('id')))
            ->lockForUpdate()
            ->first();
    }

    /** The next occurrence's due moment, or null when the task does not repeat or the series has ended. */
    private static function nextDue(Task $completed): ?Carbon
    {
        $frequency = $completed->recurrence_frequency;
        $due = $completed->due_at;

        if (! $frequency->repeats() || $due === null) {
            return null;
        }

        $nextDue = self::advance($due, $frequency, max(1, (int) ($completed->recurrence_interval ?? 1)));
        $endsAt = $completed->recurrence_ends_at;

        if ($endsAt !== null && $nextDue->greaterThan($endsAt->copy()->endOfDay())) {
            return null;
        }

        return $nextDue;
    }

    /**
     * The series' live occurrences due at $due, other than $completed itself.
     *
     * @return Builder<Task>
     */
    private static function occurrences(Task $completed, Carbon $due): Builder
    {
        return Task::query()
            ->where('series_id', $completed->series_id ?? $completed->getKey())
            ->whereKeyNot($completed->getKey())
            ->where('due_at', $due);
    }

    private static function advance(Carbon $date, RecurrenceFrequency $frequency, int $interval): Carbon
    {
        return match ($frequency) {
            RecurrenceFrequency::Daily => $date->copy()->addDays($interval),
            RecurrenceFrequency::Weekly => $date->copy()->addWeeks($interval),
            RecurrenceFrequency::Monthly => $date->copy()->addMonthsNoOverflow($interval),
            RecurrenceFrequency::None => $date->copy(),
        };
    }
}
