<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\CustomFieldEntity;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The record side of the custom field engine (decision D-9).
 *
 * A lead, contact, account or deal uses this trait and gains its values
 * relation and two readers. The entity a model stands for is named by the
 * model itself through customFieldEntity(), so the engine never has to guess
 * from a class name and a rename cannot silently orphan the definitions.
 *
 * The relation eager-loads the definition of each value: the readers below,
 * the table columns and the exporters all need the type to know which column
 * the value lives in, and one join beats one query per cell.
 *
 * Writing goes through CustomFieldValueService only — it validates against
 * the definitions before anything is stored.
 */
trait HasCustomFields
{
    /** The definition entity this model carries custom fields for. */
    abstract public static function customFieldEntity(): CustomFieldEntity;

    /**
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'entity')->with('field');
    }

    /** One field's typed value by its key, null when the record has none. */
    public function customField(string $key): mixed
    {
        $value = $this->customFieldValues
            ->first(static fn (CustomFieldValue $value): bool => $value->field instanceof CustomField
                && $value->field->getAttribute('key') === $key);

        return $value?->typedValue();
    }

    /**
     * Every stored value of the record, keyed by field key.
     *
     * @return array<string, mixed>
     */
    public function customFieldsAsArray(): array
    {
        $values = [];

        foreach ($this->customFieldValues as $value) {
            $field = $value->field;

            if ($field instanceof CustomField) {
                $values[(string) $field->getAttribute('key')] = $value->typedValue();
            }
        }

        return $values;
    }
}
