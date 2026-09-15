<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReminderNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The two scheduled notifications about tasks (decisions A-10, D-1).
 *
 * Both passes are idempotent: a task is reminded about once (`reminder_sent_at`)
 * and reported overdue once (`overdue_notified_at`), so the scheduler may
 * run the commands every minute on shared hosting without a queue worker
 * and a re-run after a failure never repeats a message. A send that fails
 * (a broken mailbox) is reported and does not stall the pass: the task is
 * stamped anyway, because the in-app entry is written before mail is
 * attempted. An assignee who may no longer sign in is skipped but the task is
 * stamped all the same. Only open tasks with an assignee qualify; the stamps are
 * written quietly, outside the workflow guard, because they are
 * bookkeeping rather than a business change worth an audit row.
 */
final class TaskReminderService
{
    private const int CHUNK = 100;

    /**
     * Reminds the assignee of every open task whose reminder time has
     * passed and returns how many were sent.
     */
    public function sendDueReminders(Carbon $now): int
    {
        return $this->notify(
            $this->candidates()
                ->whereNull('reminder_sent_at')
                ->where('reminder_at', '<=', $now),
            static fn (Task $task): Notification => new TaskReminderNotification($task),
            'reminder_sent_at',
            $now,
        );
    }

    /**
     * Tells the assignee once that an open task is past its due date and
     * returns how many were notified.
     */
    public function notifyOverdue(Carbon $now): int
    {
        return $this->notify(
            $this->candidates()
                ->whereNull('overdue_notified_at')
                ->where('due_at', '<', $now),
            static fn (Task $task): Notification => new TaskOverdueNotification($task),
            'overdue_notified_at',
            $now,
        );
    }

    /**
     * @return Builder<Task>
     */
    private function candidates(): Builder
    {
        return Task::query()
            ->open()
            ->whereNotNull('assignee_id')
            ->with('assignee');
    }

    /**
     * @param  Builder<Task>  $query
     * @param  callable(Task): Notification  $notification
     */
    private function notify(Builder $query, callable $notification, string $stamp, Carbon $now): int
    {
        $count = 0;

        $query->chunkById(self::CHUNK, function (Collection $tasks) use ($notification, $stamp, $now, &$count): void {
            foreach ($tasks as $task) {
                $assignee = $task->assignee;

                if (! $assignee instanceof User) {
                    continue;
                }

                // An assignee who may no longer sign in (disabled, pending) is
                // not told; the task is still stamped, so the pass stays
                // idempotent and a later re-activation does not replay old
                // reminders.
                if (! $assignee->status->canAuthenticate()) {
                    Task::withoutWorkflowGuard(static fn (): bool => $task->forceFill([$stamp => $now])->saveQuietly());

                    continue;
                }

                try {
                    $assignee->notify($notification($task)->locale($assignee->preferredLocale()));
                } catch (Throwable $exception) {
                    // The channels are delivered in order and the database
                    // (bell) entry comes first, so a mail failure leaves an
                    // in-app notice behind: the task is stamped so the next
                    // pass does not repeat that entry, the failure is reported
                    // and the pass carries on with the remaining tasks.
                    report($exception);
                }

                Task::withoutWorkflowGuard(static fn (): bool => $task->forceFill([$stamp => $now])->saveQuietly());

                $count++;
            }
        });

        return $count;
    }
}
