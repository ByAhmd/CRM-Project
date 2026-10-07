<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DueTone;
use App\Models\Task;
use App\Services\Settings\OrganisationCalendar;
use DateTimeInterface;

/**
 * Relative due dates (decision D-27), on the organisation calendar
 * (OrganisationCalendar), in the reader's language with Latin digits:
 *
 * - an open task due before today: «متأخرة 3 أيام» / "3 days overdue",
 *   counted in calendar days (trans_choice, the Arabic forms), red;
 * - due today, at any hour: «اليوم 17:00» / "Today 17:00", amber while open —
 *   the dashboard's own boundary (TaskMetrics), so a task due this morning is
 *   "today" in every list, not overdue in one and today in another;
 * - due tomorrow: «غداً»; yesterday (a closed task): «أمس»;
 * - due within the coming week: the weekday's name, «الخميس»;
 * - otherwise the date, "2026-10-21".
 *
 * A completed or cancelled task keeps the words but never the warning
 * colours: its date is history (DueTone::Closed).
 */
final class RelativeDueDates
{
    /** The last day offset still named by its weekday: the coming week. */
    private const int WEEKDAY_HORIZON = 6;

    public function __construct(
        private readonly OrganisationCalendar $calendar,
    ) {}

    /** The task's due date in words, or null when it has none. */
    public function for(Task $task): ?RelativeDue
    {
        if ($task->due_at === null) {
            return null;
        }

        return $this->forMoment($task->due_at, $task->isOpen() && ! $task->trashed());
    }

    /** A due moment in words, for an open task or a closed one. */
    public function forMoment(DateTimeInterface $due, bool $open = true): RelativeDue
    {
        $moment = $this->calendar->local($due);
        $days = $this->calendar->daysFromToday($moment);

        if ($open && $days < 0) {
            return new RelativeDue(trans_choice('tasks.due.overdue', -$days, ['count' => -$days]), DueTone::Overdue, $moment);
        }

        $text = match (true) {
            $days === 0 => __('tasks.due.today', ['time' => $moment->format('H:i')]),
            $days === 1 => __('tasks.due.tomorrow'),
            $days === -1 => __('tasks.due.yesterday'),
            $days > 1 && $days <= self::WEEKDAY_HORIZON => __('tasks.due.weekdays.'.strtolower($moment->format('l'))),
            default => $moment->format('Y-m-d'),
        };

        $tone = match (true) {
            ! $open => DueTone::Closed,
            $days === 0 => DueTone::Today,
            default => DueTone::Upcoming,
        };

        return new RelativeDue($text, $tone, $moment);
    }
}
