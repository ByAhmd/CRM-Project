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
use App\Enums\TaskUpdateKind;
use App\Enums\UserStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Support\SubjectPickers;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateMention;
use App\Models\User;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskMentionNotification;
use App\Notifications\TaskProgressNotification;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\CauserResolver;

/**
 * Every write to a task (decisions A-10, D-4, D-14, D-27): create, edit,
 * start, post a progress update, complete, cancel, reopen (and the undo of a
 * completion), reschedule and the quick move of an open task's due date.
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
 *   schedules the next occurrence when the task repeats; the assigner — or
 *   the creator when no assigner was ever recorded — is told the work is
 *   done (D-14), never the completer themselves and never a recipient who
 *   may not open the task (A-20);
 * - a task born with an assignee stamps `assigned_by` with the actor, and a
 *   task created for someone else tells that assignee exactly as a
 *   reassignment would (D-14); later changes of the column belong to
 *   RecordAssignmentService;
 * - the assignee reports on the work (D-14 amendment, 2026-09-28): starting
 *   moves a pending task to In progress, a progress update is a note on an
 *   open task, and both — with completions and reopenings — are kept in the
 *   task's append-only progress log (TaskUpdate); a start or an update tells
 *   the assigner under the same recipient rule as a completion;
 * - anyone who may view a task may comment on it (D-17), open or closed but
 *   never once deleted; a comment is an entry of the same append-only log
 *   (kind `comment`) and tells the task's participants — assignee, assigner
 *   (or creator), earlier commenters and colleagues mentioned earlier —
 *   never its author;
 * - a comment may mention colleagues (D-20): every active user holding
 *   `task.view_any` but the author, whatever the author's reach; other ids
 *   are dropped without complaint. Each mention is stored with the comment
 *   (append-only, like the thread), makes the colleague a participant who
 *   may open the task, and tells them with a "mentioned you" notice instead
 *   of the general comment notice;
 * - every notification leaves only after the write it reports has committed,
 *   and its mail through the queue, so a mail server can never fail or undo
 *   a task write;
 * - the reminder and overdue stamps are re-armed when the dates they belong
 *   to move (a reminder moved after it was sent is sent again) and when a
 *   task is reopened; a deleted task has no transitions until it is restored;
 * - a task may carry a checklist (D-25, rules in TaskChecklist): a create or
 *   an edit that submits `checklist` writes it — with the task's row locked,
 *   touching only that task's items — and an edit that leaves the key out
 *   keeps it; once the task is completed, cancelled or deleted the checklist
 *   is frozen, and reopening keeps its ticks. Ticking (tickChecklist) is a
 *   report on an open task, audited but never notified; completing with
 *   unticked items is allowed, and the completion's ledger entry and the
 *   assigner's notice carry how many were left.
 *
 * - a task may be shared (D-28): `assignee_ids` names every assignee, the
 *   first one first — the one the task is filed under (`assignee_id`) — and
 *   the others become its co-assignees, who hold the same task with its one
 *   status, checklist and thread. Sharing is handing out: naming anyone but
 *   the actor needs `task.assign`, every newly named user must be within the
 *   actor's assignment reach, and any change to who holds an existing task
 *   — its first assignee, its co-assignees or their order — needs
 *   `task.assign` too, whichever key carries it; each newly added
 *   co-assignee is told exactly as a first assignee is (D-14), keeps who
 *   added them and when (their own `assigned_by` / `assigned_at`, D-21), and
 *   the ledger gets one `task.assignees_changed` entry naming who joined and
 *   who left. Someone moved between first assignee and co-assignee already
 *   holds the task: they keep who handed it to them and since when, and are
 *   not told again. Anyone joining or leaving is an edit of the task, so its
 *   `updated_at` moves even when no column of its own changed. An edit
 *   without `assignee_ids` keeps the co-assignees as they are; the older
 *   `assignee_id` / `owner_id` keys still name the first assignee alone, and
 *   clearing it makes the next co-assignee first. A comment tells every
 *   assignee.
 *
 * Reassignment is RecordAssignmentService's job (audit + notification), so
 * the `assign` verb is not duplicated here: an edit that changes the
 * assignee hands that change to the assignment service.
 */
final class TaskService
{
    /** The longest start note or progress update, in characters. */
    public const int PROGRESS_TEXT_MAX = 2000;

