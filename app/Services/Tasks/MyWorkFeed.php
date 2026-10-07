<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\MyWorkBucket;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\OrganisationCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What «عملي» (My Work), the Tasks home, lists (decision D-27): the reader's
 * own tasks — those assigned to them, inside their visibility scope (D-4) —
 * in buckets on the organisation calendar (A-19, A-20):
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
 */
final class MyWorkFeed
{
    /** The most tasks one bucket lists; the rest are counted and reached from the task list. */
    public const int TASKS_PER_BUCKET = 15;

    /** How many days ahead "Next 7 days" reaches, tomorrow being the first. */
    public const int NEXT_DAYS = 7;

    /** How many days "Completed recently" looks back, today included. */
    public const int RECENT_DAYS = 7;

    /** The relations a My Work row shows: who handed the task out, and the record it is linked to. */
    private const RELATIONS = ['assigner', 'lead', 'contact', 'account', 'deal'];

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
    public function sections(User $reader): array
    {
        $bounds = $this->bounds();
        $counts = $this->counts($reader, $bounds);
        $tasks = array_fill_keys(array_map(static fn (MyWorkBucket $bucket): string => $bucket->value, MyWorkBucket::cases()), []);

        foreach ($this->heads($reader, $bounds) as $task) {
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
     * One of the reader's own tasks by id — what an action on a My Work row
     * resolves its record from (the id arrives from the browser and is
     * trusted for nothing) — with its checklist counts, or null when the
     * task is not the reader's, is out of their scope, deleted or absent.
     */
    public function find(User $reader, mixed $id): ?Task
    {
        $key = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;

        if ($key <= 0) {
            return null;
        }

        $task = TaskChecklist::withCounts($this->own($reader)->whereKey($key))->first();

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
    private function counts(User $reader, array $bounds): array
    {
        [$bucket, $bindings] = $this->bucketExpression($bounds);
        $counts = array_fill_keys(array_map(static fn (MyWorkBucket $case): string => $case->value, MyWorkBucket::cases()), 0);

        $rows = $this->listed($reader, $bounds)
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
    private function heads(User $reader, array $bounds): array
    {
        [$bucket, $bindings] = $this->bucketExpression($bounds);
        [$order, $orderBindings] = $this->orderExpression();

        $ranked = $this->listed($reader, $bounds)
            ->toBase()
            ->select('tasks.id')
            ->selectRaw($bucket.' AS `bucket`', $bindings)
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$bucket.' ORDER BY '.$order.') AS `bucket_position`', [...$bindings, ...$orderBindings]);

        $heads = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('bucket_position', '<=', self::TASKS_PER_BUCKET)
            ->select(['id', 'bucket']);

        // The checklist counts (D-25) come after the select: a select() after
        // withCount() would drop them.
        $tasks = TaskChecklist::withCounts(
            Task::query()
                ->joinSub($heads, 'heads', 'heads.id', '=', 'tasks.id')
                ->with(self::RELATIONS)
                ->select('tasks.*')
                ->addSelect('heads.bucket AS my_work_bucket'),
        )
            ->orderByRaw($order, $orderBindings)
            ->get();

        foreach ($tasks as $task) {
            // Every task here is the reader's own: the row's avatar and any
            // policy check reading the assignee need no query.
            $task->setRelation('assignee', $reader);
        }

        return array_values($tasks->all());
    }

    /**
     * The reader's tasks My Work lists: open ones, and those completed in
     * the last RECENT_DAYS days.
     *
     * @param  array{today: Carbon, tomorrow: Carbon, horizon: Carbon, recent: Carbon}  $bounds
     * @return Builder<Task>
     */
    private function listed(User $reader, array $bounds): Builder
    {
        return $this->own($reader)->where(static function (Builder $query) use ($bounds): void {
            $query->whereIn('tasks.status', Task::openStatusValues())
                ->orWhere(static function (Builder $completed) use ($bounds): void {
                    $completed->where('tasks.status', TaskStatus::Completed->value)
                        ->where('tasks.completed_at', '>=', $bounds['recent']);
                });
        });
    }

    /**
     * The reader's own tasks inside their visibility scope.
     *
     * @return Builder<Task>
     */
    private function own(User $reader): Builder
    {
        return $this->visibility->visible($reader, Task::query())
            ->where('tasks.assignee_id', $reader->getKey());
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
