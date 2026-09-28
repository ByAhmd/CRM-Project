<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Lead;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

/**
 * The lead score as a real meter (A-23, «data as hero»): a 0-100 fill on the
 * panel's own colour ramp instead of a bare number in a badge, on the leads
 * list and the lead view alike.
 *
 * The meter is the column's / entry's prefix on the same escape-safe
 * Htmlable affix path as LtrText, so the numeric state beside it stays
 * escaped and sortable exactly as before; the score interpolated into the
 * markup is clamped to an integer, never user text. The fill's inline size
 * grows from inline-start — CSS logical properties — so RTL and LTR need no
 * exceptions, and the bar is `aria-hidden` because the number beside it
 * already carries the value. The fill colour keeps the thresholds the old
 * badge wore: success from 70, warning from 40, gray below — resolved
 * through the panel ramp variables the theme already trades in.
 */
final class LeadScoreMeter
{
    public static function column(TextColumn $column): TextColumn
    {
        return $column->prefix(static fn (Lead $record): HtmlString => self::meter($record->effective_score));
    }

    /**
     * Not the prefix slot the column uses: an entry's prefix closure needs
     * the record injected, and the A-22 schema walk (SchemaColumnsTest)
     * reads prefixes off cold, record-less schemas. The formatted state is
     * evaluated at render only, where the state exists; Blade honours the
     * HtmlString, and the number beside the meter is escaped here.
     */
    public static function entry(TextEntry $entry): TextEntry
    {
        return $entry->formatStateUsing(static fn (int $state): HtmlString => new HtmlString(self::meter($state)->toHtml().e((string) $state)));
    }

    /** The Filament colour name for a score — the thresholds of D-7's rule-based scoring. */
    public static function color(int $score): string
    {
        return match (true) {
            $score >= 70 => 'success',
            $score >= 40 => 'warning',
            default => 'gray',
        };
    }

    private static function meter(int $score): HtmlString
    {
        $score = max(0, min(100, $score));

        return new HtmlString(sprintf(
            '<span class="crm-score-meter" aria-hidden="true"><span class="crm-score-meter-fill" style="inline-size: %d%%; background-color: var(--%s-500)"></span></span>',
            $score,
            self::color($score),
        ));
    }
}
