<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Task;
use App\Models\User;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The status changes a reader may make on a task (decisions A-10, D-14
 * amendment, D-27): start, complete, reopen and cancel — the moves
 * TaskService performs, named by the TaskPolicy ability that governs each.
 *
 * appliesTo() is the workflow's own shape (Pending → start; an open task →
 * complete or cancel; a closed one → reopen; a deleted task → nothing), the
 * same states TaskService refuses outside of; allowedFor() adds the policy,
 * so a status menu offers exactly the moves the reader may make on that
 * task: a handed-out assignee never sees cancel, a mentioned participant
 * sees nothing, and a cancelled task reopens only for those who may edit it.
 */
enum TaskTransition: string implements HasColor, HasIcon, HasLabel
{
    case Start = 'start';
    case Complete = 'complete';
    case Reopen = 'reopen';
    case Cancel = 'cancel';

    public function getLabel(): string
    {
        return __('enums.task_transition.'.$this->value);
    }

    /** The colour of the status the move leads to, so the menu speaks the pills' language. */
    public function getColor(): string
    {
        return $this->targetStatus()->getColor();
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Start => Heroicon::OutlinedPlayCircle,
            self::Complete => Heroicon::OutlinedCheckCircle,
            self::Reopen => Heroicon::OutlinedArrowUturnLeft,
            self::Cancel => Heroicon::OutlinedXCircle,
        };
    }

    /** The TaskPolicy ability that decides whether the reader may make the move. */
    public function ability(): string
    {
        return $this->value;
    }

    public function targetStatus(): TaskStatus
    {
        return match ($this) {
            self::Start => TaskStatus::InProgress,
            self::Complete => TaskStatus::Completed,
            self::Reopen => TaskStatus::Pending,
            self::Cancel => TaskStatus::Cancelled,
        };
    }

    /** Whether the task's state admits the move at all, whoever asks. */
    public function appliesTo(Task $task): bool
    {
        if ($task->trashed()) {
            return false;
        }

        return match ($this) {
            self::Start => $task->status === TaskStatus::Pending,
            self::Complete, self::Cancel => $task->isOpen(),
            self::Reopen => $task->isClosed(),
        };
    }

    /**
     * The moves the user may make on the task now, in menu order.
     *
     * @return list<self>
     */
    public static function allowedFor(Task $task, User $user): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $transition): bool => $transition->appliesTo($task) && $user->can($transition->ability(), $task),
        ));
    }

    /**
     * The move that leads to the status, when one does — what a drop on a
     * board column asks for. In progress → Pending has no move: a started
     * task goes back only by being completed or cancelled and reopened.
     */
    public static function towards(TaskStatus $status, Task $task): ?self
    {
        foreach (self::cases() as $transition) {
            if ($transition->targetStatus() === $status && $transition->appliesTo($task)) {
                return $transition;
            }
        }

        return null;
    }
}
