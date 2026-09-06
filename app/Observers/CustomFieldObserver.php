<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Services\CustomFields\CustomFieldValidator;
use LogicException;

/**
 * The integrity guard of a custom field definition (decision D-9).
 *
 * Three attributes are immutable, because everything stored or saved around
 * them addresses the field by them: `key` and `entity` never change after
 * creation, and `type` stops changing the moment the first value exists — a
 * changed type would leave every stored value in the wrong column.
 * CustomFieldService refuses these with a translated message before the save;
 * reaching the model anyway is a programming error, hence LogicException.
 *
 * The observer also normalises the two JSON bags on every save, whoever
 * writes them: options are trimmed, emptied of blank values, deduplicated by
 * value and dropped entirely for types that have no options; validation keeps
 * only the constraints the type can express.
 */
final class CustomFieldObserver
{
    public function saving(CustomField $field): void
    {
        if ($field->exists) {
            if ($field->isDirty('key')) {
                throw new LogicException((string) __('custom_fields.validation.key_locked'));
            }

            if ($field->isDirty('entity')) {
                throw new LogicException((string) __('custom_fields.validation.entity_locked'));
            }

            if ($field->isDirty('type') && $this->hasValues($field)) {
                throw new LogicException((string) __('custom_fields.validation.type_locked'));
            }
        }

        $field->options = $this->normaliseOptions($field);
        $field->validation = $this->normaliseValidation($field);
    }

    /**
     * @return ?list<array<string, string>>
     */
    private function normaliseOptions(CustomField $field): ?array
    {
        if (! $field->type->hasOptions()) {
            return null;
        }

        $options = [];
        $seen = [];

        foreach ($field->options ?? [] as $option) {
            $value = trim((string) ($option['value'] ?? ''));

            if ($value === '' || in_array($value, $seen, true)) {
                continue;
            }

            $seen[] = $value;
            $options[] = [
                'value' => $value,
                'label_ar' => trim((string) ($option['label_ar'] ?? '')),
                'label_en' => trim((string) ($option['label_en'] ?? '')),
            ];
        }

        return $options === [] ? null : $options;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function normaliseValidation(CustomField $field): ?array
    {
        $allowed = CustomFieldValidator::allowedConstraints($field->type);
        $validation = [];

        foreach ($field->validation ?? [] as $key => $value) {
            if (! in_array((string) $key, $allowed, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $validation[(string) $key] = $value;
        }

        return $validation === [] ? null : $validation;
    }

    private function hasValues(CustomField $field): bool
    {
        return CustomFieldValue::query()->where('custom_field_id', $field->getKey())->exists();
    }
}
