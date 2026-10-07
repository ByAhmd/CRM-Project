<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DueShortcut;
use App\Services\Settings\OrganisationCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * What moment a due-date chip means (decision D-27), on the organisation
 * calendar (OrganisationCalendar) — the one rule behind quick add and the
 * reschedule chips:
 *
 * - the time of day is the task's own when it already has a due date (a
 *   chip moves the day, not the hour the work was set for), otherwise the
 *   default 17:00 routine tasks use (RoutineTaskService::DEFAULT_DUE_TIME);
 * - Today is the first of that time, 17:00 and the last minute of the day
 *   still ahead, so "today" never lands in the past and turns the task red
 *   the moment it is chosen;
 * - Tomorrow is the next organisation day; Next week the first day of the
 *   next organisation week (the week start from the settings);
 * - Pick is the day the reader picked ("Y-m-d", read as an organisation
 *   date) — a picked past day is the reader's explicit choice and is kept;
 * - None is no due date (quick add only).
 *
 * Moments come back in the stored (application) timezone, ready for
 * TaskService.
 */
final class DueShortcuts
{
    /** The time of day a chip gives a task that has no due time of its own. */
    public const string DEFAULT_TIME = RoutineTaskService::DEFAULT_DUE_TIME;

    /** The last minute of an organisation day, the latest "today" a chip gives. */
    private const string END_OF_DAY = '23:59';

    public function __construct(
        private readonly OrganisationCalendar $calendar,
    ) {}

    /**
     * The due moment the chip means for a task due at $current (null for a
     * new task or one without a due date); null for None, and for Pick
     * without a valid picked day.
     */
    public function resolve(DueShortcut $shortcut, ?DateTimeInterface $current = null, ?string $pickedDay = null): ?Carbon
    {
        $time = $current === null ? self::DEFAULT_TIME : $this->calendar->timeOf($current);

        $moment = match ($shortcut) {
            DueShortcut::Today => $this->today($time),
            DueShortcut::Tomorrow => $this->calendar->at($this->calendar->tomorrow(), $time),
            DueShortcut::NextWeek => $this->calendar->at($this->calendar->startOfNextWeek(), $time),
            DueShortcut::Pick => self::isDate($pickedDay) ? $this->calendar->at((string) $pickedDay, $time) : null,
            DueShortcut::None => null,
        };

        return $moment === null ? null : $this->calendar->stored($moment);
    }

    /** Whether the value is a real calendar date written "Y-m-d". */
    public static function isDate(?string $value): bool
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    /** The first of the task's own time, 17:00 and the end of the day that is still ahead today. */
    private function today(string $time): CarbonImmutable
    {
        $now = $this->calendar->now();
        $today = $this->calendar->today();

        foreach ([$time, self::DEFAULT_TIME] as $candidate) {
            $moment = $this->calendar->at($today, $candidate);

            if ($moment->greaterThan($now)) {
                return $moment;
            }
        }

        return $this->calendar->at($today, self::END_OF_DAY);
    }
}
