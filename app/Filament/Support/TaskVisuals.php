<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Services\Tasks\RelativeDueDates;
use App\Services\Tasks\TaskChecklist;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

/**
 * The visual language of tasks (decision D-27), one implementation for
 * every Tasks-workspace screen — Filament columns and entries through the
 * factories below, custom Blade pages through the `<x-task.*>` components
 * (resources/views/components/task), which call the renderers here:
 *
 * - status: the TaskStatus colour (one colour per status everywhere, the
 *   very badge Filament draws) with an icon per status, so the state never
 *   rests on colour alone;
 * - priority: a flag — urgent red, high orange, medium and low muted —
 *   named for screen readers («الأولوية: عاجلة»);
 * - avatar: local initials on the A-23 tokens (InitialsAvatar), never an
 *   outside service;
 * - assignees (D-28): who holds the task — overlapping avatars, the first
 *   assignee's name and a «+N» chip for the colleagues sharing it — read
 *   from the eager-loaded Task::ASSIGNEE_RELATIONS only;
 * - checklist: a small meter with done/total (D-25), from counts read
 *   defensively — TaskChecklist::counts() takes the query's withCounts(),
 *   a loaded relation or one query, and the numbers are clamped;
 * - due: relative words on the organisation calendar (RelativeDueDates),
 *   overdue red, today amber, the exact moment in the tooltip.
 *
 * Every renderer is server-side and escape-safe: user text (names) goes
 * through e(), the rest are enum labels, numbers and lang strings, also
 * escaped. The icons and colours live on columns and entries, never on the
 * enums — an enum icon would surface on the Sales task tabs too, which
 * keep their look (D-27, owner). Colours come from theme.css tokens
 * (`crm-flag`, `crm-meter`, `crm-due`), defined for both themes at AA.
 */
final class TaskVisuals
{
    /** The most avatars an assignee presentation overlaps; the rest are counted (D-28). */
    public const int AVATAR_STACK = 3;

    public static function statusIcon(TaskStatus $status): Heroicon
    {
        return match ($status) {
            TaskStatus::Pending => Heroicon::OutlinedClock,
            TaskStatus::InProgress => Heroicon::OutlinedPlayCircle,
            TaskStatus::Completed => Heroicon::OutlinedCheckCircle,
            TaskStatus::Cancelled => Heroicon::OutlinedXCircle,
        };
    }