    /** The longest comment, in characters (D-17). */
    public const int COMMENT_TEXT_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityRecorder $activities,
        private readonly TaskRecurrence $recurrence,
        private readonly RecordAssignmentService $assignment,
        private readonly CauserResolver $causers,
        private readonly RecordVisibilityResolver $resolver,
        private readonly TaskChecklist $checklist,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Task
    {
        $attributes = $this->attributes($data, null, $actor);
        $coAssigneeIds = self::coAssigneeIds($data) ?? [];
        $this->assertAssignableOnCreate($attributes['assignee_id'], $actor);
        $this->assertShareableOnCreate($coAssigneeIds, $actor);
        $checklist = array_key_exists('checklist', $data) ? TaskChecklist::normalise($data['checklist']) : [];

        return $this->asCauser($actor, fn (): Task => DB::transaction(function () use ($attributes, $coAssigneeIds, $actor, $checklist): Task {
            $task = new Task([
                ...$attributes,
                'status' => TaskStatus::Pending,
                'series_id' => null,
                'created_by' => $actor->getKey(),
            ]);

            // A task born with an assignee remembers who assigned it (D-14):
            // the actor, even for a task they created for themselves. The
            // column is not fillable — trusted services write it directly.
            if ($attributes['assignee_id'] !== null) {
                $task->assigned_by = $actor->getKey();
            }

            $task->save();

            // A new task has no items yet, so every submitted one is created
            // (an id the browser sent names nothing of this task).
            $this->recordChecklistChange($task, $actor, $this->checklist->sync($task, $checklist));

            $assignee = $task->assignee;

            if ($assignee !== null) {
                $this->assignment->announce($task, $assignee, $actor);
            }

            $this->recordCoAssigneeChange($task, $actor, ['added' => $this->addCoAssignees($task, $coAssigneeIds, $actor), 'removed' => []]);

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

        // Who holds the task (the first assignee and the co-assignees, D-28)
        // leaves the attribute diff: a change is an assignment, written after
        // the other attributes by applyHolders().
        unset($attributes['assignee_id']);

        $before = ['first' => $task->assignee_id === null ? null : (int) $task->assignee_id, 'co' => self::currentCoAssigneeIds($task)];
        $after = self::holdersAfter($data, $before);
        $this->assertHoldersChangeable($before, $after, $actor);

        // Absent means unchanged, like every other key; present — even
        // empty — means "this is the checklist now" (D-25).
        $checklist = array_key_exists('checklist', $data) ? TaskChecklist::normalise($data['checklist']) : null;

        return $this->asCauser($actor, fn (): Task => DB::transaction(function () use ($task, $attributes, $before, $after, $actor, $checklist): Task {
            // The row lock orders this edit with a concurrent tick or
            // completion, so the checklist sync below judges the items and the
            // status as they stand, and nobody's tick is lost to the sync.
            $current = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $this->saveRearming($task, $attributes);

            if ($after !== $before) {
                $this->applyHolders($task, $before, $after, $actor);
            }

            if ($checklist !== null) {
                $frozen = $current->trashed() || ! $current->isOpen();

                $this->recordChecklistChange($task, $actor, $this->checklist->sync($task, $checklist, $frozen));
            }

            return $task;
        }));
    }

    /**
     * The assignee takes the task up (D-14 amendment, 2026-09-28): Pending →
     * In progress, with an optional note. The progress log records it and
     * the assigner is told once the start has committed.
     */
    public function start(Task $task, User $actor, ?string $note = null): Task
    {
        $note = $this->progressText($note, required: false);

        $this->asCauser($actor, function () use ($task, $actor, $note): void {
            DB::transaction(function () use ($task, $actor, $note): void {
                $current = $this->lock($task);

                if ($current->status !== TaskStatus::Pending) {
                    throw InvalidTaskTransitionException::notPending();
                }

                Task::withoutWorkflowGuard(static function () use ($current): void {
                    $current->status = TaskStatus::InProgress;
                    $current->save();
                });

                $update = $this->log($current, $actor, TaskStatus::InProgress, $note);

                $this->audit->record(ActivityLogEvent::TaskStarted, $current, $actor, array_filter([
                    'subject_label' => $current->title,
                    'note' => $note,
                ], static fn (mixed $value): bool => $value !== null));

                $this->notifyAssignerOfProgress($current, $actor, $update);
            });
        });

        return $task->refresh();
    }

    /**
     * A progress note on an open task (D-14 amendment, 2026-09-28): what
     * happened so far, in the assignee's words. The status does not move;
     * the log keeps the note and the assigner is told once it has committed.
     */
    public function postUpdate(Task $task, User $actor, string $body): TaskUpdate
    {
        $body = $this->progressText($body, required: true);

        $update = $this->asCauser($actor, fn (): TaskUpdate => DB::transaction(function () use ($task, $actor, $body): TaskUpdate {
            $current = $this->lock($task);

            if (! $current->isOpen()) {
                throw InvalidTaskTransitionException::notOpenForUpdate();
            }

            $update = $this->log($current, $actor, null, $body);

            $this->audit->record(ActivityLogEvent::TaskProgressPosted, $current, $actor, [
                'subject_label' => $current->title,
                'note' => $body,
            ]);

            $this->notifyAssignerOfProgress($current, $actor, $update);

            return $update;
        }));

        // The caller's instance shows the new entry at once, like the
        // transitions' refresh does for the status.
        $task->refresh();

        return $update;
    }

    /**
     * A comment on the task (D-17) from anyone who may view it — the policy
     * decides that, as for every other verb. Open and closed tasks take
     * comments; a deleted one takes none until it is restored. The text is
     * trimmed, required and at most COMMENT_TEXT_MAX characters; the entry
     * joins the task's append-only thread and the participants are told once
     * it has committed.
     *
     * The comment may mention colleagues (D-20): of the given ids only
     * mentionable users are kept (mentionableUsers(); the rest are dropped
     * without complaint, as for note mentions), each stored with the comment
     * in the same transaction, named in its ledger entry and told with a
     * "mentioned you" notice in place of the general one. The text already
     * holds their "@Name" (CommentDraft); it is stored as written.
     *
     * @param  list<int>  $mentionUserIds
     */
    public function comment(Task $task, User $actor, string $body, array $mentionUserIds = []): TaskUpdate
    {
        $body = $this->commentText($body);
        $mentioned = $this->mentionedUsers($actor, $mentionUserIds);

        $comment = $this->asCauser($actor, fn (): TaskUpdate => DB::transaction(function () use ($task, $actor, $body, $mentioned): TaskUpdate {
            $current = $this->lock($task, commenting: true);

            $comment = $this->log($current, $actor, null, $body, TaskUpdateKind::Comment);

            foreach ($mentioned as $user) {
                TaskUpdateMention::query()->create([
                    'task_update_id' => $comment->getKey(),
                    'task_id' => $current->getKey(),
                    'user_id' => $user->getKey(),
                ]);
            }

            $this->audit->record(ActivityLogEvent::TaskCommented, $current, $actor, array_filter([
                'subject_label' => $current->title,
                'comment' => $body,
                'mentioned' => $mentioned === [] ? null : array_map(static fn (User $user): string => $user->name, $mentioned),
            ], static fn (mixed $value): bool => $value !== null));

            $this->notifyMentioned($current, $actor, $comment, $mentioned);
            $this->notifyParticipantsOfComment($current, $actor, $comment, $mentioned);

            return $comment;
        }));

        // The caller's instance shows the new entry at once.
        $task->refresh();

        return $comment;
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

                // Completing with unticked items is allowed (D-25); the count
                // left open is part of the record and of the assigner's notice.
                ['done' => $done, 'total' => $total] = TaskChecklist::counts($current);
                $open = $total - $done;

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
                    'checklist_open' => $total > 0 ? $open : null,
                    'checklist_total' => $total > 0 ? $total : null,
                ], static fn (mixed $value): bool => $value !== null));

                // The progress log keeps the completion too; completion has
                // its own notice below, so no progress notice goes with it.
                $this->log($current, $actor, TaskStatus::Completed, $note === '' ? null : $note);

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

                $this->notifyAssignerOfCompletion($current, $actor, $open, $total);
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
        return $this->reopenFrom($task, $actor, null);
    }

