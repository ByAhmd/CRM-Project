<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Typed custom fields (decision D-9). Each type maps to one typed value column
 * on custom_field_values — never a JSON bag.
 */
enum CustomFieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Url = 'url';
    case Email = 'email';

    public function getLabel(): string
    {
        return __('enums.custom_field_type.'.$this->value);
    }

    /** The custom_field_values column this type is stored in. */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Text, self::Url, self::Email, self::Select => 'value_string',
            self::Textarea => 'value_text',
            self::Number => 'value_integer',
            self::Decimal => 'value_decimal',
            self::Date => 'value_date',
            self::DateTime => 'value_datetime',
            self::Boolean => 'value_boolean',
            self::MultiSelect => 'value_json',
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }
}
