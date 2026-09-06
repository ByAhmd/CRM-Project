<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One custom field's value on one record (decision D-9).
 *
 * Storage is typed: the definition's type names the single column the value
 * lives in and every other column is null, so a value is filtered, sorted and
 * indexed like a real column instead of being dug out of a JSON bag. The row
 * is written by CustomFieldValueService only — it is the layer that validates
 * against the definition before anything reaches the database.
 *
 * The values of a record are not audited on their own: they are recorded in
 * the properties of the parent record's own `<entity>.updated` ledger entry.
 *
 * @property ?int $value_integer
 * @property ?string $value_decimal
 * @property ?Carbon $value_date
 * @property ?Carbon $value_datetime
 * @property ?bool $value_boolean
 * @property ?list<string> $value_json
 */
#[Fillable([
    'custom_field_id', 'entity_type', 'entity_id',
    'value_string', 'value_text', 'value_integer', 'value_decimal',
    'value_date', 'value_datetime', 'value_boolean', 'value_json',
])]
final class CustomFieldValue extends Model
{
    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;

    /**
     * Every typed column, so writing one value can null the rest.
     *
     * @var list<string>
     */
    public const array VALUE_COLUMNS = [
        'value_string', 'value_text', 'value_integer', 'value_decimal',
        'value_date', 'value_datetime', 'value_boolean', 'value_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:4',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * The record the value belongs to, trashed subjects included so a
     * soft-deleted lead still reads its own values.
     *
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * The value out of the column the definition's type names: an int, a
     * string (decimals keep their scale), a Carbon, a bool or a list.
     */
    public function typedValue(): mixed
    {
        $field = $this->field;

        if (! $field instanceof CustomField) {
            return null;
        }

        return $this->getAttribute($field->valueColumn());
    }

    /**
     * Writes the value into the column its definition names and nulls every
     * other typed column, so a type that was changed before the first value
     * can never leave a stale column behind.
     */
    public function setTypedValue(mixed $value): void
    {
        $field = $this->field;

        if (! $field instanceof CustomField) {
            return;
        }

        foreach (self::VALUE_COLUMNS as $column) {
            $this->setAttribute($column, null);
        }

        $this->setAttribute($field->valueColumn(), $value);
    }
}
