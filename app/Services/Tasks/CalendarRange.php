<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Illuminate\Support\Collection;

/**
 * One visible range of the calendar (decision D-12): its entries, ordered by
 * start, and whether the range held more than CalendarFeed::MAX_EVENTS so
 * only the earliest of them were returned.
 */
final readonly class CalendarRange
{
    /**
     * @param  Collection<int, CalendarEvent>  $events
     */
    public function __construct(
        public Collection $events,
        public bool $truncated,
    ) {}
}
