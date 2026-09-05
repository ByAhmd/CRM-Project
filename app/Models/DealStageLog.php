<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DealStageLogAppendOnlyObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One deal stage transition (decision D-8). Append-only: written by
 * DealStageWorkflow, never edited. `duration_seconds` is the time the deal
 * spent in the previous stage.
 *
 * @property Carbon $changed_at
 */
#[Fillable(['deal_id', 'from_stage_id', 'to_stage_id', 'changed_by', 'changed_at', 'notes', 'duration_seconds'])]
#[ObservedBy(DealStageLogAppendOnlyObserver::class)]
final class DealStageLog extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
