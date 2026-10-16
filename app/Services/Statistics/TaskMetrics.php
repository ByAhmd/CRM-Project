<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The task numbers of the dashboard (decisions D-4, A-10).
 *
 * Every query starts from the viewer's visibility scope: the personal
 * lists are then pinned to the viewer as assignee — first assignee or
 * co-assignee of a shared task (D-28) — the follow-ups keep the whole scope
 * (a manager sees the team's, a rep their own). "Today" is the
 * organisation's calendar day (D-8): due today is an open task due at any
 * moment of that day, from its start to its end, whether the moment has
 * passed or not; overdue is an open task due before the start of that
 * day. The two never overlap and the attention list is their union,
 * soonest first — so the overdue ones lead. The widget colours the due
 * date with the same boundary (isOverdue()), so the list, the counts and
 * the colours agree.
 */
final class TaskMetrics
{
    public const UPCOMING_DAYS = 7;

    /** The kinds that count as a follow-up on the dashboard. */
    private const FOLLOW_UP_KINDS = [TaskKind::FollowUp, TaskKind::Call, TaskKind::Meeting];

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, Task>
     */
    public function myTasksToday(User $viewer): Collection
    {
        return $this->myTasksTodayQuery($viewer)->get();
    }

    /**
     * @return Builder<Task>
     */
    public function myTasksTodayQuery(User $viewer): Builder
    {
        $now = now();

        return $this->mine($viewer)
            ->whereBetween('tasks.due_at', [self::startOfOrganisationDay($now), self::endOfOrganisationDay($now)])
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id');
    }

    /**
     * @return Collection<int, Task>
     */
    public function myOverdue(User $viewer): Collection
    {
        return $this->myOverdueQuery($viewer)->get();
    }

    /**
     * @return Builder<Task>
     */
    public function myOverdueQuery(User $viewer): Builder
    {
        return $this->mine($viewer)
            ->where('tasks.due_at', '<', self::startOfOrganisationDay(now()))
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id');
    }

    /**
     * Whether the task is overdue by the dashboard's reading: open and due
     * before the start of the organisation's current day.
     */
    public function isOverdue(Task $task): bool
    {
        return $task->isOpen()
            && $task->due_at !== null
            && $task->due_at->lessThan(self::startOfOrganisationDay(now()));
    }

    /**
     * Whether the task is due inside the organisation's current calendar
     * day — the same boundary the lists use, so a widget colouring a due
     * date names the day it prints in the organisation timezone rather
     * than the application one.
     */
    public function isDueToday(Task $task): bool
    {
        if (! $task->isOpen() || $task->due_at === null) {
            return false;
        }

        $now = now();

        return $task->due_at->greaterThanOrEqualTo(self::startOfOrganisationDay($now))
            && $task->due_at->lessThanOrEqualTo(self::endOfOrganisationDay($now));
    }

    /**
     * Overdue and due today together — the overdue ones first, since they
     * are the oldest — for the attention list.
     *
     * @return Builder<Task>
     */
    public function myAttentionQuery(User $viewer): Builder
    {
        return $this->mine($viewer)
            ->where('tasks.due_at', '<=', self::endOfOrganisationDay(now()))
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id');
    }

    /**
     * Open follow-ups, calls and meetings due within the next N days,
     * across the viewer's whole reach. The window opens at the start of
     * the organisation's day — not at the current moment — so a follow-up
     * due earlier today is still surfaced; otherwise it would fall off
     * this list without appearing on the personal one, which is pinned to
     * the viewer as assignee.
     *
     * @return Collection<int, Task>
     */
    public function upcomingFollowUps(User $viewer, int $days = self::UPCOMING_DAYS): Collection
    {
        return $this->upcomingFollowUpsQuery($viewer, $days)->get();
    }

    /**
     * @return Builder<Task>
     */
    public function upcomingFollowUpsQuery(User $viewer, int $days = self::UPCOMING_DAYS): Builder
    {
        $now = now();

        return $this->scoped($viewer)
            ->whereIn('tasks.kind', array_map(static fn (TaskKind $kind): string => $kind->value, self::FOLLOW_UP_KINDS))
            ->whereBetween('tasks.due_at', [self::startOfOrganisationDay($now), self::endOfOrganisationDay($now->copy()->addDays(max(0, $days)))])
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id');
    }

    /**
     * Tasks due in the period within the viewer's reach, counted per
     * status; every status is present, at zero when empty. The owner and
     * team filters narrow to the tasks that owner — or a member of that
     * team — holds, as first assignee or co-assignee (D-28), each task
     * counted once.
     *
     * @return array<string, int>
     */
    public function countsByStatus(User $viewer, DashboardFilters $filters): array
    {
        $counts = array_fill_keys(array_map(static fn (TaskStatus $status): string => $status->value, TaskStatus::cases()), 0);

        $rows = self::constrainHolders($this->visibility->visible($viewer, Task::query()), $filters)
            ->whereBetween('tasks.due_at', [$filters->periodStart(), $filters->periodEnd()])
            ->selectRaw('tasks.status AS status, COUNT(*) AS total')
            ->groupBy('tasks.status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * DashboardFilters::constrainOwner() for tasks, where the owner is every
     * assignee (D-28): the chosen owner holds the task (Task::assignedTo()),
     * and a member of the chosen team holds it as its first assignee or as a
     * co-assignee. Only ever narrows — the filters were clamped to the
     * viewer's reach when they were built — and for an unshared task it
     * means exactly what the owner column alone meant.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private static function constrainHolders(Builder $query, DashboardFilters $filters): Builder
    {
        if ($filters->ownerId !== null) {
            $query->assignedTo($filters->ownerId);
        }

        if ($filters->teamId !== null) {
            $members = User::query()->select('id')->where('team_id', $filters->teamId);

            $query->where(static function (Builder $held) use ($members): void {
                $held->whereIn('tasks.assignee_id', $members)
                    ->orWhereIn('tasks.id', TaskCoAssignee::query()->select('task_co_assignees.task_id')->whereIn('task_co_assignees.user_id', $members));
            });
        }

        return $query;
    }

    /**
     * The start of the organisation's day containing the moment, back in
     * the application timezone for the query.
     */
    private static function startOfOrganisationDay(Carbon $moment): Carbon
    {
        return $moment->copy()
            ->setTimezone(DashboardFilters::timezone())
            ->startOfDay()
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * The end of the organisation's day containing the moment, back in the
     * application timezone for the query.
     */
    private static function endOfOrganisationDay(Carbon $moment): Carbon
    {
        return $moment->copy()
            ->setTimezone(DashboardFilters::timezone())
            ->endOfDay()
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * The viewer's own open tasks, inside their visibility scope: the ones
     * they hold as the first assignee or as a co-assignee of a shared task
     * (D-28).
     *
     * @return Builder<Task>
     */
    private function mine(User $viewer): Builder
    {
        return $this->scoped($viewer)->assignedTo($viewer);
    }

    /**
     * The open tasks the viewer may see (D-4), with what the widgets show —
     * every assignee of a shared task among it (D-28).
     *
     * @return Builder<Task>
     */
    private function scoped(User $viewer): Builder
    {
        return $this->visibility->visible($viewer, Task::query())
            ->whereIn('tasks.status', Task::openStatusValues())
            ->whereNotNull('tasks.due_at')
            ->with([...Task::ASSIGNEE_RELATIONS, 'lead', 'contact', 'account', 'deal']);
    }
}
