<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for custom field definitions (decision D-9).
 *
 * Definitions are settings, and the invariants they must keep are the ones
 * everything downstream relies on:
 *
 * 1. `key` is a snake_case slug, unique inside its entity, and never the name
 *    of a real column of that entity — a custom field called `email` on a lead
 *    would shadow the lead's own email in every form payload, import column
 *    and saved view.
 * 2. `key` and `entity` are immutable after creation, and `type` is immutable
 *    from the first stored value: stored values live in the column the type
 *    names, so a changed type would strand every one of them.
 * 3. A select and a multiselect carry at least one option; every other type
 *    carries none.
 * 4. A constraint a type cannot express (a `regex` on a date, a `max` on a
 *    toggle) is refused rather than silently ignored, and a `regex` is
 *    compiled and bounded here — a pattern PCRE cannot parse would fail every
 *    value of the field and never be attributable to its definition.
 * 5. A definition that already carries values is not deleted — it is
 *    deactivated, so history keeps its meaning (D-13).
 *
 * Every refusal is an InvalidCustomFieldException carrying a translated
 * message; the pages show it as a danger notification and halt.
 */
final class CustomFieldService
{
    /**
     * Names no custom field may take, whatever the entity: the columns every
     * record has, the relations the panel addresses by name, and the payload
     * key the engine itself owns.
     *
     * @var list<string>
     */
    private const array RESERVED_KEYS = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'created_by',
        'custom_fields', 'custom_field_values', 'tags', 'notes', 'tasks',
        'activities', 'attachments', 'owner', 'creator', 'record', 'key',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CustomField
    {
        $entity = $this->entityFrom($data['entity'] ?? null);

        if ($entity === null) {
            throw InvalidCustomFieldException::entityUnknown();
        }

        $type = $this->typeFrom($data['type'] ?? null);

        if ($type === null) {
            throw InvalidCustomFieldException::typeUnknown();
        }

        $key = $this->assertKey($entity, (string) ($data['key'] ?? ''));
        $options = $this->assertOptions($type, $data['options'] ?? null);
        $validation = $this->assertValidation($type, $data['validation'] ?? null);

        return DB::transaction(function () use ($data, $entity, $type, $key, $options, $validation): CustomField {
            $field = new CustomField;
            $field->fill($data);
            $field->setAttribute('entity', $entity);
            $field->setAttribute('type', $type);
            $field->setAttribute('key', $key);
            $field->setAttribute('options', $options);
            $field->setAttribute('validation', $validation);
            $field->setAttribute('sort', $this->sortFrom($data['sort'] ?? $field->getAttribute('sort')));
            $field->save();

            return $field;
        });
    }

    /**
     * The instance the caller holds is the one that is saved, so a page that
     * keeps its record after saving reads the persisted attributes.
     *
     * Disabled form fields are not dehydrated, so an absent `entity`, `key` or
     * `type` means "unchanged"; a value smuggled past the disabled state is
     * still compared against the stored one and refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomField $field, array $data): CustomField
    {
        return DB::transaction(function () use ($field, $data): CustomField {
            $current = CustomField::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail();

            $entity = $this->entityFrom($data['entity'] ?? null) ?? $current->entity;
            $type = $this->typeFrom($data['type'] ?? null) ?? $current->type;
            $key = array_key_exists('key', $data) ? (string) $data['key'] : (string) $current->getAttribute('key');

            if ($entity !== $current->entity) {
                throw InvalidCustomFieldException::entityLocked();
            }

            if ($key !== (string) $current->getAttribute('key')) {
                throw InvalidCustomFieldException::keyLocked();
            }

            if ($type !== $current->type && $this->valueCount($current) > 0) {
                throw InvalidCustomFieldException::typeLocked();
            }

            $options = $this->assertOptions($type, array_key_exists('options', $data) ? $data['options'] : $current->options);
            $validation = $this->assertValidation($type, array_key_exists('validation', $data) ? $data['validation'] : $current->validation);

            $field->setRawAttributes($current->getAttributes(), true);
            $field->fill($data);
            $field->setAttribute('entity', $entity);
            $field->setAttribute('type', $type);
            $field->setAttribute('key', $key);
            $field->setAttribute('options', $options);
            $field->setAttribute('validation', $validation);
            $field->setAttribute('sort', $this->sortFrom(
                array_key_exists('sort', $data) ? $data['sort'] : $current->getAttribute('sort'),
            ));
            $field->save();

            return $field;
        });
    }

    /**
     * A definition that carries values is never removed: dropping it would
     * cascade its values away with it. The administrator deactivates it, and
     * it disappears from every form, table and filter while the stored values
     * stay readable in the audit ledger.
     */
    public function delete(CustomField $field): void
    {
        DB::transaction(function () use ($field): void {
            $current = CustomField::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail();
            $count = $this->valueCount($current);

            if ($count > 0) {
                throw InvalidCustomFieldException::deleteHasValues($count);
            }

            $current->delete();
        });
    }

    /** Whether the definition may be removed at all — the pages disable the action when it may not. */
    public function isDeletable(CustomField $field): bool
    {
        return $this->valueCount($field) === 0;
    }

