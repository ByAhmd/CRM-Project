<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * Latin-script table values — product codes, money, e-mail addresses, phone
 * numbers, URLs, file names, permission keys, timestamps — inside a table the
 * reader may be reading right-to-left.
 *
 * Those values need `dir="ltr"` to read in the right character order, but the
 * attribute must sit on an *inline* wrapper around the value, never on the
 * cell. Filament paints the body of a text cell as a block that inherits
 * `text-align: start` (see `.fi-ta-cell`/`.fi-ta-header-cell` in the tables
 * package): a cell declared LTR resolves "start" to the left, while its own
 * `<th>` follows the table and resolves it to the right, so in Arabic the
 * header sat on one edge of the column and its values on the other — the
 * defect the owner reported on «الرمز» and «سعر الوحدة» in the products table.
 *
 * Wrapping only the value keeps the bidi isolation the Latin characters need
 * (HTML gives `[dir]` `unicode-bidi: isolate`) and leaves the alignment to the
 * table's own direction, so the column reads correctly and lines up with its
 * header in ar/RTL and en/LTR alike, with no `[dir='…']` CSS exception and no
 * physical left/right value (CLAUDE.md section 3, "Theme").
 *
 * The wrapper is added through Filament's own `Htmlable` prefix and suffix.
 * That is the documented way to render markup around a column value, and
 * `CanFormatState::formatState()` escapes the state with `e()` before
 * concatenating it, so a user-entered code, e-mail or phone number is always
 * escaped and can never break out of the span. Because the wrapper uses the
 * prefix/suffix slots rather than `formatStateUsing()`, it composes with
 * `->money()`, `->dateTime()`, `->limit()` and `->url()`.
 *
 * It does, however, *occupy* those two slots: `CanFormatState::prefix()` and
 * `suffix()` assign rather than append, so a column that already carries a unit
 * — `->suffix('%')` on the pipeline stage probability, say — would lose it
 * silently. Rather than leave that trap for the next author, the helper refuses
 * such a column outright; give it a column without a prefix or suffix, or fold
 * the unit into the value itself.
 */
final class LtrText
{
    /**
     * Wraps the column's value in an inline LTR span, leaving the cell — and
     * therefore the alignment — in the table's direction.
     *
     * @throws LogicException when the column already uses its prefix or suffix
     */
    public static function column(TextColumn $column): TextColumn
    {
        if (filled($column->getPrefix()) || filled($column->getSuffix())) {
            throw new LogicException(sprintf(
                'The column [%s] already uses its prefix or suffix, which LtrText::column() needs for the '
                .'direction wrapper and would overwrite. Fold the unit into the value instead.',
                $column->getName(),
            ));
        }

        return $column
            ->prefix(new HtmlString('<span dir="ltr">'))
            ->suffix(new HtmlString('</span>'));
    }
}
