<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Models\Task;
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
 * assignee, linked records and recurrence settings — pointing at the first
 * task of the series; its creation is audited by LogsActivity as the
 * causer TaskService has set.
 */
final class TaskRecurrence
{
    public function next(Task $completed): ?Task
    {
        $frequency = $completed->recurrence_frequency;
        $due = $completed->due_at;

        if (! $frequency->repeats() || $due === null) {
            return null;
        }

        $interval = max(1, (int) ($completed->recurrence_interval ?? 1));
        $nextDue = self::advance($due, $frequency, $interval);
        $endsAt = $completed->recurrence_ends_at;

        if ($endsAt !== null && $nextDue->greaterThan($endsAt->copy()->endOfDay())) {
            return null;
        }

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
        $next->save();

        return $next;
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
