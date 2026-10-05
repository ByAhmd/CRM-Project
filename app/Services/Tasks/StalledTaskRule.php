<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * When a task is stalled — one definition for every reader (decisions D-18,
 * D-25, D-26): the weekly summary e-mail and the employee dashboard both ask
 * this class, so the two can never disagree.
 *
 * A task is stalled when it is in progress and nothing has moved it for
 * AFTER_DAYS: no thread entry (a progress entry or a comment, D-17) and no
 * checklist tick (a tick counts as progress, D-25) since the cutoff. A task
 * from before the thread existed, which has neither, is stalled when it was
 * not touched (`updated_at`) since the cutoff either.
 *
 * The rule only narrows a task query the caller already scoped (the whole
 * organisation for the summary, one employee inside the reader's reach for
 * the dashboard); quietestFirst() orders such a query by the last sign of
 * progress, and since() reads that moment back from a row it selected.
 * Every moment is compared in the application timezone the columns are
 * stored in; nothing here uses SQL date functions (A-19), and GREATEST /
 * COALESCE read alike on MySQL 8 and MariaDB (A-21).
 */
final class StalledTaskRule
{
    /** An in-progress task with no progress entry, comment or checklist tick for this long is stalled. */
    public const int AFTER_DAYS = 3;

    /** The alias of the newest thread entry's moment, added by quietestFirst(). */
    public const string LAST_ENTRY_ALIAS = 'last_entry_at';

    /** The alias of the newest checklist tick's moment, added by quietestFirst(). */
    public const string LAST_TICK_ALIAS = 'last_tick_at';

    /** Stands in for "no entry or tick at all" when the quietest stalled tasks are ordered first. */
    private const string NEVER = '1000-01-01 00:00:00';

    /** The moment before which no sign of progress means stalled. */
    public static function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone((string) config('app.timezone'))->subDays(self::AFTER_DAYS);
    }

    /**
     * Narrows the task query to its stalled tasks as of the given moment.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function constrain(Builder $query, CarbonImmutable $now): Builder
    {
        $cutoff = self::cutoff($now);

        return $query
            ->where($query->qualifyColumn('status'), TaskStatus::InProgress->value)
            ->whereDoesntHave('updates', static function (Builder $entries) use ($cutoff): void {
                $entries->where('created_at', '>=', $cutoff);
            })
            ->whereDoesntHave('checklistItems', static function (Builder $items) use ($cutoff): void {
                $items->where('completed_at', '>=', $cutoff);
            })
            ->where(static function (Builder $nested) use ($cutoff): void {
                $nested->has('updates')
                    ->orWhereHas('checklistItems', static function (Builder $items): void {
                        $items->whereNotNull('completed_at');
                    })
                    ->orWhere($nested->qualifyColumn('updated_at'), '<', $cutoff);
            });
    }

    /**
     * Adds the moments of the newest thread entry and the newest tick, and
     * orders the quietest first: by the later of the two, a task with
     * neither (from before the thread) first of all, then by when it was
     * last touched. Call it after the select() of the columns the caller
     * needs (`updated_at` among them, for since()).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function quietestFirst(Builder $query): Builder
    {
        return $query
            ->addSelect([
                self::LAST_ENTRY_ALIAS => TaskUpdate::query()
                    ->select('created_at')
                    ->whereColumn('task_updates.task_id', 'tasks.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                self::LAST_TICK_ALIAS => TaskChecklistItem::query()
                    ->select('completed_at')
                    ->whereColumn('task_checklist_items.task_id', 'tasks.id')
                    ->whereNotNull('completed_at')
                    ->orderByDesc('completed_at')
                    ->limit(1),
            ])
            ->orderByRaw('GREATEST(COALESCE(`'.self::LAST_ENTRY_ALIAS.'`, ?), COALESCE(`'.self::LAST_TICK_ALIAS.'`, ?))', [self::NEVER, self::NEVER])
            ->orderBy($query->qualifyColumn('updated_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * The last sign of progress on a task read through quietestFirst(): the
     * later of its newest entry and its newest tick, else when it was last
     * touched, else the fallback — in the application timezone.
     */
    public static function since(Task $task, CarbonImmutable $fallback): CarbonImmutable
    {
        $moments = array_values(array_filter(
            [$task->getAttribute(self::LAST_ENTRY_ALIAS), $task->getAttribute(self::LAST_TICK_ALIAS)],
            static fn (mixed $moment): bool => is_string($moment) && $moment !== '',
        ));

        return $moments === []
            ? CarbonImmutable::instance($task->updated_at ?? $fallback)
            : CarbonImmutable::parse(max($moments), (string) config('app.timezone'));
    }
}