    /**
     * Presentation order inside one entity. Ids that are not definitions of
     * that entity are ignored, so a reordered table of several entities can
     * pass its whole list.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(CustomFieldEntity $entity, array $orderedIds): void
    {
        DB::transaction(static function () use ($entity, $orderedIds): void {
            $sort = 0;

            foreach ($orderedIds as $id) {
                $field = CustomField::query()->forEntity($entity)->whereKey($id)->first();

                if ($field instanceof CustomField) {
                    $field->update(['sort' => $sort]);
                    $sort++;
                }
            }
        });
    }

    /**
     * Presentation order for a list that may hold several entities — what the
     * settings table hands over after a drag. Each entity's rows are numbered
     * from zero in the order they appear, because `sort` only ever means
     * something inside one entity.
     *
     * @param  array<int|string>  $orderedIds
     */
    public function reorderAcross(array $orderedIds): void
    {
        $ids = array_values(array_map(static fn (int|string $id): int => (int) $id, $orderedIds));

        if ($ids === []) {
            return;
        }

        $fields = CustomField::query()->whereKey($ids)->get()->keyBy(static fn (CustomField $field): int => (int) $field->getKey());
        $grouped = [];

        foreach ($ids as $id) {
            $field = $fields->get($id);

            if ($field instanceof CustomField) {
                $grouped[$field->entity->value][] = (int) $field->getKey();
            }
        }

        foreach ($grouped as $entity => $entityIds) {
            $this->reorder(CustomFieldEntity::from($entity), $entityIds);
        }
    }

    /**
     * The names this entity's records already use, which a custom field may
     * therefore not take: the model's own fillable attributes plus the fixed
     * list above.
     *
     * @return list<string>
     */
    public static function reservedKeys(CustomFieldEntity $entity): array
    {
        $class = CustomFieldRegistry::modelClass($entity);
        $model = new $class;

        $reserved = self::RESERVED_KEYS;

        foreach ($model->getFillable() as $attribute) {
            $reserved[] = $attribute;
        }

        return array_values(array_unique($reserved));
    }

    private function assertKey(CustomFieldEntity $entity, string $key): string
    {
        $key = trim($key);

        if (preg_match(CustomField::KEY_PATTERN, $key) !== 1) {
            throw InvalidCustomFieldException::keyFormat();
        }

        if (in_array($key, self::reservedKeys($entity), true)) {
            throw InvalidCustomFieldException::keyReserved($key);
        }

        if (CustomField::query()->forEntity($entity)->where('key', $key)->exists()) {
            throw InvalidCustomFieldException::keyTaken();
        }

        return $key;
    }

    /**
     * @return ?list<array<string, string>>
     */
    private function assertOptions(CustomFieldType $type, mixed $options): ?array
    {
        $normalised = [];
        $seen = [];

        foreach (is_array($options) ? $options : [] as $option) {
            if (! is_array($option)) {
                throw InvalidCustomFieldException::invalidOption('');
            }

            $value = trim((string) ($option['value'] ?? ''));

            if ($value === '' || mb_strlen($value) > 100) {
                throw InvalidCustomFieldException::invalidOption($value);
            }

            if (in_array($value, $seen, true)) {
                throw InvalidCustomFieldException::invalidOption($value);
            }

            $seen[] = $value;
            $normalised[] = [
                'value' => $value,
                'label_ar' => trim((string) ($option['label_ar'] ?? '')),
                'label_en' => trim((string) ($option['label_en'] ?? '')),
            ];
        }

        if (! $type->hasOptions()) {
            if ($normalised !== []) {
                throw InvalidCustomFieldException::optionsForbidden();
            }

            return null;
        }

        if ($normalised === []) {
            throw InvalidCustomFieldException::optionsRequired();
        }

        return $normalised;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function assertValidation(CustomFieldType $type, mixed $validation): ?array
    {
        $allowed = CustomFieldValidator::allowedConstraints($type);
        $normalised = [];

        foreach (is_array($validation) ? $validation : [] as $key => $value) {
            $key = (string) $key;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (! in_array($key, $allowed, true)) {
                throw InvalidCustomFieldException::validationNotSupported($key);
            }

            $normalised[$key] = $key === 'regex' ? $this->assertPattern($value) : $value;
        }

        return $normalised === [] ? null : $normalised;
    }

    /**
     * A pattern is compiled before it is stored, never after.
     *
     * An uncompilable pattern is not a cosmetic mistake: it would be applied to
     * every value of the field, so `preg_match()` would warn into the log and
     * the rule would fail — a required field would make its whole parent record
     * unsavable and no administrator could tell why. It is also bounded here
     * rather than only in the form, because the form is not the write path.
     */
    private function assertPattern(mixed $pattern): string
    {
        if (! is_string($pattern) || mb_strlen($pattern) > CustomFieldValidator::PATTERN_LENGTH) {
            throw InvalidCustomFieldException::invalidPattern(is_scalar($pattern) ? (string) $pattern : '');
        }

        if (@preg_match(CustomFieldValidator::delimited($pattern), '') === false) {
            throw InvalidCustomFieldException::invalidPattern($pattern);
        }

        return $pattern;
    }

    /** The presentation order, kept inside the unsigned smallint column it is stored in. */
    private function sortFrom(mixed $value): int
    {
        return max(0, min(65535, is_numeric($value) ? (int) $value : 0));
    }

    private function valueCount(CustomField $field): int
    {
        return CustomFieldValue::query()->where('custom_field_id', $field->getKey())->count();
    }

    private function entityFrom(mixed $value): ?CustomFieldEntity
    {
        if ($value instanceof CustomFieldEntity) {
            return $value;
        }

        return is_string($value) && $value !== '' ? CustomFieldEntity::tryFrom($value) : null;
    }

    private function typeFrom(mixed $value): ?CustomFieldType
    {
        if ($value instanceof CustomFieldType) {
            return $value;
        }

        return is_string($value) && $value !== '' ? CustomFieldType::tryFrom($value) : null;
    }
}
