<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\LeadStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable lead status (decisions D-7, A-4).
 *
 * The administrator names, colours and orders statuses; `kind` is what the
 * workflow reasons about. Exactly one row is the default for new leads and
 * exactly one row is of kind Converted — both invariants are owned by
 * LeadStatusService, which is the only sanctioned write path.
 *
 * @property LeadStatusKind $kind
 * @property BadgeColor $color
 * @property bool $is_default
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'kind', 'color', 'is_default', 'is_active', 'sort'])]
final class LeadStatus extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<LeadStatusFactory> */
    use HasFactory;

    use HasLocalisedName;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LeadStatusKind::class,
            'color' => BadgeColor::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'kind', 'color', 'is_default', 'is_active'];
    }

    /** The status new leads start in (D-7); exactly one row carries the flag. */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /** The single terminal status leads reach on conversion (D-7). */
    public function isConverted(): bool
    {
        return $this->kind === LeadStatusKind::Converted;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
