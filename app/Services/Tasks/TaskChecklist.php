<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The checklist rules of a task and of a routine task (decision D-25), used
 * by TaskService, TaskRecurrence and RoutineTaskService inside their own
 * transactions — nothing here opens a transaction, locks a task, checks a
 * policy or writes the ledger; the callers do.
 *
 * - a checklist holds at most MAX_ITEMS items, each one line of at most
 *   BODY_MAX characters: the text is trimmed and its inner whitespace
 *   (line breaks included) collapsed to single spaces, and a blank item is
 *   dropped;
 * - syncing a task's checklist from a form keeps the items whose submitted
 *   id names an item of THAT task — renamed and moved, their ticks kept —
 *   creates the rest and deletes the stored items the submission left out.
 *   An id the browser forged (another task's item, an unknown or repeated
 *   id) never reaches another task's row: the item is simply created anew;
 * - a frozen checklist (a closed or deleted task) refuses every change, while
 *   a submission identical to what is stored passes as a no-op, so saving a
 *   closed task's other details still works;
 * - ticking stamps who ticked and when; unticking clears both; an item
 *   already in the asked state, and an id that is not the task's, are left
 *   alone;
 * - copies (the next occurrence of a repeating task, the tasks of a routine)
 *   are unticked, in the same order.
 *
 * The readers never touch an unloaded relation: counts() prefers the
 * withCount() aliases withCounts() adds to a list query, then a loaded
 * relation, and only then asks the database once.
 */
final class TaskChecklist
{
    /** The most items one checklist may hold. */
    public const int MAX_ITEMS = 50;

    /** The longest item, in characters. */
    public const int BODY_MAX = 255;

    /** The withCount() alias of the number of items. */
    public const string TOTAL_ALIAS = 'checklist_total';

    /** The withCount() alias of the number of ticked items. */
    public const string DONE_ALIAS = 'checklist_done';

    /**
     * Adds the item and ticked-item counts to a task query, read back by
     * counts(). Call it after any select() — a later select() drops them.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'checklistItems as '.self::TOTAL_ALIAS,
            'checklistItems as '.self::DONE_ALIAS => static fn (Builder $items): Builder => $items->whereNotNull('completed_at'),
        ]);
    }

    /**
     * How many items the task's checklist holds and how many are ticked.
     *
     * @return array{done: int, total: int}
     */
    public static function counts(Task $task): array
    {
        $attributes = $task->getAttributes();

        if (array_key_exists(self::TOTAL_ALIAS, $attributes) && array_key_exists(self::DONE_ALIAS, $attributes)) {
            return ['done' => (int) $attributes[self::DONE_ALIAS], 'total' => (int) $attributes[self::TOTAL_ALIAS]];
        }

        if ($task->relationLoaded('checklistItems')) {
            $items = $task->checklistItems;

            return ['done' => $items->filter(static fn (TaskChecklistItem $item): bool => $item->isDone())->count(), 'total' => $items->count()];
        }

        if (! $task->exists) {
            return ['done' => 0, 'total' => 0];
        }

        $row = TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->toBase()
            ->selectRaw('COUNT(*) AS `total`, COUNT(`completed_at`) AS `done`')
            ->first();

        return ['done' => (int) ($row->done ?? 0), 'total' => (int) ($row->total ?? 0)];
    }

    /**
     * "done/total" for the task page, the task list and the board card;
     * null when the task has no checklist.
     */
    public static function progressLabel(Task $task): ?string
    {
        ['done' => $done, 'total' => $total] = self::counts($task);

        return $total === 0 ? null : __('tasks.fields.checklist_progress', ['done' => $done, 'total' => $total]);
    }

    /**
     * The unticked and total items of the open, non-deleted tasks among the
     * given ids — what the bulk Complete dialog warns about. One query.
     *
     * @param  list<int>  $taskIds
     * @return array{open: int, total: int}
     */
    public static function openAcross(array $taskIds): array
    {
        if ($taskIds === []) {
            return ['open' => 0, 'total' => 0];
        }

        $row = TaskChecklistItem::query()
            ->join('tasks', 'tasks.id', '=', 'task_checklist_items.task_id')
            ->whereIn('tasks.id', $taskIds)
            ->whereNull('tasks.deleted_at')
            ->whereIn('tasks.status', Task::openStatusValues())
            ->toBase()
            ->selectRaw('COUNT(*) AS `total`, COUNT(`task_checklist_items`.`completed_at`) AS `done`')
            ->first();

        $total = (int) ($row->total ?? 0);

        return ['open' => $total - (int) ($row->done ?? 0), 'total' => $total];
    }

