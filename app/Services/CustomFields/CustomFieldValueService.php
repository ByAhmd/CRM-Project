<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldType;
use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\RecordLabel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The only write path for custom field values (decision D-9).
 *
 * Everything a value must satisfy is derived from its definition and enforced
 * here, on the server: the Filament components mirror the same rules for the
 * user's benefit, but a payload that never went through a form (an import, a
 * console command, a future API) is validated identically.
 *
 * This service — not the page that calls it — is the authorisation boundary of
 * the engine. Values belong to their record, so the actor must be allowed to
 * `update` the record before a single value is validated, and to `view` it
 * before values are read back. A page, an importer, a bulk action or a future
 * endpoint therefore cannot write a value onto a record outside the actor's
 * visibility scope (D-4) by forgetting a gate.
 *
 * How a payload is read — the rule the callers depend on:
 *
 * - keys the payload does not carry are left untouched (a partial update
 *   never wipes a value it was not asked about);
 * - a key carrying null or an empty string clears the value, and the row is
 *   deleted rather than kept empty;
 * - `required` is enforced for the keys the payload carries, and additionally
 *   for every required field when the record was just created — so a record
 *   can never come into existence missing a mandatory field.
 *
 * The form always sends every field of the entity, so both paths are covered
 * by the same submission.
 *
 * Values are not audited on their own: they belong to their record, so a
 * change is written into the properties of the record's own `<entity>.updated`
 * ledger entry — and only when something actually changed.
 *
 * The definitions are read through the container's CustomFieldRegistry on
 * every call rather than through an injected instance: the registry is scoped
 * to the request or job and forgotten whenever a definition is saved or
 * deleted, so a write sees the definitions as they are now — including one an
 * administrator saved earlier in the same request — while an import of many
 * rows reads them once instead of once per row.
 */
final class CustomFieldValueService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  keyed by field key
     */
    public function fill(Model $record, array $values, User $actor): void
    {
        $entity = CustomFieldRegistry::entityFor($record);

        if ($entity === null) {
            throw InvalidCustomFieldException::unsupportedEntity($record::class);
        }

        Gate::forUser($actor)->authorize('update', $record);

        $fields = app(CustomFieldRegistry::class)->keyed($entity);

        if ($fields === []) {
            return;
        }

        $payload = [];

        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $values)) {
                $payload[$key] = $this->normalise($field, $values[$key]);
            }
        }

        $this->validate($record, $fields, $payload);

        $changes = DB::transaction(fn (): array => $this->store($record, $fields, $payload));

        if ($changes === []) {
            return;
        }

        $this->audit->record(
            ActivityLogEvent::from($entity->value.'.updated'),
            $record,
            $actor,
            [
                'subject_label' => RecordLabel::of($record),
                'custom_fields' => $changes,
            ],
        );
    }

    /**
     * Every stored value of a record in one query, keyed by field key — the
     * read side used by the form filler and the exporters.
     *
     * The actor is optional because the reader is also used where there is no
     * user at all (a console command, a queued export): when one is given the
     * read is authorised against the record's own `view` ability, when none is
     * given the caller is the application itself.
     *
     * @return array<string, mixed>
     */
    public function values(Model $record, ?User $actor = null): array
    {
        $entity = CustomFieldRegistry::entityFor($record);

        if ($entity === null) {
            return [];
        }

        if ($actor instanceof User) {
            Gate::forUser($actor)->authorize('view', $record);
        }

        $values = [];

        foreach ($this->rows($record) as $row) {
            $field = $row->field;

            if ($field instanceof CustomField) {
                $values[(string) $field->getAttribute('key')] = $row->typedValue();
            }
        }

        return $values;
    }

    /**
     * @param  array<string, CustomField>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function validate(Model $record, array $fields, array $payload): void
    {
        $rules = [];
        $messages = [];
        $attributes = [];
        $onCreate = $record->wasRecentlyCreated;

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $payload) && ! ($onCreate && $field->is_required)) {
                continue;
            }

            $rules[$key] = CustomFieldValidator::rulesFor($field);
            $messages += CustomFieldValidator::messagesFor($field, $key);
            $attributes += CustomFieldValidator::attributesFor($field, $key);
        }

        if ($rules === []) {
            return;
        }

        Validator::make($payload, $rules, $messages, $attributes)->validate();
    }

    /**
     * @param  array<string, CustomField>  $fields
     * @param  array<string, mixed>  $payload
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function store(Model $record, array $fields, array $payload): array
    {
        $changes = [];

        foreach ($payload as $key => $value) {
            $field = $fields[$key];
            $row = $this->row($record, $field);
            $old = $this->auditValue($row?->typedValue());
            $stored = $this->cast($field, $value);

            if ($stored === null || $stored === '' || $stored === []) {
                if ($row !== null) {
                    $row->delete();
                    $changes[$key] = ['old' => $old, 'new' => null];
                }

                continue;
            }

            if ($row === null) {
                $row = new CustomFieldValue([
                    'custom_field_id' => $field->getKey(),
                    'entity_type' => $record->getMorphClass(),
                    'entity_id' => $record->getKey(),
                ]);
                $row->setRelation('field', $field);
            }

            $row->setTypedValue($stored);
            $new = $this->auditValue($row->typedValue());

            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }

            $row->save();
        }

        return $changes;
    }

    private function row(Model $record, CustomField $field): ?CustomFieldValue
    {
        $row = CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->where('entity_type', $record->getMorphClass())
            ->where('entity_id', $record->getKey())
            ->first();

        return $row?->setRelation('field', $field);
    }

    /**
     * @return Collection<int, CustomFieldValue>
     */
    private function rows(Model $record): Collection
    {
        return CustomFieldValue::query()
            ->with('field')
            ->where('entity_type', $record->getMorphClass())
            ->where('entity_id', $record->getKey())
            ->get();
    }

    /**
     * The payload as the rules expect to see it: trimmed strings, dates as
     * text in the format the pickers send, lists without blanks. Emptiness is
     * one thing — null — whichever way it arrived.
     */
    private function normalise(CustomField $field, mixed $value): mixed
    {
        if ($field->type === CustomFieldType::MultiSelect) {
            if (! is_array($value)) {
                return $value === null || $value === '' ? null : $value;
            }

            $list = [];

            foreach ($value as $element) {
                if (is_string($element) && trim($element) !== '') {
                    $list[] = trim($element);
                }
            }

            return $list === [] ? null : array_values(array_unique($list));
        }

        if ($value instanceof DateTimeInterface) {
            // A programmatic caller may hand over any timezone; the columns and
            // the model's casts read Asia/Riyadh (D-8), so the wall time is
            // converted rather than copied.
            $moment = Carbon::instance($value)->setTimezone((string) config('app.timezone'));

            return $field->type === CustomFieldType::Date
                ? $moment->format('Y-m-d')
                : $moment->format('Y-m-d H:i');
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** The validated value in the shape its typed column stores. */
    private function cast(CustomField $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Number => (int) $value,
            CustomFieldType::Decimal => (float) $value,
            CustomFieldType::Boolean => (bool) $value,
            CustomFieldType::Date => Carbon::parse(is_string($value) ? $value : (string) $value)->format('Y-m-d'),
            CustomFieldType::DateTime => Carbon::parse(is_string($value) ? $value : (string) $value)->format('Y-m-d H:i:s'),
            CustomFieldType::MultiSelect => is_array($value) ? array_values($value) : [$value],
            default => (string) $value,
        };
    }

    /** A value as it is written to the ledger: JSON-safe, comparable, readable. */
    private function auditValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return $value;
    }
}
