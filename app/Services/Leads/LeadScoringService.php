<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\LeadScoringRuleKind;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use Illuminate\Support\Collection;

/**
 * Rule-based lead scoring (decision D-7).
 *
 * The score is the clamped (0–100) sum of every active rule that matches the
 * lead: its source, its status, a filled field, or activity within the last
 * N days. An administrator's manual override (score_override) wins when set;
 * the computed value is still kept so removing the override needs no recount.
 */
final class LeadScoringService
{
    /**
     * @var Collection<int, LeadScoringRule>|null
     */
    private ?Collection $rules = null;

    public function calculate(Lead $lead): int
    {
        $score = 0;

        foreach ($this->rules() as $rule) {
            if ($this->matches($rule, $lead)) {
                $score += (int) $rule->points;
            }
        }

        return max(0, min(100, $score));
    }

    /**
     * Persists a fresh computed score without touching the audit ledger. An
     * unchanged score is not written again, so the daily rescore of every open
     * lead costs one write per lead whose score actually moved; `scored_at`
     * is the moment the stored score was last computed differently or saved.
     */
    public function rescore(Lead $lead): void
    {
        $score = $this->calculate($lead);

        if ($lead->exists && ! $lead->isDirty('score') && $lead->score === $score) {
            return;
        }

        $lead->forceFill(['score' => $score, 'scored_at' => now()])->saveQuietly();
    }

    /** Drops the memoised rule set so a later calculation reads the database again. */
    public function refresh(): void
    {
        $this->rules = null;
    }

    private function matches(LeadScoringRule $rule, Lead $lead): bool
    {
        return match ($rule->kind) {
            LeadScoringRuleKind::Source => $rule->reference_id !== null && (int) $rule->reference_id === (int) $lead->lead_source_id,
            LeadScoringRuleKind::Status => $rule->reference_id !== null && (int) $rule->reference_id === (int) $lead->lead_status_id,
            LeadScoringRuleKind::FieldFilled => is_string($rule->field)
                && in_array($rule->field, LeadScoringRule::SCORABLE_FIELDS, true)
                && filled($lead->getAttribute($rule->field)),
            LeadScoringRuleKind::ActivityRecency => $lead->last_activity_at !== null
                && $rule->within_days !== null
                && $lead->last_activity_at->greaterThanOrEqualTo(now()->subDays((int) $rule->within_days)),
        };
    }

    /**
     * @return Collection<int, LeadScoringRule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= LeadScoringRule::query()->active()->orderBy('sort')->get();
    }
}