    /**
     * The «تراجع» (undo) of a one-click completion (D-27): a reopen that
     * holds only while the task is still completed — judged under the row
     * lock, so an undo that arrives after someone else reopened, cancelled
     * or deleted the task changes nothing. Who may undo is the policy's
     * `reopen` answer, exactly as for the Reopen action. On a repeating task
     * the occurrence the completion scheduled is withdrawn with it, unless
     * someone has already touched it (TaskRecurrence::untouchedSuccessor()).
     */
    public function undoCompletion(Task $task, User $actor): Task
    {
        return $this->reopenFrom($task, $actor, TaskStatus::Completed);
    }

    /**
     * Reopens a closed task; with $expected, only while it still has that
     * status.
     */
    private function reopenFrom(Task $task, User $actor, ?TaskStatus $expected): Task
    {
        $this->asCauser($actor, function () use ($task, $actor, $expected): void {
            DB::transaction(function () use ($task, $actor, $expected): void {
                $current = $this->lock($task);

                if ($expected !== null && $current->status !== $expected) {
                    throw InvalidTaskTransitionException::notCompleted();
                }

                if ($current->isOpen()) {
                    throw InvalidTaskTransitionException::notClosed();
                }

                $previous = $current->status;
                $completedAt = $current->completed_at;

                // The undo puts things back as they were before the click: the
                // next occurrence that completion scheduled is withdrawn while
                // nobody has touched it (soft-deleted, so the ledger keeps it);
                // completing again schedules it afresh. A plain reopen keeps
                // it, as before, and a later completion never duplicates it.
                $withdrawn = $expected === TaskStatus::Completed && $completedAt !== null
                    ? $this->recurrence->untouchedSuccessor($current, $completedAt)
                    : null;

                $withdrawn?->delete();

                // A reopened task is due and remindable again, so both
                // notification stamps are re-armed with the status. Its
                // checklist keeps its ticks (D-25): the work done stays done.
                Task::withoutWorkflowGuard(static function () use ($current): void {
                    $current->status = TaskStatus::Pending;
                    $current->completed_at = null;
                    $current->reminder_sent_at = null;
                    $current->overdue_notified_at = null;
                    $current->save();
                });

                $this->audit->record(ActivityLogEvent::TaskReopened, $current, $actor, array_filter([
                    'subject_label' => $current->title,
                    'previous_status' => $previous->value,
                    'withdrawn_occurrence_id' => $withdrawn === null ? null : (int) $withdrawn->getKey(),
                ], static fn (mixed $value): bool => $value !== null));

                $this->log($current, $actor, TaskStatus::Pending, null);
            });
        });

        return $task->refresh();
    }

