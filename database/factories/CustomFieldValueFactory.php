<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored value — a short text on a lead by default (decision D-9).
 *
 * The factory writes the row as it is; production writes go through
 * CustomFieldValueService, which validates against the definition first.
 *
 * @extends Factory<CustomFieldValue>
 */
final class CustomFieldValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory(),
            'entity_type' => (new Lead)->getMorphClass(),
            'entity_id' => Lead::factory(),
            'value_string' => fake()->word(),
        ];
    }

    /** Store the value against the given record instead of a fresh lead. */
    public function forRecord(Model $record): self
    {
        return $this->state(fn (array $attributes): array => [
            'entity_type' => $record->getMorphClass(),
            'entity_id' => $record->getKey(),
        ]);
    }

    public function forField(CustomField $field): self
    {
        return $this->state(fn (array $attributes): array => [
            'custom_field_id' => $field->getKey(),
        ]);
    }
}