    /**
     * The repeater state of the task form: the stored items with their ids,
     * in order — every fill path of the form loads it, so a save never
     * submits an empty list over a checklist it did not show.
     *
     * @return list<array{id: int, body: string}>
     */
    public static function formState(Task $task): array
    {
        if (! $task->exists) {
            return [];
        }

        return TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'body'])
            ->map(static fn (TaskChecklistItem $item): array => ['id' => (int) $item->getKey(), 'body' => $item->body])
            ->values()
            ->all();
    }

    /**
     * The routine's items as the simple repeater of its form holds them.
     *
     * @return list<string>
     */
    public static function templateBodies(TaskTemplate $template): array
    {
        if (! $template->exists) {
            return [];
        }

        return TaskTemplateChecklistItem::query()
            ->where('task_template_id', $template->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('body')
            ->map(static fn (mixed $body): string => (string) $body)
            ->values()
            ->all();
    }

    /**
     * A submitted checklist, validated and normalised: a list of items, each
     * a string or an array with a `body` (and, from the task form, an `id`).
     * Blank items are dropped; an over-long item, too many items or a value
     * of the wrong shape is refused under the `checklist` key.
     *
     * @return list<array{id: ?int, body: string}>
     */
    public static function normalise(mixed $state): array
    {
        if ($state === null || $state === '') {
            return [];
        }

        if (! is_array($state)) {
            throw ValidationException::withMessages(['checklist' => __('tasks.validation.checklist_invalid')]);
        }

        $items = [];

        foreach (array_values($state) as $entry) {
            $id = null;

            if (is_array($entry)) {
                $id = self::id($entry['id'] ?? null);
                $body = $entry['body'] ?? null;
            } else {
                $body = $entry;
            }

            if ($body !== null && ! is_string($body) && ! is_int($body)) {
                throw ValidationException::withMessages(['checklist' => __('tasks.validation.checklist_invalid')]);
            }

            $body = Str::squish((string) $body);

            if ($body === '') {
                continue;
            }

            if (mb_strlen($body) > self::BODY_MAX) {
                throw ValidationException::withMessages(['checklist' => __('tasks.validation.checklist_item_too_long', ['max' => self::BODY_MAX])]);
            }

            $items[] = ['id' => $id, 'body' => $body];
        }

        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['checklist' => __('tasks.validation.checklist_too_many', ['max' => self::MAX_ITEMS])]);
        }

        return $items;
    }

    /**
     * Makes the task's checklist the submitted one (see the class notes) and
     * returns what changed — empty lists and false when nothing did. The
     * caller holds the task's row lock; with $frozen any change is refused
     * before a single row is written.
     *
     * @param  list<array{id: ?int, body: string}>  $items
     * @return array{added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, reordered: bool}
     */
    public function sync(Task $task, array $items, bool $frozen = false): array
    {
        $stored = TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->orderBy('id')->get();

        $byId = $stored->keyBy(static fn (TaskChecklistItem $item): int => (int) $item->getKey());

        $kept = [];
        $created = [];
        $renamed = [];
        $moves = [];

        foreach ($items as $position => $item) {
            $current = $item['id'] !== null && ! isset($kept[$item['id']]) ? $byId->get($item['id']) : null;

            if (! $current instanceof TaskChecklistItem) {
                $created[] = ['position' => $position, 'body' => $item['body']];

                continue;
            }

            $kept[(int) $current->getKey()] = $current;

            if ($current->body !== $item['body']) {
                $renamed[] = ['from' => $current->body, 'to' => $item['body']];
            }

            if ($current->body !== $item['body'] || $current->position !== $position) {
                $moves[] = [$current, $position, $item['body']];
            }
        }

        $removed = $stored->reject(static fn (TaskChecklistItem $item): bool => isset($kept[(int) $item->getKey()]))->values();

        $storedOrder = $stored->map(static fn (TaskChecklistItem $item): int => (int) $item->getKey())
            ->filter(static fn (int $id): bool => isset($kept[$id]))
            ->values()
            ->all();
        $reordered = $storedOrder !== array_keys($kept);

        $diff = [
            'added' => array_map(static fn (array $item): string => $item['body'], $created),
            'removed' => $removed->map(static fn (TaskChecklistItem $item): string => $item->body)->values()->all(),
            'renamed' => $renamed,
            'reordered' => $reordered,
        ];

        if ($moves === [] && $created === [] && $removed->isEmpty()) {
            return $diff;
        }

        if ($frozen) {
            throw ValidationException::withMessages(['checklist' => __('tasks.validation.checklist_frozen')]);
        }

        if ($removed->isNotEmpty()) {
            TaskChecklistItem::query()
                ->where('task_id', $task->getKey())
                ->whereKey($removed->map(static fn (TaskChecklistItem $item): int => (int) $item->getKey())->all())
                ->delete();
        }

        foreach ($moves as [$item, $position, $body]) {
            $item->fill(['body' => $body, 'position' => $position])->save();
        }

        foreach ($created as $item) {
            TaskChecklistItem::query()->create([
                'task_id' => $task->getKey(),
                'body' => $item['body'],
                'position' => $item['position'],
            ]);
        }

        $task->unsetRelation('checklistItems');

        return $diff;
    }

    /**
     * Ticks and unticks the task's items by id and returns the bodies that
     * changed state. An id in both lists, an id that is not the task's and an
     * item already in the asked state are left alone. The caller holds the
     * task's row lock and has checked that the task is open.
     *
     * @param  list<int>  $check
     * @param  list<int>  $uncheck
     * @return array{checked: list<string>, unchecked: list<string>}
     */
    public function tick(Task $task, User $actor, array $check, array $uncheck): array
    {
        $check = array_values(array_diff($check, $uncheck));
        $uncheck = array_values(array_diff($uncheck, $check));
        $checked = [];
        $unchecked = [];

        if ($check === [] && $uncheck === []) {
            return ['checked' => [], 'unchecked' => []];
        }

        $items = TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->whereKey([...$check, ...$uncheck])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $now = now();

        foreach ($items as $item) {
            $id = (int) $item->getKey();

            if (in_array($id, $check, true) && ! $item->isDone()) {
                TaskChecklistItem::withoutWorkflowGuard(static fn (): bool => $item->forceFill([
                    'completed_at' => $now,
                    'completed_by' => $actor->getKey(),
                ])->save());

                $checked[] = $item->body;
            } elseif (in_array($id, $uncheck, true) && $item->isDone()) {
                TaskChecklistItem::withoutWorkflowGuard(static fn (): bool => $item->forceFill([
                    'completed_at' => null,
                    'completed_by' => null,
                ])->save());

                $unchecked[] = $item->body;
            }
        }

        $task->unsetRelation('checklistItems');

        return ['checked' => $checked, 'unchecked' => $unchecked];
    }

    /**
     * Copies the bodies, unticked and in order, into the task's checklist
     * (a task that has none yet: the next occurrence, a routine's task).
     *
     * @param  list<string>  $bodies
     */
    public function copyInto(Task $task, array $bodies): void
    {
        foreach ($bodies as $position => $body) {
            TaskChecklistItem::query()->create([
                'task_id' => $task->getKey(),
                'body' => $body,
                'position' => $position,
            ]);
        }
    }

    /**
     * The bodies of the task's checklist, in order.
     *
     * @return list<string>
     */
    public static function bodies(Task $task): array
    {
        return array_map(static fn (array $item): string => $item['body'], self::formState($task));
    }

    /**
     * Replaces the routine's checklist with the given bodies and returns what
     * changed, compared item by item as a list (a repeated line counts as
     * often as it appears): the lines added, the lines removed, and whether
     * the same lines now stand in another order.
     *
     * @param  list<string>  $bodies
     * @return array{added: list<string>, removed: list<string>, reordered: bool}
     */
    public function replaceTemplate(TaskTemplate $template, array $bodies): array
    {
        $stored = self::templateBodies($template);

        if ($stored === $bodies) {
            return ['added' => [], 'removed' => [], 'reordered' => false];
        }

        $diff = [
            'added' => self::listDiff($bodies, $stored),
            'removed' => self::listDiff($stored, $bodies),
        ];
        $diff['reordered'] = $diff['added'] === [] && $diff['removed'] === [];

        TaskTemplateChecklistItem::query()->where('task_template_id', $template->getKey())->delete();

        foreach ($bodies as $position => $body) {
            TaskTemplateChecklistItem::query()->create([
                'task_template_id' => $template->getKey(),
                'body' => $body,
                'position' => $position,
            ]);
        }

        $template->unsetRelation('checklistItems');

        return $diff;
    }

    /**
     * The lines of $from that $minus does not account for, counting repeats.
     *
     * @param  list<string>  $from
     * @param  list<string>  $minus
     * @return list<string>
     */
    private static function listDiff(array $from, array $minus): array
    {
        $remaining = array_count_values($minus);
        $left = [];

        foreach ($from as $line) {
            if (($remaining[$line] ?? 0) > 0) {
                $remaining[$line]--;

                continue;
            }

            $left[] = $line;
        }

        return $left;
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }
}
