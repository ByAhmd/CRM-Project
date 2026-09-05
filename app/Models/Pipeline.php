<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StageKind;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sales pipeline (decisions D-8, A-4).
 *
 * The ordered set of stages a deal moves through. Exactly one pipeline is
 * the default for new deals, and the default can be neither deactivated nor
 * deleted. Every pipeline carries at least one Open stage, exactly one Won,
 * exactly one Lost and exactly one default stage (which must be Open) — all
 * of it owned by PipelineService, the only sanctioned write path.
 *
 * @property bool $is_default
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'is_default', 'is_active', 'sort'])]
final class Pipeline extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    use HasLocalisedName;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'is_default', 'is_active'];
    }

    /** The pipeline new deals start in (D-8); exactly one row carries the flag. */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * @return HasOne<PipelineStage, $this>
     */
    public function defaultStage(): HasOne
    {
        return $this->hasOne(PipelineStage::class)->where('is_default', true);
    }

    /**
     * @return HasOne<PipelineStage, $this>
     */
    public function wonStage(): HasOne
    {
        return $this->hasOne(PipelineStage::class)->where('kind', StageKind::Won->value);
    }

    /**
     * @return HasOne<PipelineStage, $this>
     */
    public function lostStage(): HasOne
    {
        return $this->hasOne(PipelineStage::class)->where('kind', StageKind::Lost->value);
    }
}