    /**
     * Ticks and unticks checklist items of an open task (D-25): a report on
     * the work, so the policy asks `tickChecklist` (whoever may report on the
     * task). The task's row is locked, a closed or deleted task is refused,
     * only the task's own items are touched, each tick keeps who made it and
     * when, and the ledger gets one entry naming what changed with the
     * progress after it. Nobody is notified.
     *
     * @param  list<int>  $check
     * @param  list<int>  $uncheck
     */
    public function tickChecklist(Task $task, User $actor, array $check, array $uncheck = []): Task
    {
        $check = self::ids($check);
        $uncheck = self::ids($uncheck);

        $this->asCauser($actor, function () use ($task, $actor, $check, $uncheck): void {
            DB::transaction(function () use ($task, $actor, $check, $uncheck): void {
                $current = $this->lock($task, checklist: true);

                if (! $current->isOpen()) {
                    throw InvalidTaskTransitionException::checklistNotOpen();
                }

                $changes = $this->checklist->tick($current, $actor, $check, $uncheck);

                if ($changes['checked'] === [] && $changes['unchecked'] === []) {
                    return;
                }

                ['done' => $done, 'total' => $total] = TaskChecklist::counts($current);

                $this->audit->record(ActivityLogEvent::TaskChecklistTicked, $current, $actor, array_filter([
                    'subject_label' => $current->title,
                    'checklist_checked' => $changes['checked'],
                    'checklist_unchecked' => $changes['unchecked'],
                    'checklist_done' => $done,
                    'checklist_total' => $total,
                ], static fn (mixed $value): bool => $value !== []));
            });
        });

        return $task->refresh();
    }

