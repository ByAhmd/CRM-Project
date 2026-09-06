<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\Concerns\AuditsAsLookup;
use App\Observers\CustomFieldObserver;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An administrator-defined field on a lead, contact, account or deal (D-9).
 *
 * The definition is settings data: it is written through CustomFieldService
 * only, audited as a lookup (A-5), and immutable where the rest of the system
 * depends on it — `key` and `entity` never change after creation, `type` stops
 * changing the moment a value exists (CustomFieldObserver).
 *
 * `options` is the bilingual choice list of a select or multiselect,
 * `validation` the type's own constraints; both are normalised on save.
 *
 * @property CustomFieldEntity $entity
 * @property CustomFieldType $type
 * @property ?list<array<string, string>> $options
 * @property ?array<string, mixed> $validation
 * @property bool $is_required
 * @property bool $is_filterable
 * @property bool $is_listed
 * @property bool $is_active
 * @property-read string $display_label
 */
#[Fillable([
    'entity', 'key', 'label_ar', 'label_en', 'type', 'options',
    'is_required', 'is_filterable', 'is_listed', 'is_active', 'sort', 'validation',
])]
#[ObservedBy(CustomFieldObserver::class)]
final class CustomField extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    /** The shape a key must have: a snake_case slug of 2 to 50 characters. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => CustomFieldEntity::class,
            'type' => CustomFieldType::class,
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_listed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return [
            'entity', 'key', 'label_ar', 'label_en', 'type', 'options',
            'is_required', 'is_filterable', 'is_listed', 'is_active', 'sort', 'validation',
        ];
    }

    /**
     * The label in the language the user is reading, the other language as
     * the fallback (A-4) — field labels are data, not translation keys.
     *
     * @return Attribute<string, never>
     */
    protected function displayLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $arabic = (string) $this->getAttribute('label_ar');
            $english = (string) $this->getAttribute('label_en');

            if (app()->getLocale() === 'ar') {
                return $arabic !== '' ? $arabic : $english;
            }

            return $english !== '' ? $english : $arabic;
        });
    }

    /** The label of one stored option value, the raw value when it is unknown. */
    public function optionLabel(string $value): string
    {
        foreach ($this->options ?? [] as $option) {
            if (($option['value'] ?? null) === $value) {
                $arabic = (string) ($option['label_ar'] ?? '');
                $english = (string) ($option['label_en'] ?? '');

                if (app()->getLocale() === 'ar') {
                    return $arabic !== '' ? $arabic : ($english !== '' ? $english : $value);
                }

                return $english !== '' ? $english : ($arabic !== '' ? $arabic : $value);
            }
        }

        return $value;
    }

    /**
     * The option values as stored, in definition order.
     *
     * @return list<string>
     */
    public function optionValues(): array
    {
        $values = [];

        foreach ($this->options ?? [] as $option) {
            $value = (string) ($option['value'] ?? '');

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The options as a picker's `value => label` map in the reader's language.
     *
     * @return array<string, string>
     */
    public function optionLabels(): array
    {
        $labels = [];

        foreach ($this->optionValues() as $value) {
            $labels[$value] = $this->optionLabel($value);
        }

        return $labels;
    }

    /** The `custom_field_values` column this definition's values live in (D-9). */
    public function valueColumn(): string
    {
        return $this->type->valueColumn();
    }

    /** One constraint from the `validation` bag, null when it is not set. */
    public function constraint(string $key): mixed
    {
        return $this->validation[$key] ?? null;
    }

    public function hasValues(): bool
    {
        return $this->values()->exists();
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEntity(Builder $query, CustomFieldEntity $entity): Builder
    {
        return $query->where('entity', $entity->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_listed', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