    /** The status as Filament's own badge: the TaskStatus colour, its icon and its label. */
    public static function statusPill(TaskStatus $status): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$color" :icon="$icon" class="crm-status-pill">{{ $label }}</x-filament::badge>',
            ['color' => $status->getColor(), 'icon' => self::statusIcon($status), 'label' => $status->getLabel()],
        ));
    }

    /** A `status` column as the pill: colour and label from TaskStatus, the icon from here. */
    public static function statusColumn(TextColumn $column): TextColumn
    {
        return $column
            ->badge()
            ->icon(static fn (mixed $state): ?Heroicon => $state instanceof TaskStatus ? self::statusIcon($state) : null);
    }

    public static function statusEntry(TextEntry $entry): TextEntry
    {
        return $entry
            ->badge()
            ->icon(static fn (mixed $state): ?Heroicon => $state instanceof TaskStatus ? self::statusIcon($state) : null);
    }

    /** Solid for the two priorities that ask for attention, outlined for the others. */
    public static function priorityIcon(TaskPriority $priority): Heroicon
    {
        return match ($priority) {
            TaskPriority::Urgent, TaskPriority::High => Heroicon::Flag,
            TaskPriority::Medium, TaskPriority::Low => Heroicon::OutlinedFlag,
        };
    }

    /**
     * The priority flag. Alone it is an image named «الأولوية: عاجلة»; with
     * its label the visible word names it and the flag is decoration.
     */
    public static function priorityFlag(TaskPriority $priority, bool $withLabel = false): HtmlString
    {
        $icon = generate_icon_html(
            self::priorityIcon($priority),
            attributes: new ComponentAttributeBag(['class' => 'crm-flag-icon', 'aria-hidden' => 'true']),
            size: IconSize::Small,
        )?->toHtml() ?? '';

        $class = 'crm-flag crm-flag--'.$priority->value;

        if ($withLabel) {
            return new HtmlString(sprintf(
                '<span class="%s">%s<span class="crm-flag-label">%s</span></span>',
                $class,
                $icon,
                e($priority->getLabel()),
            ));
        }

        return new HtmlString(sprintf(
            '<span class="%1$s" role="img" aria-label="%2$s" title="%2$s">%3$s</span>',
            $class,
            e(__('tasks.fields.priority_flag', ['priority' => $priority->getLabel()])),
            $icon,
        ));
    }

    /** A `priority` column as the flag with its word. */
    public static function priorityColumn(TextColumn $column): TextColumn
    {
        return $column->formatStateUsing(static fn (mixed $state): string|HtmlString => $state instanceof TaskPriority ? self::priorityFlag($state, withLabel: true) : '');
    }

    public static function priorityEntry(TextEntry $entry): TextEntry
    {
        return $entry->formatStateUsing(static fn (mixed $state): string|HtmlString => $state instanceof TaskPriority ? self::priorityFlag($state, withLabel: true) : '');
    }

    /**
     * A person's avatar standing alone, named for screen readers; null —
     * no one — reads «غير مُسندة» / "Unassigned" as an empty dashed circle.
     */
    public static function avatar(?string $name): HtmlString
    {
        $avatar = $name === null ? null : InitialsAvatar::forName($name, labelled: true);

        if ($avatar !== null) {
            return $avatar;
        }

        return new HtmlString(sprintf(
            '<span class="crm-avatar crm-avatar--empty" role="img" aria-label="%1$s" title="%1$s"></span>',
            e(__('assignment.placeholders.unassigned')),
        ));
    }

    /**
     * Every assignee of the task (D-28), the first — the one it is filed
     * under — first, then the colleagues sharing it in the order they were
     * added: Task::assignees() over the eager-loaded ASSIGNEE_RELATIONS only.
     * A relation the screen did not load adds nobody and never costs a query
     * (lazy loading is prevented); every screen that shows assignees loads
     * Task::ASSIGNEE_RELATIONS with its rows.
     *
     * @return list<User>
     */
    public static function assigneesOf(Task $task): array
    {
        $first = $task->relationLoaded('assignee') ? $task->getRelation('assignee') : null;
        $first = $first instanceof User ? $first : null;
        $people = $first === null ? [] : [$first];

        if ($task->relationLoaded('coAssignees')) {
            foreach ($task->getRelation('coAssignees') as $user) {
                if ($user instanceof User && ($first === null || ! $user->is($first))) {
                    $people[] = $user;
                }
            }
        }

        return $people;
    }

    /**
     * Who handed the task to the reader (D-14, D-28), for the «مهامي» row's
     * "handed out by" avatar: the task's assigner when the reader is its
     * first assignee, the colleague who added them when they are a
     * co-assignee — never the reader themselves, and null when nobody else
     * did. Reads the eager-loaded `assigner` and `coAssigneeRows.assigner`
     * only (MyWorkFeed loads both); an unloaded relation answers null.
     */
    public static function handedOutBy(Task $task, User $reader): ?User
    {
        $readerId = (int) $reader->getKey();

        if ($task->assignee_id !== null && (int) $task->assignee_id === $readerId) {
            $assigner = $task->relationLoaded('assigner') ? $task->getRelation('assigner') : null;
        } else {
            $row = $task->relationLoaded('coAssigneeRows')
                ? $task->getRelation('coAssigneeRows')->first(static fn (TaskCoAssignee $row): bool => (int) $row->user_id === $readerId)
                : null;
            $assigner = $row instanceof TaskCoAssignee && $row->relationLoaded('assigner') ? $row->getRelation('assigner') : null;
        }

        return $assigner instanceof User && (int) $assigner->getKey() !== $readerId ? $assigner : null;
    }

    /** Every assignee's name, the first first, joined for reading («هدى، عمر، سارة»); empty for nobody. */
    public static function assigneeNames(Task $task): string
    {
        return self::names(self::assigneesOf($task));
    }

    /**
     * Who holds the task (D-28), the one presentation every Tasks-workspace
     * screen shows it in: up to AVATAR_STACK overlapping avatars, the first
     * assignee's name — the one the task is filed under — and, for a shared
     * task, a «+N» chip. The chip means the same everywhere, here and in
     * coHolders(): the people whose avatar is not drawn. So it counts those
     * beyond the stack, and a compact list cell — where CSS leaves the first
     * avatar alone (theme.css, .crm-assignees) — swaps in its second count,
     * everyone but the first. The whole carries every name as its tooltip;
     * the visually hidden words name the others for a screen reader
     * («ومكلَّفان آخران: …», plural forms through trans_choice), and the
     * avatars and the chip are decoration. Escape-safe; null when nobody
     * holds the task.
     */
    public static function assignees(Task $task): ?HtmlString
    {
        $people = self::assigneesOf($task);

        if ($people === []) {
            return null;
        }

        $everyone = __('tasks.assignees.everyone', ['names' => self::names($people)]);
        $others = array_slice($people, 1);
        $beyond = count($people) - min(count($people), self::AVATAR_STACK);
        $chip = '';

        if ($others !== []) {
            $chip = sprintf(
                '<span class="crm-assignees__more%s" aria-hidden="true" dir="ltr" title="%s">%s<span class="crm-assignees__more-compact">%s</span></span><span class="sr-only">%s</span>',
                $beyond === 0 ? ' crm-assignees__more--compact-only' : '',
                e($everyone),
                $beyond > 0 ? '<span class="crm-assignees__more-stacked">'.e(__('tasks.assignees.more', ['count' => $beyond])).'</span>' : '',
                e(__('tasks.assignees.more', ['count' => count($others)])),
                e(trans_choice('tasks.assignees.others', count($others), ['count' => count($others), 'names' => self::names($others)])),
            );
        }

        return new HtmlString(sprintf(
            '<span class="crm-assignees" title="%s">%s<span class="crm-assignees__name">%s</span>%s</span>',
            e($everyone),
            self::avatarStack($people),
            e($people[0]->name),
            $chip,
        ));
    }

    /**
     * The colleagues who hold a shared task beside the given holder (D-28):
     * the Mine view of My Work and a card in an assignee's board column,
     * where the holder is already plain. Up to AVATAR_STACK overlapping
     * avatars and a «+N» chip for those beyond them, the whole an image named
     * «مشتركة مع …» with that tooltip. Null when nobody else holds the task.
     */
    public static function coHolders(Task $task, User|int|null $holder): ?HtmlString
    {
        $holderId = $holder instanceof User ? (int) $holder->getKey() : $holder;
        $others = array_values(array_filter(
            self::assigneesOf($task),
            static fn (User $user): bool => (int) $user->getKey() !== $holderId,
        ));

        if ($others === []) {
            return null;
        }

        $label = __('tasks.assignees.shared_with', ['names' => self::names($others)]);
        $beyond = count($others) - min(count($others), self::AVATAR_STACK);

        return new HtmlString(sprintf(
            '<span class="crm-assignees crm-assignees--others" role="img" aria-label="%1$s" title="%1$s">%2$s%3$s</span>',
            e($label),
            self::avatarStack($others),
            $beyond > 0 ? '<span class="crm-assignees__more" dir="ltr">'.e(__('tasks.assignees.more', ['count' => $beyond])).'</span>' : '',
        ));
    }

    /**
     * Every assignee as a list (D-28: the task page's «المكلَّفون», the
     * form's read-only list), each with their avatar — decoration, the name
     * beside it is read — the first one first, a removed account marked as
     * such. Null when nobody holds the task.
     */
    public static function assigneeList(Task $task): ?HtmlString
    {
        return self::peopleList(self::assigneesOf($task));
    }

    /**
     * People as the task screens list them: one line each, avatar and name.
     *
     * @param  list<User>  $people
     */
    public static function peopleList(array $people): ?HtmlString
    {
        if ($people === []) {
            return null;
        }

        $items = '';

        foreach ($people as $user) {
            $name = $user->trashed() ? __('tasks.empty.deleted_author_named', ['name' => $user->name]) : $user->name;

            $items .= sprintf(
                '<li class="crm-person">%s<span class="crm-person__name">%s</span></li>',
                InitialsAvatar::forName($user->name)?->toHtml() ?? '',
                e($name),
            );
        }

        return new HtmlString('<ul class="crm-people" role="list">'.$items.'</ul>');
    }

    /**
     * An `assignee.name` column as who holds the task (assignees(), D-28):
     * still sorted by the first assignee's name through the column's
     * relationship, its state every name so the placeholder stands only for
     * a task nobody holds. The table query eager-loads
     * Task::ASSIGNEE_RELATIONS, as TaskResource's does.
     */
    public static function assigneeColumn(TextColumn $column): TextColumn
    {
        return $column
            ->state(static fn (Task $record): ?string => self::assigneesOf($record) === [] ? null : self::assigneeNames($record))
            ->formatStateUsing(static fn (mixed $state, Task $record): string|HtmlString => self::assignees($record) ?? '');
    }

    /** An entry listing every assignee with their avatar (assigneeList()); the placeholder for nobody. */
    public static function assigneesEntry(TextEntry $entry): TextEntry
    {
        return $entry
            ->state(static fn (?Model $record): ?string => $record instanceof Task && self::assigneesOf($record) !== [] ? self::assigneeNames($record) : null)
            ->formatStateUsing(static fn (mixed $state, ?Model $record): string|HtmlString => $record instanceof Task ? (self::assigneeList($record) ?? '') : '');
    }

    /**
     * Up to AVATAR_STACK avatars, overlapping (decoration).
     *
     * @param  list<User>  $people
     */
    private static function avatarStack(array $people): string
    {
        $avatars = '';

        foreach (array_slice($people, 0, self::AVATAR_STACK) as $user) {
            $avatars .= InitialsAvatar::forName($user->name)?->toHtml() ?? '<span class="crm-avatar crm-avatar--empty"></span>';
        }

        return '<span class="crm-assignees__stack" aria-hidden="true">'.$avatars.'</span>';
    }

    /**
     * @param  list<User>  $people
     */
    private static function names(array $people): string
    {
        return implode(__('tasks.assignees.separator'), array_map(static fn (User $user): string => $user->name, $people));
    }

    /**
     * The checklist meter: a bar filling from inline-start and "done/total"
     * in Latin digits, named «أُنجز 2 من 5 بنود» for screen readers. Null
     * without a checklist; the counts are clamped, so a stale or odd pair
     * never draws a bar past its end.
     */
    public static function checklistMeter(int $done, int $total): ?HtmlString
    {
        if ($total <= 0) {
            return null;
        }

        $done = max(0, min($done, $total));
        $percent = intdiv($done * 100, $total);

        return new HtmlString(sprintf(
            '<span class="crm-meter%s" role="img" aria-label="%s">'
            .'<span class="crm-meter-track" aria-hidden="true"><span class="crm-meter-fill" style="inline-size: %d%%"></span></span>'
            .'<span class="crm-meter-text" dir="ltr" aria-hidden="true">%s</span>'
            .'</span>',
            $done === $total ? ' crm-meter--done' : '',
            e(trans_choice('tasks.fields.checklist_meter', $total, ['done' => $done, 'total' => $total])),
            $percent,
            e(__('tasks.fields.checklist_progress', ['done' => $done, 'total' => $total])),
        ));
    }

    /** The task's checklist meter, or null when it has no checklist. */
    public static function checklistMeterFor(Task $task): ?HtmlString
    {
        ['done' => $done, 'total' => $total] = TaskChecklist::counts($task);

        return self::checklistMeter($done, $total);
    }

    /**
     * A checklist column as the meter. Give the table query
     * TaskChecklist::withCounts() so the counts ride on it (no query per row).
     */
    public static function checklistColumn(TextColumn $column): TextColumn
    {
        return $column
            ->state(static fn (Task $record): ?string => TaskChecklist::progressLabel($record))
            ->formatStateUsing(static fn (mixed $state, Task $record): string|HtmlString => self::checklistMeterFor($record) ?? '');
    }

    /**
     * The task's due date in relative words inside a `<time>` carrying the
     * exact moment (machine-readable, and as the tooltip); null without a
     * due date.
     */
    public static function relativeDue(Task $task): ?HtmlString
    {
        $due = app(RelativeDueDates::class)->for($task);

        if ($due === null) {
            return null;
        }

        return new HtmlString(sprintf(
            '<time class="crm-due %s" datetime="%s" title="%s">%s</time>',
            $due->tone->cssClass(),
            e($due->iso()),
            e($due->absolute()),
            e($due->text),
        ));
    }

    /** A `due_at` column in relative words, the exact moment in the tooltip; still sortable by the raw column. */
    public static function dueColumn(TextColumn $column): TextColumn
    {
        return $column
            ->formatStateUsing(static fn (mixed $state, Task $record): string|HtmlString => self::relativeDue($record) ?? '')
            ->tooltip(static fn (Task $record): ?string => app(RelativeDueDates::class)->for($record)?->absolute())
            ->placeholder(__('tasks.due.none'));
    }

    public static function dueEntry(TextEntry $entry): TextEntry
    {
        return $entry
            ->formatStateUsing(static fn (mixed $state, ?Task $record): string|HtmlString => $record === null ? '' : (self::relativeDue($record) ?? ''))
            ->placeholder(__('tasks.due.none'));
    }

    /**
     * The words inside a status pill that changes the status in place (the
     * task list's status cell): the status as seen, and for a screen reader
     * what the control does — «تغيير حالة «…» (الآن: …)», as the board's pill
     * and My Work's say it.
     */
    public static function statusPillName(Task $task): HtmlString
    {
        return new HtmlString(sprintf(
            '<span aria-hidden="true">%s</span><span class="sr-only">%s</span>',
            e($task->status->getLabel()),
            e(self::statusPillLabel($task)),
        ));
    }

    /** What a status pill that changes the status does, naming the task and its current status. */
    public static function statusPillLabel(Task $task): string
    {
        return __('tasks.board_ux.actions.change_status', ['status' => $task->status->getLabel(), 'task' => $task->title]);
    }

    /**
     * The completion check every Tasks-workspace row starts with: an empty
     * ring when the reader may complete the task now ($actionable — pass
     * TaskActions::mayComplete()), a filled check once completed, a crossed
     * circle once cancelled, a muted mark otherwise. The icon is decoration;
     * the visually hidden words beside it say what the control does or
     * what the state is.
     */
    public static function completionCheck(Task $task, bool $actionable): HtmlString
    {
        [$icon, $class, $text] = match (true) {
            $task->status === TaskStatus::Completed => [Heroicon::CheckCircle, 'crm-task-check--done', $task->status->getLabel()],
            $task->status === TaskStatus::Cancelled => [Heroicon::OutlinedXCircle, 'crm-task-check--cancelled', $task->status->getLabel()],
            $actionable => [Heroicon::OutlinedCheckCircle, 'crm-task-check--open', __('tasks.list_ux.helpers.mark_complete', ['task' => $task->title])],
            default => [Heroicon::OutlinedStop, 'crm-task-check--locked', $task->status->getLabel()],
        };

        $svg = generate_icon_html($icon, attributes: new ComponentAttributeBag(['class' => 'crm-task-check-icon', 'aria-hidden' => 'true']), size: IconSize::Large)?->toHtml() ?? '';

        return new HtmlString(sprintf(
            '<span class="crm-task-check %s" title="%s">%s<span class="sr-only">%s</span></span>',
            $class,
            e($text),
            $svg,
            e($text),
        ));
    }
}
