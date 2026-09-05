<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A record's name in the language the user is reading (decision A-4).
 *
 * Configurable lookups carry both an Arabic and an English name, both
 * required. This is the read side: one rule applied identically wherever a
 * record is labelled instead of a locale check repeated at each call site.
 * The other language is the fallback so a blank label can never render.
 */
trait HasLocalisedName
{
    /**
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $arabic = (string) $this->getAttribute('name_ar');
            $english = (string) $this->getAttribute('name_en');

            if (app()->getLocale() === 'ar') {
                return $arabic !== '' ? $arabic : $english;
            }

            return $english !== '' ? $english : $arabic;
        });
    }

    /** The column holding the name for the current locale — for sorting and searching. */
    public static function localisedNameColumn(): string
    {
        return app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';
    }
}
