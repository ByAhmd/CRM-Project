<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFields;

use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use App\Services\CustomFields\CustomFieldService;
use App\Services\CustomFields\CustomFieldValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The invariants of a definition (decision D-9): the key, the options, the
 * constraints, what may never change and what may never be deleted.
 */
final class CustomFieldServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CustomFieldService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();

        $this->service = app(CustomFieldService::class);
    }

    #[Test]
    public function a_definition_is_created_and_audited_as_a_lookup(): void
    {
        $field = $this->service->create($this->attributes());

        $this->assertSame('budget_note', $field->getAttribute('key'));
        $this->assertSame(CustomFieldEntity::Lead, $field->entity);
        $this->assertSame(CustomFieldType::Text, $field->type);
        $this->assertNull($field->options);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_id' => $field->getKey(),
        ]);
    }

    #[Test]
    public function a_key_that_is_not_a_slug_is_refused(): void
    {
        foreach (['Budget', 'budget note', '1budget', 'b', 'budget-note', ''] as $key) {
            try {
                $this->service->create($this->attributes(['key' => $key]));
                $this->fail('the key '.$key.' was accepted');
            } catch (InvalidCustomFieldException $exception) {
                $this->assertSame(__('custom_fields.validation.key_format'), $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_key_that_names_a_real_attribute_of_the_entity_is_refused(): void
    {
        foreach (['email', 'first_name', 'company_name', 'created_at', 'tags'] as $key) {
            try {
                $this->service->create($this->attributes(['key' => $key]));
                $this->fail('the reserved key '.$key.' was accepted');
            } catch (InvalidCustomFieldException $exception) {
                $this->assertSame(__('custom_fields.validation.key_reserved', ['key' => $key]), $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_key_is_unique_within_its_entity_but_free_in_another(): void
    {
        $this->service->create($this->attributes());

        $contactField = $this->service->create($this->attributes(['entity' => CustomFieldEntity::Contact->value]));

        $this->assertSame(CustomFieldEntity::Contact, $contactField->entity);

        $this->expectException(InvalidCustomFieldException::class);
        $this->expectExceptionMessage((string) __('custom_fields.validation.key_taken'));

        $this->service->create($this->attributes());
    }

    #[Test]
    public function a_choice_field_needs_options_and_every_other_type_refuses_them(): void
    {
        try {
            $this->service->create($this->attributes(['type' => CustomFieldType::Select->value, 'options' => []]));
            $this->fail('a select without options was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.options_required'), $exception->getMessage());
        }

        try {
            $this->service->create($this->attributes([
                'key' => 'other_key',
                'options' => [['value' => 'a', 'label_ar' => 'أ', 'label_en' => 'A']],
            ]));
            $this->fail('a text field with options was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.options_forbidden'), $exception->getMessage());
        }
    }

    #[Test]
    public function a_repeated_option_value_is_refused(): void
    {
        $this->expectException(InvalidCustomFieldException::class);
        $this->expectExceptionMessage((string) __('custom_fields.validation.invalid_option', ['value' => 'a']));

        $this->service->create($this->attributes([
            'type' => CustomFieldType::Select->value,
            'options' => [
                ['value' => 'a', 'label_ar' => 'أ', 'label_en' => 'A'],
                ['value' => ' a ', 'label_ar' => 'ب', 'label_en' => 'B'],
            ],
        ]));
    }

    #[Test]
    public function a_constraint_the_type_cannot_express_is_refused(): void
    {
        try {
            $this->service->create($this->attributes(['validation' => ['min' => 3]]));
            $this->fail('a numeric bound on a text field was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.validation_not_supported', ['key' => 'min']), $exception->getMessage());
        }

        try {
            $this->service->create($this->attributes([
                'key' => 'deal_weight',
                'type' => CustomFieldType::Number->value,
                'validation' => ['regex' => '/^a/'],
            ]));
            $this->fail('a pattern on a number field was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.validation_not_supported', ['key' => 'regex']), $exception->getMessage());
        }

        $number = $this->service->create($this->attributes([
            'key' => 'deal_weight',
            'type' => CustomFieldType::Number->value,
            'validation' => ['min' => 1, 'max' => 10, 'step' => 1],
        ]));

        $this->assertSame(['min' => 1, 'max' => 10, 'step' => 1], $number->validation);
    }

    #[Test]
    public function a_pattern_that_pcre_cannot_compile_is_refused_at_definition_time(): void
    {
        foreach (['[unclosed', '(', '*bad', str_repeat('a', CustomFieldValidator::PATTERN_LENGTH + 1)] as $pattern) {
            try {
                $this->service->create($this->attributes(['validation' => ['regex' => $pattern]]));
                $this->fail('the pattern '.$pattern.' was accepted');
            } catch (InvalidCustomFieldException $exception) {
                $this->assertSame(
                    __('custom_fields.validation.invalid_pattern', ['pattern' => $pattern]),
                    $exception->getMessage(),
                );
            }
        }

        $this->assertDatabaseCount('custom_fields', 0);

        $field = $this->service->create($this->attributes(['validation' => ['regex' => '^[A-Z]{3}$']]));

        $this->assertSame(['regex' => '^[A-Z]{3}$'], $field->validation);
        $this->assertContains('regex:/^[A-Z]{3}$/', CustomFieldValidator::rulesFor($field));
    }

    #[Test]
    public function an_unknown_entity_or_type_says_so_instead_of_blaming_the_key(): void
    {
        try {
            $this->service->create($this->attributes(['entity' => 'invoices']));
            $this->fail('an unknown entity was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.entity_unknown'), $exception->getMessage());
        }

        try {
            $this->service->create($this->attributes(['type' => 'colour']));
            $this->fail('an unknown type was accepted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.type_unknown'), $exception->getMessage());
        }
    }

    #[Test]
    public function the_presentation_order_is_kept_inside_the_column_that_stores_it(): void
    {
        $field = $this->service->create($this->attributes(['sort' => 70000]));

        $this->assertSame(65535, (int) $field->fresh()?->getAttribute('sort'));

        $this->service->update($field, ['sort' => -3]);

        $this->assertSame(0, (int) $field->fresh()?->getAttribute('sort'));
    }

    #[Test]
    public function a_reordered_list_of_several_entities_is_numbered_inside_each_entity(): void
    {
        $first = CustomField::factory()->create(['key' => 'first_key', 'sort' => 5]);
        $second = CustomField::factory()->create(['key' => 'second_key', 'sort' => 9]);
        $deal = CustomField::factory()->forEntity(CustomFieldEntity::Deal)->create(['key' => 'deal_key', 'sort' => 7]);

        $this->service->reorderAcross([$second->getKey(), $deal->getKey(), $first->getKey()]);

        $this->assertSame(0, (int) $second->fresh()?->getAttribute('sort'));
        $this->assertSame(1, (int) $first->fresh()?->getAttribute('sort'));
        $this->assertSame(0, (int) $deal->fresh()?->getAttribute('sort'));
    }

    #[Test]
    public function the_key_and_the_entity_never_change(): void
    {
        $field = $this->service->create($this->attributes());

        try {
            $this->service->update($field, ['key' => 'another_key']);
            $this->fail('the key was changed');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.key_locked'), $exception->getMessage());
        }

        try {
            $this->service->update($field, ['entity' => CustomFieldEntity::Deal->value]);
            $this->fail('the entity was changed');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(__('custom_fields.validation.entity_locked'), $exception->getMessage());
        }

        $this->service->update($field, ['label_en' => 'Budget note']);

        $this->assertSame('Budget note', $field->fresh()?->getAttribute('label_en'));
    }

    #[Test]
    public function the_model_itself_refuses_a_changed_key_as_a_programming_error(): void
    {
        $field = $this->service->create($this->attributes());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage((string) __('custom_fields.validation.key_locked'));

        $field->update(['key' => 'sneaky_key']);
    }

    #[Test]
    public function the_type_changes_until_the_first_value_exists(): void
    {
        $field = $this->service->create($this->attributes());

        $this->service->update($field, ['type' => CustomFieldType::Textarea->value]);
        $this->assertSame(CustomFieldType::Textarea, $field->fresh()?->type);

        $this->storeValue($field);

        $this->expectException(InvalidCustomFieldException::class);
        $this->expectExceptionMessage((string) __('custom_fields.validation.type_locked'));

        $this->service->update($field, ['type' => CustomFieldType::Number->value]);
    }

    #[Test]
    public function a_definition_holding_values_is_deactivated_instead_of_deleted(): void
    {
        $field = $this->service->create($this->attributes());

        $this->assertTrue($this->service->isDeletable($field));

        $this->storeValue($field);

        $this->assertFalse($this->service->isDeletable($field));

        try {
            $this->service->delete($field);
            $this->fail('a definition with values was deleted');
        } catch (InvalidCustomFieldException $exception) {
            $this->assertSame(trans_choice('custom_fields.validation.delete_has_values', 1, ['count' => '1']), $exception->getMessage());
        }

        $this->service->update($field, ['is_active' => false]);

        $this->assertDatabaseHas('custom_fields', ['id' => $field->getKey(), 'is_active' => false]);
        $this->assertDatabaseCount('custom_field_values', 1);
    }

    #[Test]
    public function an_empty_definition_is_deleted_and_audited(): void
    {
        $field = $this->service->create($this->attributes());

        $this->service->delete($field);

        $this->assertDatabaseMissing('custom_fields', ['id' => $field->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_id' => $field->getKey(),
        ]);
    }

    #[Test]
    public function reordering_numbers_the_definitions_of_one_entity_only(): void
    {
        $first = CustomField::factory()->create(['key' => 'first_key', 'sort' => 5]);
        $second = CustomField::factory()->create(['key' => 'second_key', 'sort' => 9]);
        $other = CustomField::factory()->forEntity(CustomFieldEntity::Deal)->create(['key' => 'deal_key', 'sort' => 7]);

        $this->service->reorder(CustomFieldEntity::Lead, [$second->getKey(), $first->getKey(), $other->getKey()]);

        $this->assertSame(0, (int) $second->fresh()?->getAttribute('sort'));
        $this->assertSame(1, (int) $first->fresh()?->getAttribute('sort'));
        $this->assertSame(7, (int) $other->fresh()?->getAttribute('sort'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'entity' => CustomFieldEntity::Lead->value,
            'key' => 'budget_note',
            'label_ar' => 'ملاحظة الميزانية',
            'label_en' => 'Budget note',
            'type' => CustomFieldType::Text->value,
            'is_required' => false,
            'is_filterable' => false,
            'is_listed' => false,
            'is_active' => true,
            'sort' => 0,
        ], $overrides);
    }

    private function storeValue(CustomField $field): CustomFieldValue
    {
        $lead = Lead::factory()->create();

        return CustomFieldValue::factory()->forField($field)->forRecord($lead)->create(['value_string' => 'stored']);
    }
}
