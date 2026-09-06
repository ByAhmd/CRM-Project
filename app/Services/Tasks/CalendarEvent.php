<?php

declare(strict_types=1);

namespace App\Services\Tasks;

/**
 * One entry of the calendar feed (decision D-12), shaped for FullCalendar's
 * event source: a task (`task-<id>`) or a meeting / call activity
 * (`activity-<id>`).
 *
 * Dates are ISO 8601 strings in the organisation timezone; an all-day entry
 * carries the date alone. Colours are CSS `var(--crm-color-…)` references so
 * both themes paint the event from the tokens the calendar block of the
 * theme defines, never a hex value decided in PHP.
 */
final readonly class CalendarEvent
{
    /**
     * @param  list<string>  $classNames
     * @param  array<string, mixed>  $extendedProps
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $start,
        public ?string $end,
        public bool $allDay,
        public string $backgroundColor,
        public string $borderColor,
        public string $textColor,
        public ?string $url,
        public bool $editable,
        public array $classNames,
        public array $extendedProps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->start,
            'end' => $this->end,
            'allDay' => $this->allDay,
            'backgroundColor' => $this->backgroundColor,
            'borderColor' => $this->borderColor,
            'textColor' => $this->textColor,
            'url' => $this->url,
            'editable' => $this->editable,
            'classNames' => $this->classNames,
            'extendedProps' => $this->extendedProps,
        ];
    }
}
