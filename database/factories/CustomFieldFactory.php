<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A custom field definition — a short text field on leads by default
 * (decision D-9).
 *
 * @extends Factory<CustomField>
 */
final class CustomFieldFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 99999);

        return [
            'entity' => CustomFieldEntity::Lead,
            'key' => 'field_'.$suffix,
            'label_ar' => 'حقل '.$suffix,
            'label_en' => 'Field '.$suffix,
            'type' => CustomFieldType::Text,
            'options' => null,
            'is_required' => false,
            'is_filterable' => false,
            'is_listed' => false,
            'is_active' => true,
            'sort' => 0,
            'validation' => null,
        ];
    }

    public function forEntity(CustomFieldEntity $entity): static
    {
        return $this->state(fn (array $attributes): array => ['entity' => $entity]);
    }

    /**
     * A definition of the given type; option types get two options unless the
     * caller names its own.
     *
     * @param  list<string>  $options
     */
    public function ofType(CustomFieldType $type, array $options = ['one', 'two']): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'options' => $type->hasOptions() ? self::optionsFor($options) : null,
        ]);
    }

    /**
     * @param  list<string>  $options
     */
    public function select(array $options = ['one', 'two']): static
    {
        return $this->ofType(CustomFieldType::Select, $options);
    }

    /**
     * @param  list<string>  $options
     */
    public function multiSelect(array $options = ['one', 'two']): static
    {
        return $this->ofType(CustomFieldType::MultiSelect, $options);
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes): array => ['is_required' => true]);
    }

    public function listed(): static
    {
        return $this->state(fn (array $attributes): array => ['is_listed' => true]);
    }

    public function filterable(): static
    {
        return $this->state(fn (array $attributes): array => ['is_filterable' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * @param  list<string>  $values
     * @return list<array<string, string>>
     */
    private static function optionsFor(array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'label_ar' => 'خيار '.$value,
                'label_en' => 'Option '.$value,
            ];
        }

        return $options;
    }
}
