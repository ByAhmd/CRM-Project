<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadScoringRuleKind;
use App\Models\LeadScoringRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScoringRule>
 */
final class LeadScoringRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => LeadScoringRuleKind::FieldFilled,
            'field' => 'email',
            'points' => 10,
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
