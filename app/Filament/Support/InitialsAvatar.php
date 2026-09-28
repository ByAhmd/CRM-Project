<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The deterministic initials avatar beside a primary name column (A-23,
 * «data as hero»): the Leads, Contacts and Accounts lists open with a small
 * round mark carrying the record's initials, coloured by the record's name.
 *
 * Determinism is the point — the hue is derived from the name (crc32, 0-359),
 * so a record wears the same colour on every page load, every page and every
 * device, and two records with different names almost always differ. The
 * saturation and lightness are NOT encoded here: they come from the
 * `--crm-avatar-*` tokens in theme.css, fixed per theme, so every avatar
 * sits at the same visual weight in light and dark alike and the styling
 * stays under the token discipline.
 *
 * The markup travels the same escape-safe Htmlable affix path as LtrText:
 * the avatar is the column's prefix, `CanFormatState::formatState()` escapes
 * the state itself with `e()` before concatenating, and the initials inside
 * the span are escaped here — so a user-entered name can never break out of
 * the markup, in the avatar or in the name beside it. Because the avatar
 * lives inside the existing name column, no table gains a column and the
 * mobile column budget is untouched. The span is `aria-hidden`: the initials
 * repeat the name beside them, so a screen reader hears the name once.
 */
final class InitialsAvatar
{
    /**
     * @param  string  $attribute  the record attribute (or accessor) holding the display name
     */
    public static function column(TextColumn $column, string $attribute): TextColumn
    {
        return $column->prefix(static fn (Model $record): ?HtmlString => self::render((string) $record->getAttribute($attribute)));
    }

    private static function render(string $name): ?HtmlString
    {
        $initials = self::initials($name);

        if ($initials === '') {
            return null;
        }

        return new HtmlString(sprintf(
            '<span class="crm-avatar" style="--crm-avatar-h: %d" aria-hidden="true">%s</span>',
            self::hue($name),
            e($initials),
        ));
    }

    /** The first letter of the first and last words — one letter for a one-word name. */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        $initials = mb_substr($words[0], 0, 1);

        if (count($words) > 1) {
            $initials .= mb_substr($words[count($words) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }

    /** 0-359, stable for a given name so the record keeps its colour everywhere. */
    private static function hue(string $name): int
    {
        return crc32(mb_strtolower(trim($name))) % 360;
    }
}
