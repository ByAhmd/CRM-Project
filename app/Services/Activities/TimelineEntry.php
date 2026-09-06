<?php

declare(strict_types=1);

namespace App\Services\Activities;

use App\Enums\TimelineEntryKind;
use Illuminate\Support\Carbon;

/**
 * One line of a record's timeline (module row 12, D-13).
 *
 * Built by TimelineReader from an activity, a note, a task, a status or
 * stage log, an attachment or an audit row; the source type and id identify
 * the row it came from, so two entries never collide in a rendered list and
 * a page boundary can be reasoned about. Titles, badges, meta labels and
 * values arrive already translated for the current locale.
 */
final readonly class TimelineEntry
{
    /**
     * @param  array<string, string>  $meta  Translated label => display value.
     * @param  array<string, string>  $links  Translated label => URL, for entries that point at several records.
     */
    public function __construct(
        public TimelineEntryKind $kind,
        public Carbon $occurredAt,
        public string $title,
        public string $sourceType,
        public int $sourceId,
        public ?string $body = null,
        public ?string $actor = null,
        public ?string $url = null,
        public ?string $badge = null,
        public array $meta = [],
        public array $links = [],
    ) {}

    /** A stable key for `wire:key` and de-duplication. */
    public function key(): string
    {
        return $this->sourceType.'-'.$this->sourceId;
    }

    /** The calendar day the entry belongs to in the given timezone, as Y-m-d. */
    public function day(string $timezone): string
    {
        return $this->occurredAt->copy()->timezone($timezone)->format('Y-m-d');
    }
}
