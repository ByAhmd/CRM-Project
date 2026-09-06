<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFields;

use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The settings resource of the engine (decision D-9): only settings managers
 * reach it, the immutable attributes are locked in the form, and a definition
 * holding values cannot be deleted from it.
 */
final class CustomFieldResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function only_settings_managers_reach_the_resource(): void
    {
        $field = CustomField::factory()->create();

        $this->actingAs($this->admin())->get(CustomFieldResource::getUrl('index'))->assertOk();
        $this->actingAs($this->admin())->get(CustomFieldResource::getUrl('edit', ['record' => $field]))->assertOk();
        $this->actingAs($this->salesManager())->get(CustomFieldResource::getUrl('index'))->assertForbidden();
        $this->actingAs($this->salesRep())->get(CustomFieldResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($this->admin())->test(ListCustomFields::class)->assertCanSeeTableRecords([$field]);
    }

    #[Test]
    public function an_administrator_creates_a_text_field_and_it_is_audited(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCustomField::class)
            ->fillForm([
                'entity' => CustomFieldEntity::Lead->value,
                'key' => 'budget_note',
                'label_ar' => 'ملاحظة الميزانية',
                'label_en' => 'Budget note',
                'type' => CustomFieldType::Text->value,
                'is_listed' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $field = CustomField::query()->where('key', 'budget_note')->firstOrFail();

        $this->assertSame(CustomFieldEntity::Lead, $field->entity);
        $this->assertSame(CustomFieldType::Text, $field->type);
        $this->assertTrue($field->is_listed);
        $this->assertNull($field->options);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_id' => $field->getKey(),
        ]);
    }

    #[Test]
    public function a_key_that_is_not_a_slug_or_is_already_taken_is_rejected_by_the_form(): void
    {
        CustomField::factory()->create(['key' => 'budget_note']);

        Livewire::actingAs($this->admin())
            ->test(CreateCustomField::class)
            ->fillForm($this->formData(['key' => 'Budget Note']))
            ->call('create')
            ->assertHasFormErrors(['key']);

        Livewire::actingAs($this->admin())
            ->test(CreateCustomField::class)
            ->fillForm($this->formData(['key' => 'budget_note']))
            ->call('create')
            ->assertHasFormErrors(['key']);

        Livewire::actingAs($this->admin())
            ->test(CreateCustomField::class)
            ->fillForm($this->formData(['key' => 'email']))
            ->call('create')
            ->assertHasFormErrors(['key']);

        $this->assertSame(1, CustomField::query()->count());
    }

    #[Test]
    public function a_choice_field_is_created_with_its_options_and_they_are_reordered(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCustomField::class)
            ->fillForm($this->formData([
                'key' => 'buying_stage',
                'type' => CustomFieldType::Select->value,
                'options' => [
                    ['value' => 'early', 'label_ar' => 'مبكرة', 'label_en' => 'Early'],
                    ['value' => 'late', 'label_ar' => 'متأخرة', 'label_en' => 'Late'],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $field = CustomField::query()->where('key', 'buying_stage')->firstOrFail();

        $this->assertSame(['early', 'late'], $field->optionValues());

        Livewire::actingAs($this->admin())
            ->test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->fillForm([
                'options' => [
                    ['value' => 'late', 'label_ar' => 'متأخرة', 'label_en' => 'Late'],
                    ['value' => 'early', 'label_ar' => 'مبكرة', 'label_en' => 'Early'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['late', 'early'], $field->fresh()?->optionValues());
    }

    #[Test]
    public function the_key_and_the_entity_are_locked_on_edit_and_the_type_is_locked_once_values_exist(): void
    {
        $field = CustomField::factory()->create(['key' => 'budget_note']);

        Livewire::actingAs($this->admin())
            ->test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->assertFormFieldDisabled('key')
            ->assertFormFieldDisabled('entity')
            ->assertFormFieldEnabled('type');

        CustomFieldValue::factory()->forField($field)->forRecord(Lead::factory()->create())->create();

        Livewire::actingAs($this->admin())
            ->test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->assertFormFieldDisabled('type');
    }

    #[Test]
    public function a_definition_holding_values_cannot_be_deleted_and_is_deactivated_instead(): void
    {
        $field = CustomField::factory()->create(['key' => 'budget_note']);
        CustomFieldValue::factory()->forField($field)->forRecord(Lead::factory()->create())->create();

        $this->assertFalse($this->admin()->can('delete', $field));

        Livewire::actingAs($this->admin())
            ->test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->assertActionDisabled('delete')
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('custom_fields', ['id' => $field->getKey(), 'is_active' => false]);
        $this->assertDatabaseCount('custom_field_values', 1);
    }

    #[Test]
    public function an_empty_definition_is_deleted_from_the_edit_page(): void
    {
        $field = CustomField::factory()->create(['key' => 'budget_note']);

        Livewire::actingAs($this->admin())
            ->test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('custom_fields', ['id' => $field->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_id' => $field->getKey(),
        ]);
    }

    #[Test]
    public function the_list_is_filtered_by_entity_and_shows_how_many_values_a_definition_holds(): void
    {
        $lead = CustomField::factory()->create(['key' => 'lead_key']);
        $deal = CustomField::factory()->forEntity(CustomFieldEntity::Deal)->create(['key' => 'deal_key']);
        CustomFieldValue::factory()->forField($lead)->forRecord(Lead::factory()->create())->create();

        Livewire::actingAs($this->admin())
            ->test(ListCustomFields::class)
            ->filterTable('entity', CustomFieldEntity::Lead->value)
            ->assertCanSeeTableRecords([$lead])
            ->assertCanNotSeeTableRecords([$deal])
            ->assertTableColumnStateSet('values_count', 1, $lead);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'entity' => CustomFieldEntity::Lead->value,
            'key' => 'some_key',
            'label_ar' => 'حقل',
            'label_en' => 'Field',
            'type' => CustomFieldType::Text->value,
            'sort' => 0,
        ], $overrides);
    }
}
