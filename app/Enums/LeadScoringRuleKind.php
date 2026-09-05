<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a lead scoring rule looks at (decision D-7: rule-based scoring).
 */
enum LeadScoringRuleKind: string implements HasLabel
{
    case Source = 'source';
    case Status = 'status';
    case FieldFilled = 'field_filled';
    case ActivityRecency = 'activity_recency';

    public function getLabel(): string
    {
        return __('enums.lead_scoring_rule_kind.'.$this->value);
    }

    public function usesReference(): bool
    {
        return $this === self::Source || $this === self::Status;
    }

    public function usesField(): bool
    {
        return $this === self::FieldFilled;
    }

    public function usesDays(): bool
    {
        return $this === self::ActivityRecency;
    }
}