    /**
     * Writes a checklist structure change to the ledger on the task (D-25),
     * when there is one: the items added, removed and renamed, and whether
     * the kept ones were reordered.
     *
     * @param  array{added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, reordered: bool}  $changes
     */
    private function recordChecklistChange(Task $task, User $actor, array $changes): void
    {
        if ($changes['added'] === [] && $changes['removed'] === [] && $changes['renamed'] === [] && ! $changes['reordered']) {
            return;
        }

        $this->audit->record(ActivityLogEvent::TaskChecklistChanged, $task, $actor, array_filter([
            'subject_label' => $task->title,
            'checklist_added' => $changes['added'],
            'checklist_removed' => $changes['removed'],
            'checklist_renamed' => $changes['renamed'],
            'checklist_reordered' => $changes['reordered'] ? true : null,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null));
    }

    /**
     * Moves an open task to another due moment (D-27: the quick reschedule
     * chips). The row is locked; a deleted task is refused as every write
     * is, a completed or cancelled one keeps its date. A meeting or a call
     * that carries a span moves with it, its start and end shifted by the
     * same amount (or, when the task had no due date, starting at the new
     * moment with its old length). Who may move it is the policy's `update`
     * answer — never the handed-out assignee (D-14 amendment). The stamps
     * are re-armed and the diff reaches the ledger as `task.updated`.
     */
    public function moveDue(Task $task, Carbon $dueAt, User $actor): Task
    {
        $this->asCauser($actor, function () use ($task, $dueAt): void {
            DB::transaction(function () use ($task, $dueAt): void {
                $current = $this->lock($task);

                if ($current->isClosed()) {
                    throw InvalidTaskTransitionException::notOpenForReschedule();
                }

                $startsAt = $current->starts_at;
                $endsAt = $current->ends_at;

                if (self::kindHasTimeSpan($current->kind) && $startsAt !== null) {
                    $shift = $current->due_at !== null
                        ? $dueAt->getTimestamp() - $current->due_at->getTimestamp()
                        : $dueAt->getTimestamp() - $startsAt->getTimestamp();

                    $startsAt = $startsAt->copy()->addSeconds($shift);
                    $endsAt = $endsAt?->copy()->addSeconds($shift);
                }

                $this->saveRearming($current, [
                    'due_at' => $dueAt,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
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
     * Tells the person who handed the task out that it is done (D-14). The
     * recipient rule is reportRecipient()'s; the message leaves once the
     * completion has committed.
     */
    private function notifyAssignerOfCompletion(Task $task, User $actor, int $checklistOpen, int $checklistTotal): void
    {
        $recipient = $this->reportRecipient($task, $actor);

        if ($recipient === null) {
            return;
        }

        DB::afterCommit(static function () use ($recipient, $task, $actor, $checklistOpen, $checklistTotal): void {
            $recipient->notify((new TaskCompletedNotification($task, $actor, $checklistOpen, $checklistTotal))->locale($recipient->preferredLocale()));
        });
    }

    /**
     * Tells the person who handed the task out that it was started or that
     * progress was posted (D-14 amendment, 2026-09-28) — the same recipient
     * as a completion, once the entry has committed.
     */
    private function notifyAssignerOfProgress(Task $task, User $actor, TaskUpdate $update): void
    {
        $recipient = $this->reportRecipient($task, $actor);

        if ($recipient === null) {
            return;
        }

        DB::afterCommit(static function () use ($recipient, $task, $actor, $update): void {
            $recipient->notify((new TaskProgressNotification($task, $actor, $update))->locale($recipient->preferredLocale()));
        });
    }

    /**
     * Tells each colleague the comment mentions that they were pulled into
     * the task (D-20), once it has committed — only those who may sign in
     * and open the task (A-20), which the stored mention itself now allows.
     *
     * @param  list<User>  $mentioned
     */
    private function notifyMentioned(Task $task, User $actor, TaskUpdate $comment, array $mentioned): void
    {
        $recipients = array_values(array_filter(
            $mentioned,
            static fn (User $user): bool => $user->status->canAuthenticate() && $user->can('view', $task),
        ));

        if ($recipients === []) {
            return;
        }

        DB::afterCommit(static function () use ($recipients, $task, $actor, $comment): void {
            foreach ($recipients as $recipient) {
                $recipient->notify((new TaskMentionNotification($task, $actor, $comment))->locale($recipient->preferredLocale()));
            }
        });
    }

    /**
     * Tells the task's participants about a new comment (D-17), once it has
     * committed; the recipients are commentRecipients()'s, less the users the
     * comment mentions — they have their own notice (D-20).
     *
     * @param  list<User>  $mentioned
     */
    private function notifyParticipantsOfComment(Task $task, User $actor, TaskUpdate $comment, array $mentioned = []): void
    {
        $mentionedIds = array_map(static fn (User $user): int => (int) $user->getKey(), $mentioned);

        $recipients = $this->commentRecipients($task, $actor)
            ->reject(static fn (User $user): bool => in_array((int) $user->getKey(), $mentionedIds, true))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        DB::afterCommit(static function () use ($recipients, $task, $actor, $comment): void {
            foreach ($recipients as $recipient) {
                $recipient->notify((new TaskCommentNotification($task, $actor, $comment))->locale($recipient->preferredLocale()));
            }
        });
    }

    /**
     * Whether a comment the actor posts on the task tells anyone (D-17) — the
     * comment modal promises a notice only when this holds, under the very
     * rule that sends it.
     */
    public function commentsReachSomeone(Task $task, User $actor): bool
    {
        return $this->commentRecipients($task, $actor)->isNotEmpty();
    }

    /**
     * Who hears about a comment on the task (D-17): its participants — every
     * assignee (D-28), the assigner (or the creator when no assigner was ever
     * recorded, as for a completion), everyone who commented on it before and
     * everyone a comment on it mentioned (D-20). Never the author, never a
     * deleted account or one that may no longer sign in (D-11), and — as on
     * every event path (A-20) — never someone who may not open the task: the
     * message quotes the comment and links to it.
     *
     * @return Collection<int, User>
     */
    private function commentRecipients(Task $task, User $actor): Collection
    {
        $ids = TaskUpdate::query()
            ->where('task_id', $task->getKey())
            ->where('kind', TaskUpdateKind::Comment->value)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->merge(TaskUpdateMention::query()->where('task_id', $task->getKey())->distinct()->pluck('user_id'))
            ->merge(TaskCoAssignee::query()->where('task_id', $task->getKey())->pluck('user_id'))
            ->push($task->assignee_id, $task->assigned_by ?? $task->created_by)
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(static fn (int $id): bool => $id === (int) $actor->getKey())
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->get()
            ->filter(static fn (User $user): bool => $user->status->canAuthenticate() && $user->can('view', $task))
            ->values();
    }

    /**
     * The colleagues the actor may mention in a task comment (D-20): every
     * user who may sign in (D-11, an active account that is not deleted) and
     * holds `task.view_any` through a role or a direct grant — so the mention
     * lets them open the task — except the actor. Deliberately not limited to
     * the actor's assignment reach: an employee pulls in a manager or a
     * colleague from another team. The comment editor lists these users and
     * comment() keeps only these; ordered by name.
     *
     * @return Builder<User>
     */
    public function mentionableUsers(User $actor): Builder
    {
        $viewAny = Permission::TaskViewAny->value;

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($actor->getKey())
            ->where(static function (Builder $holders) use ($viewAny): void {
                $holders
                    ->whereHas('permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny))
                    ->orWhereHas('roles.permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny));
            })
            ->orderBy('name');
    }

    /**
     * The mentionable users among the given ids, each once, in the order the
     * comment mentions them; every other id is dropped without complaint.
     *
     * @param  list<int>  $userIds
     * @return list<User>
     */
    private function mentionedUsers(User $actor, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $users = $this->mentionableUsers($actor)->whereKey($ids)->get()->keyBy(static fn (User $user): int => (int) $user->getKey());

        return array_values(array_filter(array_map(
            static fn (int $id): ?User => $users->get($id),
            $ids,
        )));
    }

    /**
     * Whether a start or an update the actor posts on the task tells anyone
     * (D-14 amendment, 2026-09-28) — the report modals promise a notice only
     * when this holds, under the very rule that sends it.
     */
    public function reportsReachSomeone(Task $task, User $actor): bool
    {
        return $this->reportRecipient($task, $actor) !== null;
    }

    /**
     * Who hears about the work on a task (D-14): the assigner, or the creator
     * when no assigner was ever recorded (tasks created before D-14,
     * unassigned tasks). A recorded assigner who has since been deleted or
     * disabled is not replaced by the creator — nobody is told, whichever way
     * the assigner left. The actor never notifies themselves, an account that
     * may no longer sign in receives nothing (D-11), and — as on every other
     * event path (A-20) — neither does a recipient who may not open the task:
     * the message carries the task's title and a view link.
     */
    private function reportRecipient(Task $task, User $actor): ?User
    {
        $recipient = $task->assigned_by !== null ? $task->assigner : $task->creator;

        if ($recipient === null || $recipient->is($actor) || ! $recipient->status->canAuthenticate() || ! $recipient->can('view', $task)) {
            return null;
        }

        return $recipient;
    }

    /** One entry of the task's thread, written inside the caller's transaction. */
    private function log(Task $task, User $actor, ?TaskStatus $status, ?string $body, TaskUpdateKind $kind = TaskUpdateKind::Progress): TaskUpdate
    {
        return TaskUpdate::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $actor->getKey(),
            'kind' => $kind,
            'status' => $status,
            'body' => $body,
        ]);
    }

    /**
     * The text of a start note or a progress update: trimmed, at most
     * PROGRESS_TEXT_MAX characters, and — for an update — required. An
     * empty optional note is null.
     */
    private function progressText(?string $text, bool $required): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            if ($required) {
                throw ValidationException::withMessages(['body' => __('tasks.validation.update_body_required')]);
            }

            return null;
        }

        if (mb_strlen($text) > self::PROGRESS_TEXT_MAX) {
            throw ValidationException::withMessages(['body' => __('tasks.validation.update_body_too_long', ['max' => self::PROGRESS_TEXT_MAX])]);
        }

        return $text;
    }

    /**
     * The text of a comment (D-17): trimmed, required and at most
     * COMMENT_TEXT_MAX characters.
     */
    private function commentText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages(['comment' => __('tasks.validation.comment_body_required')]);
        }

        if (mb_strlen($text) > self::COMMENT_TEXT_MAX) {
            throw ValidationException::withMessages(['comment' => __('tasks.validation.comment_body_too_long', ['max' => self::COMMENT_TEXT_MAX])]);
        }

        return $text;
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
     * Sharing a new task is handing it out (D-28): naming co-assignees needs
     * `task.assign`, and every one of them must be within the actor's
     * assignment reach — the same rule as a first assignee. An edit follows
     * assertHoldersChangeable().
     *
     * @param  list<int>  $coAssigneeIds
     */
    private function assertShareableOnCreate(array $coAssigneeIds, User $actor): void
    {
        if ($coAssigneeIds === []) {
            return;
        }

        if (! $actor->can(Permission::TaskAssign->value)) {
            throw UnassignableUserException::make();
        }

        if ($this->resolver->assignableUsers($actor, Task::permissionGroup())->whereKey($coAssigneeIds)->count() !== count($coAssigneeIds)) {
            throw UnassignableUserException::make();
        }
    }

    /**
     * Who holds the task after an edit (D-28), from what the edit submitted
     * and who holds it now: `assignee_ids` names everyone, the first one
     * first; the older `assignee_id` / `owner_id` keys (the Sales task tabs)
     * name the first assignee alone and keep the co-assignees — and clearing
     * the first assignee there makes the next co-assignee first, so a shared
     * task never drops to unassigned while others still hold it (the earliest
     * whose account is not deleted, so the task is not filed under a removed
     * account while a colleague still holds it; the earliest of all when only
     * deleted ones remain); without any of them nothing changes. The
     * co-assignees are in the order they were added (or named), the first
     * assignee never among them.
     *
     * @param  array<string, mixed>  $data
     * @param  array{first: ?int, co: list<int>}  $before
     * @return array{first: ?int, co: list<int>}
     */
    private static function holdersAfter(array $data, array $before): array
    {
        if (array_key_exists('assignee_ids', $data)) {
            $ids = self::ids(is_array($data['assignee_ids']) ? $data['assignee_ids'] : []);

            return ['first' => $ids[0] ?? null, 'co' => array_slice($ids, 1)];
        }

        $key = match (true) {
            array_key_exists('assignee_id', $data) => 'assignee_id',
            array_key_exists('owner_id', $data) => 'owner_id',
            default => null,
        };

        if ($key === null) {
            return $before;
        }

        $first = self::id($data[$key]);
        $co = array_values(array_filter($before['co'], static fn (int $id): bool => $id !== $first));

        if ($first === null && $co !== []) {
            $live = User::query()->whereKey($co)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $next = array_values(array_filter($co, static fn (int $id): bool => in_array($id, $live, true)))[0] ?? $co[0];
            $first = $next;
            $co = array_values(array_filter($co, static fn (int $id): bool => $id !== $next));
        }

        return ['first' => $first, 'co' => $co];
    }

    /**
     * Only a holder of `task.assign` changes who holds an existing task
     * (D-14, D-28) — its first assignee, its co-assignees, or which of them
     * comes first — whichever key the edit used, and everyone who newly
     * joins it must be within the actor's assignment reach. Keeping the
     * holders as they are (a disabled colleague among them) needs nothing;
     * neither does the reach of someone who already holds the task.
     *
     * @param  array{first: ?int, co: list<int>}  $before
     * @param  array{first: ?int, co: list<int>}  $after
     */
    private function assertHoldersChangeable(array $before, array $after, User $actor): void
    {
        $sorted = static function (array $ids): array {
            sort($ids);

            return $ids;
        };

        if ($after['first'] === $before['first'] && $sorted($after['co']) === $sorted($before['co'])) {
            return;
        }

        if (! $actor->can(Permission::TaskAssign->value)) {
            throw UnassignableUserException::make();
        }

        $joining = array_values(array_diff(self::everyone($after), self::everyone($before)));

        if ($joining !== [] && $this->resolver->assignableUsers($actor, Task::permissionGroup())->whereKey($joining)->count() !== count($joining)) {
            throw UnassignableUserException::make();
        }
    }

    /**
     * Writes a change of who holds the task (D-28), in the order that keeps
     * it whole: the co-assignees who leave it altogether go first, then the
     * first assignee changes through RecordAssignmentService (audit, notice;
     * a co-assignee moved up keeps who handed them the task and since when,
     * and is not told again), then the co-assignees who join get their rows.
     * A first assignee moved down to co-assignee keeps the task's stamps —
     * who handed it to them and when it reached them (D-14, D-21) — and is
     * not told either; only those who truly joined or left are named in the
     * `task.assignees_changed` ledger entry.
     *
     * @param  array{first: ?int, co: list<int>}  $before
     * @param  array{first: ?int, co: list<int>}  $after
     */
    private function applyHolders(Task $task, array $before, array $after, User $actor): void
    {
        $previous = ['assigned_by' => $task->assigned_by, 'assigned_at' => $task->assigned_at];

        $removed = $this->dropCoAssignees($task, self::everyone($after));

        if ($after['first'] !== $before['first']) {
            $first = $after['first'] === null ? null : User::withTrashed()->find($after['first']);

            if ($after['first'] !== null && ! $first instanceof User) {
                throw UnassignableUserException::make();
            }

            $this->assignment->assign($task, $first, $actor);
        }

        $carried = $before['first'] !== null && in_array($before['first'], $after['co'], true)
            ? [$before['first'] => $previous]
            : [];

        $added = $this->addCoAssignees($task, $after['co'], $actor, $carried);

        // Someone joining or leaving changes the task, though only rows beside
        // it were written: its `updated_at` moves as a change of the first
        // assignee moves it, so an occurrence an administrator has shared
        // further is never read as untouched and withdrawn by the undo of the
        // completion that scheduled it (D-27, TaskRecurrence::untouchedSuccessor()).
        if ($added !== [] || $removed !== []) {
            $task->touch();
        }

        $this->recordCoAssigneeChange($task, $actor, ['added' => $added, 'removed' => $removed]);
    }

    /**
     * Removes the co-assignee rows of everyone not among the given holders
     * (D-28), one by one as models so the visibility memo hears of it, and
     * returns who left.
     *
     * @param  list<int>  $keep
     * @return list<User>
     */
    private function dropCoAssignees(Task $task, array $keep): array
    {
        $removed = [];

        $leaving = TaskCoAssignee::query()
            ->where('task_id', $task->getKey())
            ->when($keep !== [], static fn (Builder $rows): Builder => $rows->whereNotIn('user_id', $keep))
            ->with('user')
            ->orderBy('id')
            ->get();

        foreach ($leaving as $row) {
            if ($row->user instanceof User) {
                $removed[] = $row->user;
            }

            $row->delete();
        }

        $task->unsetRelation('coAssignees');

        return $removed;
    }

    /**
     * Writes a row for each of the given co-assignees who holds none yet
     * (D-28) and returns who joined. A joiner is added by the actor and
     * reached by the task now, and told once the write commits, as a first
     * assignee is (D-14). A user in $carried already held the task — its
     * first assignee moved down — so their row keeps the given stamps, they
     * are not told and they did not join.
     *
     * @param  list<int>  $coAssigneeIds
     * @param  array<int, array{assigned_by: ?int, assigned_at: ?Carbon}>  $carried
     * @return list<User>
     */
    private function addCoAssignees(Task $task, array $coAssigneeIds, User $actor, array $carried = []): array
    {
        $current = TaskCoAssignee::query()->where('task_id', $task->getKey())->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $firstId = $task->assignee_id === null ? null : (int) $task->assignee_id;

        $joining = array_values(array_filter(
            array_diff($coAssigneeIds, $current),
            static fn (int $id): bool => $id !== $firstId,
        ));

        if ($joining === []) {
            return [];
        }

        $users = User::withTrashed()->whereKey($joining)->get()->keyBy(static fn (User $user): int => (int) $user->getKey());
        $added = [];

        foreach ($joining as $userId) {
            $user = $users->get($userId);

            if (! $user instanceof User) {
                continue;
            }

            $stamps = $carried[$userId] ?? null;

            TaskCoAssignee::query()->create([
                'task_id' => $task->getKey(),
                'user_id' => $userId,
                'assigned_by' => $stamps === null ? $actor->getKey() : $stamps['assigned_by'],
                'assigned_at' => $stamps['assigned_at'] ?? now(),
            ]);

            if ($stamps === null) {
                $this->assignment->announce($task, $user, $actor);
                $added[] = $user;
            }
        }

        $task->unsetRelation('coAssignees');

        return $added;
    }

    /**
     * Everyone who holds the task in the given state: the first assignee and
     * the co-assignees.
     *
     * @param  array{first: ?int, co: list<int>}  $holders
     * @return list<int>
     */
    private static function everyone(array $holders): array
    {
        return array_values(array_filter([$holders['first'], ...$holders['co']], static fn (?int $id): bool => $id !== null));
    }

    /**
     * Writes a change of co-assignees to the ledger on the task (D-28), when
     * there is one: the names of who joined and who left.
     *
     * @param  array{added: list<User>, removed: list<User>}  $changes
     */
    private function recordCoAssigneeChange(Task $task, User $actor, array $changes): void
    {
        if ($changes['added'] === [] && $changes['removed'] === []) {
            return;
        }

        $names = static fn (array $users): array => array_map(static fn (User $user): string => $user->name, $users);

        $this->audit->record(ActivityLogEvent::TaskAssigneesChanged, $task, $actor, array_filter([
            'subject_label' => $task->title,
            'assignees_added' => $names($changes['added']),
            'assignees_removed' => $names($changes['removed']),
        ], static fn (mixed $value): bool => $value !== []));
    }

    /**
     * The co-assignees a create or an edit asks for (D-28): every id of
     * `assignee_ids` after the first (the first assignee), in order, each
     * once; null when the data carries no `assignee_ids` (unchanged).
     *
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private static function coAssigneeIds(array $data): ?array
    {
        if (! array_key_exists('assignee_ids', $data)) {
            return null;
        }

        return array_slice(self::ids(is_array($data['assignee_ids']) ? $data['assignee_ids'] : []), 1);
    }

    /**
     * The task's current co-assignees, in the order they were added.
     *
     * @return list<int>
     */
    private static function currentCoAssigneeIds(Task $task): array
    {
        return TaskCoAssignee::query()
            ->where('task_id', $task->getKey())
            ->orderBy('id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The row locked for the transition, so two concurrent completions cannot
     * both spawn the next occurrence. A deleted task has no transitions — and
     * takes no comments — until it is restored.
     */
    private function lock(Task $task, bool $commenting = false, bool $checklist = false): Task
    {
        /** @var Task $current */
        $current = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

        if ($current->trashed()) {
            throw match (true) {
                $commenting => InvalidTaskTransitionException::commentOnTrashed(),
                $checklist => InvalidTaskTransitionException::checklistNotOpen(),
                default => InvalidTaskTransitionException::trashed(),
            };
        }

        return $current;
    }

    /**
     * The attributes a create or an edit writes, resolved from the submitted
     * data with the existing task (on edit) or the defaults (on create) filling
     * the keys the caller left out. The form's `owner_id` (OwnerSelect) is an
     * alias of `assignee_id`, and the first of `assignee_ids` (D-28) wins over
     * both; the guarded workflow columns are never read.
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

        $assigneeId = match (true) {
            // D-28: every assignee, the first one first.
            array_key_exists('assignee_ids', $data) => self::ids(is_array($data['assignee_ids']) ? $data['assignee_ids'] : [])[0] ?? null,
            array_key_exists('assignee_id', $data) => $data['assignee_id'],
            array_key_exists('owner_id', $data) => $data['owner_id'],
            default => $existing === null ? $actor->getKey() : $existing->assignee_id,
        };

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

    /**
     * The well-formed, positive ids among those a form or a request sent,
     * each once; anything else is dropped.
     *
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
