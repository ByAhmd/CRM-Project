<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeColor;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A bilingual tag an administrator defines once and users attach to leads,
 * contacts, accounts and deals (decision A-4).
 *
 * The model owns no relation back to the entities it labels: each taggable
 * entity declares the pivot side through App\Models\Concerns\HasTags, so
 * adding a taggable never touches this class.
 *
 * @property BadgeColor $color
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'color', 'is_active'])]
final class Tag extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use HasLocalisedName;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => BadgeColor::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'color', 'is_active'];
    }

    /**
     * Active tags as select options, keyed by id and labelled in the reader's
     * language, ordered by that same name.
     *
     * @return array<int, string>
     */
    public static function activeOptions(): array
    {
        $options = [];

        foreach (self::query()->where('is_active', true)->orderBy(self::localisedNameColumn())->get() as $tag) {
            $options[(int) $tag->getKey()] = $tag->display_name;
        }

        return $options;
    }
}
