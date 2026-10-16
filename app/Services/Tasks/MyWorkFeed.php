<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\MyWorkBucket;
use App\Enums\MyWorkView;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\VisibilityLevel;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\OrganisationCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What «عملي» (My Work), the Tasks home, lists (decision D-27): in one of
 * three views (MyWorkView, amended 2026-10-12) — the reader's own tasks
 * (assigned to them), the tasks they handed out to someone else («أسندتُها»,
 * `assigned_by` the reader), or every task inside their reach («الفريق»,
 * offered at the team and all levels only) — always inside the reader's
 * visibility scope (D-4), in buckets on the organisation calendar (A-19, A-20):
 *
 * - Overdue: open and due before the start of today;
 * - Today: open and due at any moment of today, passed or not (the same
 *   boundary TaskMetrics and the relative due dates use, so «اليوم 17:00»
 *   never sits in another bucket);
 * - Next 7 days: open and due from tomorrow to the end of the seventh day ahead;
 * - Later: open and due after that;
 * - No date: open without a due date;
 * - Completed recently: completed since the start of the organisation day
 *   six days ago — the last seven days, today included.
 *
 * Cancelled and deleted tasks never appear. The bounds are computed in PHP
 * and compared with the raw `due_at` / `completed_at` columns (never SQL
 * DATE(), A-19), so the indexes serve. The page costs a fixed number of
 * queries however many tasks there are: one for the counts, one for the
 * first TASKS_PER_BUCKET tasks of every bucket (a window function, as the
 * tasks board caps its columns) with their checklist counts, and one per
 * eager-loaded relation.
 *
 * A shared task (D-28) is the work of every assignee: «مهامي» lists the tasks
 * the reader holds as the first assignee or as a co-assignee
 * (Task::assignedTo()); «أسندتُها» the ones they handed to someone else,
 * either as the task's assigner or by adding a colleague to it; and every
 * row — in each view, and the task an action resolves — comes with all its
 * assignees (Task::ASSIGNEE_RELATIONS), so the screens name them without a
 * query per row.
 */
final class MyWorkFeed
{
    /** The most tasks one bucket lists; the rest are counted and reached from the task list. */
    public const int TASKS_PER_BUCKET = 15;

    /** How many days ahead "Next 7 days" reaches, tomorrow being the first. */
    public const int NEXT_DAYS = 7;

    /** How many days "Completed recently" looks back, today included. */
    public const int RECENT_DAYS = 7;

    /**
     * The relations a My Work row shows: who handed the task out — its
     * assigner, or for a co-assignee whoever added them (D-28,
     * TaskVisuals::handedOutBy()) — and the record it is linked to.
     */
    private const RELATIONS = ['assigner', 'coAssigneeRows.assigner', 'lead', 'contact', 'account', 'deal'];

    /**
     * The views the reader is offered, in tab order: their own tasks always;
     * the ones they handed out when they may hand tasks out (D-14) or have
     * done so — as a task's assigner or by sharing it (D-28); everyone's when
     * their task visibility reaches beyond their own (team or all — at `own`
     * the team view would only repeat «مهامي»).
     *
     * @return list<MyWorkView>
     */
    public function views(User $reader): array
    {
        $views = [MyWorkView::Mine];

        if ($reader->can(Permission::TaskAssign->value) || $this->scope($reader, MyWorkView::Delegated)->exists()) {
            $views[] = MyWorkView::Delegated;
        }

        if (in_array($this->visibility->levelFor($reader, Task::class), [VisibilityLevel::Team, VisibilityLevel::All], true)) {
            $views[] = MyWorkView::Team;
        }

        return $views;
    }

