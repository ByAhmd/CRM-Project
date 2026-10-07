<?php

declare(strict_types=1);

namespace App\Services\Settings;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The organisation's clock and calendar (decisions D-8, A-19, A-20, D-27):
 * the one place every Tasks-workspace screen asks what "now", "today",
 * "tomorrow" and "this week" mean, so the relative due dates, the My Work
 * buckets, the board and the reschedule chips never disagree.
 *
 * Days are the organisation's calendar days — its timezone from the
 * settings, not the server's — and weeks start on the organisation's week
 * start (SettingsRepository::weekStartsOn(), 0 = Sunday … 6 = Saturday).
 * Moments handed back for reading are CarbonImmutable in the organisation
 * timezone; moments for a query or a write go through stored(), which
 * places them in the application timezone the datetime columns are kept in,
 * so a range compares the raw, indexed column (A-19: no SQL DATE()).
 *
 * The current moment comes from now(), so a test that travels in time moves
 * this clock with it.
 */
final class OrganisationCalendar
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function timezone(): string
    {
        return $this->settings->timezone();
    }

    /** 0 = Sunday … 6 = Saturday, as Carbon numbers the days. */
    public function weekStartsOn(): int
    {
        return $this->settings->weekStartsOn();
    }

    /** The current moment on the organisation clock. */
    public function now(): CarbonImmutable
    {
        return $this->local(now());
    }

    /** The given moment on the organisation clock. */
    public function local(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone());
    }

    /** The first moment of today, on the organisation clock. */
    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** The first moment of tomorrow, on the organisation clock. */
    public function tomorrow(): CarbonImmutable
    {
        return $this->today()->addDay();
    }

    /** The first moment of the organisation week holding the moment (default: now). */
    public function startOfWeek(?DateTimeInterface $moment = null): CarbonImmutable
    {
        $local = $moment === null ? $this->now() : $this->local($moment);

        return $local->startOfWeek($this->weekStartsOn())->startOfDay();
    }

    /** The first moment of next organisation week. */
    public function startOfNextWeek(): CarbonImmutable
    {
        return $this->startOfWeek()->addWeek();
    }

    /**
     * How many organisation calendar days the moment lies from today: 0
     * today, 1 tomorrow, -1 yesterday, whatever the time of day.
     */
    public function daysFromToday(DateTimeInterface $moment): int
    {
        return self::dayNumber($this->local($moment)) - self::dayNumber($this->now());
    }

    public function isToday(DateTimeInterface $moment): bool
    {
        return $this->daysFromToday($moment) === 0;
    }

    /**
     * A calendar day of the organisation at a time of day ("17:00"), on the
     * organisation clock. The day is read as a date alone — a moment's own
     * date on the organisation clock, or a "Y-m-d" string as a date picker
     * gives it.
     */
    public function at(DateTimeInterface|string $day, string $time): CarbonImmutable
    {
        $date = $day instanceof DateTimeInterface
            ? $this->local($day)->format('Y-m-d')
            : substr(trim($day), 0, 10);

        [$hour, $minute] = self::clock($time);

        return CarbonImmutable::parse($date, $this->timezone())->setTime($hour, $minute);
    }

    /** The organisation-clock time of day of a moment, "17:00". */
    public function timeOf(DateTimeInterface $moment): string
    {
        return $this->local($moment)->format('H:i');
    }

    /**
     * The moment in the application timezone the datetime columns are
     * stored in, as the mutable Carbon the services and the query builder
     * take.
     */
    public function stored(DateTimeInterface $moment): Carbon
    {
        return Carbon::instance($moment)->setTimezone((string) config('app.timezone'));
    }

    /**
     * The half-open range [first moment, first moment of the next day) of an
     * organisation calendar day, in the stored timezone — a "due that day"
     * query compares the raw column against both ends.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dayRange(DateTimeInterface|string $day): array
    {
        $start = $this->at($day, '00:00');

        return [$this->stored($start), $this->stored($start->addDay())];
    }

    /** A running day count for the calendar date the moment shows, independent of its clock time. */
    private static function dayNumber(CarbonImmutable $moment): int
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $moment->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));

        return $date === null ? 0 : intdiv($date->getTimestamp(), 86400);
    }

    /**
     * "17:00" as [17, 0]. The callers pass constants or a "H:i" they formatted
     * themselves; a malformed value reads as 00:00 and an hour or minute past
     * the clock is clamped, never rolled into the next day.
     *
     * @return array{0: int, 1: int}
     */
    private static function clock(string $time): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $match) !== 1) {
            return [0, 0];
        }

        return [min(23, (int) $match[1]), min(59, (int) $match[2])];
    }
}
