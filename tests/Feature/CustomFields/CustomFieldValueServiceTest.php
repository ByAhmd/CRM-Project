<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFields;

use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\CustomFields\CustomFieldValidator;
use App\Services\CustomFields\CustomFieldValueService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Writing values (decision D-9): typed storage, server-side validation, the
 * partial-update rule, and the audit trail on the parent record.
 */
final class CustomFieldValueServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CustomFieldValueService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();

        $this->service = app(CustomFieldValueService::class);
        $this->actor = $this->admin();
    }

    #[Test]
    public function every_type_is_stored_in_its_own_column_and_read_back_typed(): void
    {
        $cases = [
            [CustomFieldType::Text, 'hello', 'value_string', 'hello'],
            [CustomFieldType::Textarea, 'a longer note', 'value_text', 'a longer note'],
            [CustomFieldType::Number, '42', 'value_integer', 42],
            [CustomFieldType::Decimal, '12.3456', 'value_decimal', '12.3456'],
            [CustomFieldType::Date, '2026-03-01', 'value_date', '2026-03-01'],
            [CustomFieldType::DateTime, '2026-03-01 14:30', 'value_datetime', '2026-03-01 14:30:00'],
            [CustomFieldType::Boolean, true, 'value_boolean', true],
            [CustomFieldType::Url, 'https://example.com', 'value_string', 'https://example.com'],
            [CustomFieldType::Email, 'buyer@example.com', 'value_string', 'buyer@example.com'],
            [CustomFieldType::Select, 'one', 'value_string', 'one'],
            [CustomFieldType::MultiSelect, ['one', 'two'], 'value_json', ['one', 'two']],
        ];

        foreach ($cases as [$type, $input, $column, $expected]) {
            $field = CustomField::factory()->ofType($type)->create();
            $lead = Lead::factory()->create();
            $key = (string) $field->getAttribute('key');

            $this->service->fill($lead, [$key => $input], $this->actor);

            $row = CustomFieldValue::query()
                ->where('custom_field_id', $field->getKey())
                ->where('entity_id', $lead->getKey())
                ->firstOrFail();

            $this->assertNotNull($row->getAttribute($column), $type->value.' was not stored in '.$column);

            foreach (CustomFieldValue::VALUE_COLUMNS as $other) {
                if ($other !== $column) {
                    $this->assertNull($row->getAttribute($other), $type->value.' also wrote '.$other);
                }
            }

            $format = $type === CustomFieldType::Date ? 'Y-m-d' : 'Y-m-d H:i:s';
            $typed = $row->setRelation('field', $field)->typedValue();
            $read = $lead->fresh()?->customField($key);

            $this->assertSame($expected, $typed instanceof Carbon ? $typed->format($format) : $typed, 'round trip of '.$type->value);
            $this->assertSame($expected, $read instanceof Carbon ? $read->format($format) : $read, 'reader of '.$type->value);
        }
    }

    #[Test]
    public function a_null_clears_the_value_by_deleting_its_row(): void
    {
        $field = CustomField::factory()->create();
        $lead = Lead::factory()->create();
        $key = (string) $field->getAttribute('key');

        $this->service->fill($lead, [$key => 'first'], $this->actor);
        $this->assertDatabaseCount('custom_field_values', 1);

        $this->service->fill($lead, [$key => null], $this->actor);
        $this->assertDatabaseCount('custom_field_values', 0);
        $this->assertNull($lead->fresh()?->customField($key));
    }

    #[Test]
    public function a_key_the_payload_does_not_carry_keeps_its_value(): void
    {
        $kept = CustomField::factory()->create(['key' => 'kept_key']);
        $changed = CustomField::factory()->create(['key' => 'changed_key']);
        $lead = Lead::factory()->create();

        $this->service->fill($lead, ['kept_key' => 'keep me', 'changed_key' => 'before'], $this->actor);
        $this->service->fill($lead, ['changed_key' => 'after'], $this->actor);

        $fresh = $lead->fresh();

        $this->assertSame('keep me', $fresh->customField('kept_key'));
        $this->assertSame('after', $fresh->customField('changed_key'));
        $this->assertSame(2, CustomFieldValue::query()->count());
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($changed->fresh());
    }

    #[Test]
    public function one_field_holds_at_most_one_value_per_record(): void
    {
        $field = CustomField::factory()->create();
        $lead = Lead::factory()->create();
        $key = (string) $field->getAttribute('key');

        $this->service->fill($lead, [$key => 'first'], $this->actor);
        $this->service->fill($lead, [$key => 'second'], $this->actor);

        $this->assertSame(1, CustomFieldValue::query()->where('custom_field_id', $field->getKey())->count());
        $this->assertSame('second', $lead->fresh()?->customField($key));
    }

    #[Test]
    public function a_value_that_breaks_its_definition_is_refused(): void
    {
        $number = CustomField::factory()->ofType(CustomFieldType::Number)->create([
            'key' => 'weight_key',
            'validation' => ['min' => 1, 'max' => 10],
        ]);
        $select = CustomField::factory()->select(['low', 'high'])->create(['key' => 'band_key']);
        $lead = Lead::factory()->create();

        $this->assertNotNull($number->fresh());

        foreach ([['weight_key' => 99], ['weight_key' => 'abc'], ['band_key' => 'unknown'], ['band_key' => ['low']]] as $payload) {
            try {
                $this->service->fill($lead, $payload, $this->actor);
                $this->fail('an invalid value was accepted: '.json_encode($payload));
            } catch (ValidationException $exception) {
                $this->assertNotSame([], $exception->errors());
            }
        }

        $this->assertDatabaseCount('custom_field_values', 0);
        $this->assertSame('band_key', (string) $select->getAttribute('key'));
    }

    #[Test]
    public function a_required_field_must_be_present_on_a_new_record_and_may_not_be_cleared(): void
    {
        $field = CustomField::factory()->required()->create(['key' => 'must_key']);
        $lead = Lead::factory()->create();

        try {
            $this->service->fill($lead, [], $this->actor);
            $this->fail('a new record was saved without its required custom field');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('custom_fields.validation.value_required', ['label' => $field->display_label])],
                $exception->errors()['must_key'] ?? [],
            );
        }

        $this->service->fill($lead, ['must_key' => 'present'], $this->actor);
        $this->assertSame('present', $lead->fresh()?->customField('must_key'));

        $stored = Lead::query()->findOrFail($lead->getKey());

        $this->service->fill($stored, [], $this->actor);
        $this->assertSame('present', $stored->fresh()?->customField('must_key'));

        $this->expectException(ValidationException::class);

        $this->service->fill($stored, ['must_key' => null], $this->actor);
    }

    #[Test]
    public function a_change_is_written_into_the_parent_records_audit_entry_and_nothing_is_written_when_nothing_changed(): void
    {
        $field = CustomField::factory()->create(['key' => 'budget_key']);
        $lead = Lead::factory()->create();

        $this->service->fill($lead, ['budget_key' => 'first'], $this->actor);
        $this->service->fill($lead, ['budget_key' => 'first'], $this->actor);

        $entries = ActivityLog::query()
            ->where('description', ActivityLogEvent::LeadUpdated->value)
            ->where('subject_id', $lead->getKey())
            ->get();

        $this->assertCount(1, $entries, 'an unchanged value was audited');

        $properties = $entries->first()?->properties->toArray() ?? [];

        $this->assertEqualsCanonicalizing(['old' => null, 'new' => 'first'], $properties['custom_fields']['budget_key'] ?? []);
        $this->assertSame($lead->full_name, $properties['subject_label'] ?? null);
        $this->assertSame($this->actor->getKey(), $entries->first()?->causer_id);

        $this->service->fill($lead, ['budget_key' => 'second'], $this->actor);

        $latest = ActivityLog::query()
            ->where('description', ActivityLogEvent::LeadUpdated->value)
            ->where('subject_id', $lead->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertEqualsCanonicalizing(['old' => 'first', 'new' => 'second'], $latest->properties->toArray()['custom_fields']['budget_key'] ?? []);
    }

    #[Test]
    public function the_four_entities_each_write_their_own_definitions(): void
    {
        $records = [
            Lead::factory()->create(),
            Contact::factory()->create(),
            Account::factory()->create(),
            Deal::factory()->create(),
        ];

        foreach ($records as $record) {
            $entity = $record::customFieldEntity();
            $field = CustomField::factory()->forEntity($entity)->create();
            $key = (string) $field->getAttribute('key');

            $this->service->fill($record, [$key => 'value of '.$entity->value], $this->actor);

            $this->assertDatabaseHas('custom_field_values', [
                'custom_field_id' => $field->getKey(),
                'entity_type' => $record->getMorphClass(),
                'entity_id' => $record->getKey(),
                'value_string' => 'value of '.$entity->value,
            ]);
        }
    }

    #[Test]
    public function a_long_note_is_bounded_by_what_its_column_holds_rather_than_truncated_by_the_database(): void
    {
        $field = CustomField::factory()->ofType(CustomFieldType::Textarea)->create(['key' => 'note_key']);
        $lead = Lead::factory()->create();

        // Arabic is two bytes per character, so the character ceiling is what
        // keeps a long paste inside a 65 535 *byte* TEXT column.
        $longest = str_repeat('ن', CustomFieldValidator::TEXT_LENGTH);

        $this->service->fill($lead, ['note_key' => $longest], $this->actor);

        $this->assertSame($longest, $lead->fresh()?->customField('note_key'));

        $this->expectException(ValidationException::class);

        $this->service->fill($lead, ['note_key' => $longest.'ن'], $this->actor);
    }

    #[Test]
    public function a_date_and_time_given_in_another_timezone_is_stored_as_the_riyadh_wall_time(): void
    {
        $field = CustomField::factory()->ofType(CustomFieldType::DateTime)->create(['key' => 'call_at_key']);
        $lead = Lead::factory()->create();

        $this->service->fill($lead, [
            'call_at_key' => new DateTimeImmutable('2026-03-01 12:00', new DateTimeZone('UTC')),
        ], $this->actor);

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->getKey(),
            'entity_id' => $lead->getKey(),
            'value_datetime' => '2026-03-01 15:00:00',
        ]);
    }

    #[Test]
    public function a_rep_writes_values_on_a_record_they_reach_and_on_no_other(): void
    {
        CustomField::factory()->create(['key' => 'note_key']);

        $rep = $this->salesRep();
        $other = $this->salesRep();

        $own = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $foreign = Lead::factory()->create(['owner_id' => $other->getKey()]);

        $this->service->fill($own, ['note_key' => 'mine'], $rep);

        $this->assertSame('mine', $own->fresh()?->customField('note_key'));

        try {
            $this->service->fill($foreign, ['note_key' => 'not mine'], $rep);
            $this->fail('a rep wrote values onto a record outside their scope');
        } catch (AuthorizationException) {
            // The service, not the page, is the authorisation boundary (D-4).
        }

        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $foreign->getKey()]);
        $this->assertSame(1, CustomFieldValue::query()->count());
    }

    #[Test]
    public function a_rep_reads_values_only_of_the_records_they_reach(): void
    {
        $field = CustomField::factory()->create(['key' => 'note_key']);

        $rep = $this->salesRep();
        $own = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $foreign = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->service->fill($own, ['note_key' => 'mine'], $rep);
        $this->service->fill($foreign, ['note_key' => 'theirs'], $this->actor);

        $this->assertSame(['note_key' => 'mine'], $this->service->values($own, $rep));
        $this->assertSame(['note_key' => 'theirs'], $this->service->values($foreign));
        $this->assertNotNull($field->fresh());

        $this->expectException(AuthorizationException::class);

        $this->service->values($foreign, $rep);
    }

    #[Test]
    public function an_inactive_definition_is_ignored(): void
    {
        $field = CustomField::factory()->inactive()->create(['key' => 'retired_key']);
        $lead = Lead::factory()->create();

        $this->service->fill($lead, ['retired_key' => 'value'], $this->actor);

        $this->assertDatabaseCount('custom_field_values', 0);
        $this->assertFalse($field->is_active);
    }
}