    /**
     * The view a reader lands on: the one asked for when it is offered,
     * otherwise their own tasks when they have open work, otherwise the team's
     * (or what they handed out) — so someone who hands work out never opens on
     * an empty page while the team is busy (the owner's report, 2026-10-12).
     */
    public function resolveView(User $reader, ?string $asked): MyWorkView
    {
        $offered = $this->views($reader);
        $view = is_string($asked) ? MyWorkView::tryFrom($asked) : null;

        if ($view !== null && in_array($view, $offered, true)) {
            return $view;
        }

        if (count($offered) === 1 || $this->scope($reader, MyWorkView::Mine)->whereIn('tasks.status', Task::openStatusValues())->exists()) {
            return MyWorkView::Mine;
        }

        return in_array(MyWorkView::Team, $offered, true) ? MyWorkView::Team : MyWorkView::Delegated;
    }

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly OrganisationCalendar $calendar,
    ) {}

    /**
     * Every bucket, in page order, empty ones included (the page shows each
     * bucket's count).
     *
     * @return list<MyWorkSection>
     */
    public function sections(User $reader, MyWorkView $view = MyWorkView::Mine): array
    {
        $bounds = $this->bounds();
        $counts = $this->counts($reader, $view, $bounds);
        $tasks = array_fill_keys(array_map(static fn (MyWorkBucket $bucket): string => $bucket->value, MyWorkBucket::cases()), []);

        foreach ($this->heads($reader, $view, $bounds) as $task) {
            $bucket = (string) $task->getAttribute('my_work_bucket');

            if (array_key_exists($bucket, $tasks)) {
                $tasks[$bucket][] = $task;
            }
        }

        return array_map(
            static fn (MyWorkBucket $bucket): MyWorkSection => new MyWorkSection($bucket, $counts[$bucket->value], $tasks[$bucket->value]),
            MyWorkBucket::cases(),
        );
    }

    /**
     * One task of the view by id — what an action on a My Work row resolves
     * its record from (the id arrives from the browser and is trusted for
     * nothing) — with its checklist counts and every assignee (D-28: the
     * policy asks whether it was handed out to the reader), or null when the
     * task is not in the view, is out of the reader's scope, deleted or
     * absent. The action still asks TaskPolicy whether the reader may make
     * the move.
     */
    public function find(User $reader, mixed $id, MyWorkView $view = MyWorkView::Mine): ?Task
    {
        $key = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;

        if ($key <= 0) {
            return null;
        }

        $task = TaskChecklist::withCounts($this->scope($reader, $view)->whereKey($key)->with(Task::ASSIGNEE_RELATIONS))->first();

        return $task instanceof Task ? $task : null;
    }

    /**
     * The moments the buckets are cut at, in the stored timezone.
     *
     * @return array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}
     */
    private function bounds(): array
    {
        $today = $this->calendar->today();
        [$todayStart, $tomorrowStart] = $this->calendar->dayRange($today);

        return [
            'today' => $todayStart,
            'tomorrow' => $tomorrowStart,
            'horizon' => $this->calendar->dayRange($today->addDays(self::NEXT_DAYS))[1],
            'recent' => $this->calendar->dayRange($today->subDays(self::RECENT_DAYS - 1))[0],
        ];
    }

    /**
     * Each bucket's size, zero for an empty one.
     *
     * @param  array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}  $bounds
     * @return array<string, int>
     */
    private function counts(User $reader, MyWorkView $view, array $bounds): array
    {
        [$bucket, $bindings] = $this->bucketExpression($bounds);
        $counts = array_fill_keys(array_map(static fn (MyWorkBucket $case): string => $case->value, MyWorkBucket::cases()), 0);

        $rows = $this->listed($reader, $view, $bounds)
            ->toBase()
            ->selectRaw($bucket.' AS `bucket`, COUNT(*) AS `total`', $bindings)
            ->groupBy('bucket')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->bucket] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The first TASKS_PER_BUCKET tasks of every bucket, in page order, each
     * carrying its bucket (`my_work_bucket`), its checklist counts and the
     * relations a row shows.
     *
     * @param  array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}  $bounds
     * @return list<Task>
     */
    private function heads(User $reader, MyWorkView $view, array $bounds): array
    {
        [$bucket, $bindings] = $this->bucketExpression($bounds);
        [$order, $orderBindings] = $this->orderExpression();

        $ranked = $this->listed($reader, $view, $bounds)
            ->toBase()
            ->select('tasks.id')
            ->selectRaw($bucket.' AS `bucket`', $bindings)
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$bucket.' ORDER BY '.$order.') AS `bucket_position`', [...$bindings, ...$orderBindings]);

        $heads = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('bucket_position', '<=', self::TASKS_PER_BUCKET)
            ->select(['id', 'bucket']);

        // The checklist counts (D-25) come after the select: a select() after
        // withCount() would drop them. Every view loads every assignee: even
        // in «مهامي» a shared task may be filed under a colleague (D-28).
        $tasks = TaskChecklist::withCounts(
            Task::query()
                ->joinSub($heads, 'heads', 'heads.id', '=', 'tasks.id')
                ->with([...self::RELATIONS, ...Task::ASSIGNEE_RELATIONS])
                ->select('tasks.*')
                ->addSelect('heads.bucket AS my_work_bucket'),
        )
            ->orderByRaw($order, $orderBindings)
            ->get();

        return array_values($tasks->all());
    }

    /**
     * The reader's tasks My Work lists: open ones, and those completed in
     * the last RECENT_DAYS days.
     *
     * @param  array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}  $bounds
     * @return Builder<Task>
     */
    private function listed(User $reader, MyWorkView $view, array $bounds): Builder
    {
        return $this->scope($reader, $view)->where(static function (Builder $query) use ($bounds): void {
            $query->whereIn('tasks.status', Task::openStatusValues())
                ->orWhere(static function (Builder $completed) use ($bounds): void {
                    $completed->where('tasks.status', TaskStatus::Completed->value)
                        ->where('tasks.completed_at', '>=', $bounds['recent']);
                });
        });
    }

    /**
     * The view's tasks inside the reader's visibility scope: their own (they
     * hold it, as its first assignee or as a co-assignee — D-28), the ones
     * they handed out to someone else, or every task in reach.
     *
     * Handed out (D-14, D-28) is either path: the reader is the task's
     * assigner and its first assignee is someone else, or the reader added
     * someone else to it as a co-assignee.
     *
     * @return Builder<Task>
     */
    private function scope(User $reader, MyWorkView $view): Builder
    {
        $query = $this->visibility->visible($reader, Task::query());
        $readerId = (int) $reader->getKey();

        return match ($view) {
            MyWorkView::Mine => $query->assignedTo($readerId),
            MyWorkView::Delegated => $query->where(static function (Builder $handedOut) use ($readerId): void {
                $handedOut
                    ->where(static function (Builder $asAssigner) use ($readerId): void {
                        $asAssigner->where('tasks.assigned_by', $readerId)
                            ->whereNotNull('tasks.assignee_id')
                            ->where('tasks.assignee_id', '!=', $readerId);
                    })
                    ->orWhereIn('tasks.id', TaskCoAssignee::query()
                        ->select('task_co_assignees.task_id')
                        ->where('task_co_assignees.assigned_by', $readerId)
                        ->where('task_co_assignees.user_id', '!=', $readerId));
            }),
            MyWorkView::Team => $query,
        };
    }

    /**
     * The SQL naming a listed task's bucket, with its bindings.
     *
     * @param  array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}  $bounds
     * @return array{0: string, 1: list<mixed>}
     */
    private function bucketExpression(array $bounds): array
    {
        return [
            'CASE'
            .' WHEN `tasks`.`status` = ? THEN ?'
            .' WHEN `tasks`.`due_at` IS NULL THEN ?'
            .' WHEN `tasks`.`due_at` < ? THEN ?'
            .' WHEN `tasks`.`due_at` < ? THEN ?'
            .' WHEN `tasks`.`due_at` < ? THEN ?'
            .' ELSE ? END',
            [
                TaskStatus::Completed->value, MyWorkBucket::CompletedRecently->value,
                MyWorkBucket::NoDate->value,
                $bounds['today'], MyWorkBucket::Overdue->value,
                $bounds['tomorrow'], MyWorkBucket::Today->value,
                $bounds['horizon'], MyWorkBucket::NextSevenDays->value,
                MyWorkBucket::Later->value,
            ],
        ];
    }

    /**
     * The order inside a bucket: open work soonest due first, the recently
     * completed newest first, then the oldest task first.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function orderExpression(): array
    {
        return [
            'CASE WHEN `tasks`.`status` = ? THEN NULL ELSE `tasks`.`due_at` END, `tasks`.`completed_at` DESC, `tasks`.`id`',
            [TaskStatus::Completed->value],
        ];
    }
}
