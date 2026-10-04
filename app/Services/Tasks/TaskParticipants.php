<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskUpdateMention;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\TransactionRolledBack;
use WeakMap;

/**
 * The participant read path of tasks (decision D-20): a user mentioned in a
 * comment on a task may open that task, beside the own/team/all scope and the
 * linked-record path.
 *
 * TaskPolicy::view asks includes() for one task at a time — on a task list
 * that is every row, more than once — so the ids of the tasks a user was
 * mentioned on are read in one query per user per request and kept for the
 * rest of it; the class is container-scoped for that reason, like
 * RecordVisibilityResolver. A new mention (TaskService writes them inside
 * the comment's transaction, and the recipients of that comment are judged
 * before it commits) and any rolled-back transaction clear the memo, so a
 * check is never served a participation that was not, or is no longer, in
 * the database. List queries use the subquery of taskIdsQuery() instead and
 * stay one statement.
 */
#[Scoped]
final class TaskParticipants
{
    /**
     * Per user id, the ids of the tasks they were mentioned on, as keys.
     *
     * @var array<int, array<int, true>>
     */
    private array $taskIds = [];

    /**
     * The event dispatchers the memo flush is registered on, so the listeners
     * are wired once per application rather than once per resolved instance.
     *
     * @var WeakMap<Dispatcher, true>|null
     */
    private static ?WeakMap $flushRegisteredOn = null;

    public function __construct(Dispatcher $events)
    {
        self::$flushRegisteredOn ??= new WeakMap;

        if (isset(self::$flushRegisteredOn[$events])) {
            return;
        }

        self::$flushRegisteredOn[$events] = true;

        $flush = static function (): void {
            if (app()->resolved(self::class)) {
                app(self::class)->forget();
            }
        };

        $events->listen('eloquent.created: '.TaskUpdateMention::class, $flush);
        $events->listen(TransactionRolledBack::class, $flush);
    }

    /** Whether a comment on the task mentioned the user. */
    public function includes(User $user, Task $task): bool
    {
        $userId = (int) $user->getKey();

        $this->taskIds[$userId] ??= array_fill_keys(
            TaskUpdateMention::query()
                ->where('user_id', $userId)
                ->distinct()
                ->pluck('task_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            true,
        );

        return isset($this->taskIds[$userId][(int) $task->getKey()]);
    }

    /**
     * The ids of the tasks the user was mentioned on, as a subquery for a
     * `whereIn` on tasks.id.
     *
     * @return Builder<TaskUpdateMention>
     */
    public function taskIdsQuery(User $user): Builder
    {
        return TaskUpdateMention::query()
            ->select('task_id')
            ->where('user_id', $user->getKey());
    }

    /** Drops the memoised participations (a mention was written or a write rolled back). */
    public function forget(): void
    {
        $this->taskIds = [];
    }
}
