<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityKind;
use App\Enums\BadgeColor;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use App\Observers\ActivityTypeObserver;
use Database\Factories\ActivityTypeFactory;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable activity type (decision A-4).
 *
 * The administrator names, colours and orders the types; `kind` is the
 * behaviour the timeline and the activity form reason about. One system row
 * per kind is seeded: it cannot be deleted (ActivityTypePolicy) and its kind
 * cannot change (ActivityTypeObserver). `icon` stores the NAME of a
 * Filament\Support\Icons\Heroicon case, e.g. `OutlinedPhone`.
 *
 * @property ActivityKind $kind
 * @property BadgeColor $color
 * @property bool $is_system
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'kind', 'icon', 'color', 'is_system', 'is_active', 'sort'])]
#[ObservedBy([ActivityTypeObserver::class])]
final class ActivityType extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<ActivityTypeFactory> */
    use HasFactory;

    use HasLocalisedName;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'color' => BadgeColor::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'kind', 'icon', 'color', 'is_system', 'is_active'];
    }

    /** The stored icon name resolved to its Heroicon case, or null when unset or unknown. */
    public function heroicon(): ?Heroicon
    {
        $name = $this->getAttribute('icon');

        if (! is_string($name) || $name === '' || ! defined(Heroicon::class.'::'.$name)) {
            return null;
        }

        $icon = constant(Heroicon::class.'::'.$name);

        return $icon instanceof Heroicon ? $icon : null;
    }
}
