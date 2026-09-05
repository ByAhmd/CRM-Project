<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadScoringRuleKind;
use App\Models\Concerns\AuditsAsLookup;
use App\Observers\LeadScoringRuleObserver;
use Database\Factories\LeadScoringRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead scoring rule (decision D-7).
 *
 * @property LeadScoringRuleKind $kind
 * @property bool $is_active
 */
#[Fillable(['kind', 'reference_id', 'field', 'within_days', 'points', 'is_active', 'sort'])]
#[ObservedBy(LeadScoringRuleObserver::class)]
final class LeadScoringRule extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<LeadScoringRuleFactory> */
    use HasFactory;

    /**
     * Lead attributes a field_filled rule may point at, with the lang key of
     * their label under leads.fields.
     *
     * @var list<string>
     */
    public const SCORABLE_FIELDS = ['email', 'phone', 'company_name', 'job_title', 'website', 'city', 'lead_source_id', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LeadScoringRuleKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['kind', 'reference_id', 'field', 'within_days', 'points', 'is_active'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** What the rule targets, for tables and the audit ledger. */
    public function targetLabel(): string
    {
        return match ($this->kind) {
            LeadScoringRuleKind::Source => (string) (LeadSource::query()->find($this->reference_id)?->getAttribute('display_name') ?? __('common.placeholders.empty')),
            LeadScoringRuleKind::Status => (string) (LeadStatus::query()->find($this->reference_id)?->getAttribute('display_name') ?? __('common.placeholders.empty')),
            LeadScoringRuleKind::FieldFilled => (string) __('leads.fields.'.$this->field),
            LeadScoringRuleKind::ActivityRecency => __('lead_scoring_rules.fields.within_days_value', ['days' => (int) $this->within_days]),
        };
    }
}
