<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use App\Observers\PipelineStageObserver;
use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stage of a sales pipeline (decisions D-8, A-4).
 *
 * `kind` is what the deal workflow reasons about and `probability` feeds the
 * forecast — Won is always 100 and Lost always 0. A stage belongs to its
 * pipeline for life (PipelineStageObserver refuses a change of pipeline) and
 * the last Won or Lost stage of a pipeline cannot be deleted. The stage set's
 * invariants are validated by PipelineService on every change.
 *
 * @property StageKind $kind
 * @property BadgeColor $color
 * @property bool $is_default
 * @property-read string $display_name
 */
#[Fillable(['pipeline_id', 'name_ar', 'name_en', 'kind', 'probability', 'color', 'is_default', 'sort'])]
#[ObservedBy(PipelineStageObserver::class)]
final class PipelineStage extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<PipelineStageFactory> */
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
            'kind' => StageKind::class,
            'color' => BadgeColor::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['pipeline_id', 'name_ar', 'name_en', 'kind', 'probability', 'color', 'is_default'];
    }

    /** The stage new deals of this pipeline start in (D-8); exactly one per pipeline. */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /** Won or Lost — the terminal kinds every pipeline has exactly one of. */
    public function isClosed(): bool
    {
        return $this->kind->isClosed();
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    /**
     * The other stages of the same pipeline and kind — what remains of that
     * kind if this stage were removed or changed.
     *
     * @return Builder<self>
     */
    public function siblingsOfSameKind(): Builder
    {
        return self::query()
            ->where('pipeline_id', $this->pipeline_id)
            ->where('kind', $this->kind->value)
            ->whereKeyNot($this->getKey());
    }
}
