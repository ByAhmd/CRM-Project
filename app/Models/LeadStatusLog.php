<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\LeadStatusLogAppendOnlyObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lead status transition (decision D-7). Append-only: written by
 * LeadStatusWorkflow and LeadConversionWorkflow, never edited.
 *
 * @property Carbon $changed_at
 */
#[Fillable(['lead_id', 'from_status_id', 'to_status_id', 'changed_by', 'changed_at', 'notes'])]
#[ObservedBy(LeadStatusLogAppendOnlyObserver::class)]
final class LeadStatusLog extends Model
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
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'from_status_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'to_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
