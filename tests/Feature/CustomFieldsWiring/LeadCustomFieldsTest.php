<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFieldsWiring;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\User;
use DateTimeInterface;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The custom field engine wired into the lead resource (decision D-9): the
 * create and edit forms, the view page, the list table's columns and filters.
 *
 * Every assertion is locale-agnostic: a label is read off the definition
 * (`display_label`, which is data, not a translation key) and a message off the
 * language files, so the suite proves the same thing in Arabic and in English.
 */
final class LeadCustomFieldsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->actor = $this->admin();
    }

    #[Test]
    public function the_create_page_writes_a_value_of_every_shape_into_its_own_typed_column(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(CreateLead::class)
            ->fillForm([
                'first_name' => 'فهد',
                'last_name' => 'القحطاني',
                CustomFieldsSchema::STATE_PATH => [
                    'focus_key' => 'Riyadh expo',
                    'band_key' => 'one',
                    'vip_key' => true,
                    'renewal_key' => '2026-05-09',
                    'cities_key' => ['one', 'two'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('last_name', 'القحطاني')->firstOrFail();

        $this->assertSame('Riyadh expo', $lead->customField('focus_key'));
        $this->assertSame('one', $lead->customField('band_key'));
        $this->assertTrue($lead->customField('vip_key'));
        $this->assertSame(['one', 'two'], $lead->customField('cities_key'));
        $this->assertSame('2026-05-09', $this->date($lead, 'renewal_key'));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $this->definition('focus_key')->getKey(),
            'entity_type' => $lead->getMorphClass(),
            'entity_id' => $lead->getKey(),
            'value_string' => 'Riyadh expo',
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $this->definition('vip_key')->getKey(),
            'entity_id' => $lead->getKey(),
            'value_boolean' => 1,
        ]);
    }

    #[Test]
    public function a_required_definition_blocks_the_create_page_and_names_itself_in_the_error(): void
    {
        $field = CustomField::factory()->required()->create(['key' => 'focus_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateLead::class)
            ->fillForm([
                'first_name' => 'فهد',
                'last_name' => 'القحطاني',
                CustomFieldsSchema::STATE_PATH => ['focus_key' => ''],
            ])
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.focus_key' => 'required'])
            ->assertSee(__('custom_fields.validation.value_required', ['label' => $field->display_label]));

        $this->assertDatabaseCount('leads', 0);
    }

    #[Test]
    public function a_required_definition_blocks_the_edit_page_too(): void
    {
        CustomField::factory()->required()->create(['key' => 'focus_key', 'sort' => 0]);

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['focus_key' => 'kept']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm([CustomFieldsSchema::STATE_PATH => ['focus_key' => '']])
            ->call('save')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.focus_key' => 'required']);

        $this->assertSame('kept', $lead->fresh()?->customField('focus_key'));
    }

    #[Test]
    public function the_edit_page_is_filled_with_the_stored_values_and_saves_the_changed_ones(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => [
                'focus_key' => 'Riyadh expo',
                'band_key' => 'one',
                'vip_key' => true,
                'renewal_key' => '2026-05-09',
                'cities_key' => ['one'],
            ],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormSet([
                CustomFieldsSchema::STATE_PATH.'.focus_key' => 'Riyadh expo',
                CustomFieldsSchema::STATE_PATH.'.band_key' => 'one',
                CustomFieldsSchema::STATE_PATH.'.vip_key' => true,
                CustomFieldsSchema::STATE_PATH.'.cities_key' => ['one'],
                CustomFieldsSchema::STATE_PATH.'.renewal_key' => '2026-05-09',
            ])
            ->fillForm([
                CustomFieldsSchema::STATE_PATH => [
                    'focus_key' => 'Jeddah expo',
                    'band_key' => 'two',
                    'vip_key' => false,
                    'renewal_key' => '2027-01-31',
                    'cities_key' => ['one', 'two'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();

        $this->assertSame('Jeddah expo', $lead->customField('focus_key'));
        $this->assertSame('two', $lead->customField('band_key'));
        $this->assertFalse($lead->customField('vip_key'));
        $this->assertSame('2027-01-31', $this->date($lead, 'renewal_key'));
        $this->assertSame(['one', 'two'], $lead->customField('cities_key'));
    }

    #[Test]
    public function saving_the_edit_page_without_touching_a_value_leaves_it_exactly_as_it_was(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => [
                'focus_key' => 'Riyadh expo',
                'band_key' => 'one',
                'vip_key' => true,
                'renewal_key' => '2026-05-09',
                'cities_key' => ['one', 'two'],
            ],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['job_title' => 'CTO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();

        $this->assertSame('CTO', $lead->job_title);
        $this->assertSame('Riyadh expo', $lead->customField('focus_key'));
        $this->assertSame('one', $lead->customField('band_key'));
        $this->assertTrue($lead->customField('vip_key'));
        $this->assertSame('2026-05-09', $this->date($lead, 'renewal_key'));
        $this->assertSame(['one', 'two'], $lead->customField('cities_key'));
    }

    #[Test]
    public function clearing_a_value_on_the_edit_page_deletes_its_row(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Riyadh expo', 'band_key' => 'one'],
        ], $this->actor);

        $this->assertDatabaseCount('custom_field_values', 2);

        Livewire::actingAs($this->actor)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm([CustomFieldsSchema::STATE_PATH => ['focus_key' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_id' => $this->definition('focus_key')->getKey(),
            'entity_id' => $lead->getKey(),
        ]);
        $this->assertSame('one', $lead->fresh()?->customField('band_key'));
    }

    #[Test]
    public function the_view_page_shows_the_section_with_the_values_a_reader_expects(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);
        $band = $this->definition('band_key');

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Riyadh expo', 'band_key' => 'one', 'vip_key' => true],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertSee(__('custom_fields.sections.custom'))
            ->assertSee($this->definition('focus_key')->display_label)
            ->assertSee('Riyadh expo')
            ->assertSee($band->optionLabel('one'))
            ->assertSee(__('custom_fields.values.yes'));
    }

    #[Test]
    public function a_listed_definition_becomes_a_column_the_reader_can_switch_on(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Riyadh expo']], $this->actor);

        $column = CustomFieldsSchema::NAME_PREFIX.'focus_key';

        Livewire::actingAs($this->actor)
            ->test(ListLeads::class)
            ->assertCanNotRenderTableColumn($column);

        $page = Livewire::actingAs($this->actor)->test(ListLeads::class);
        $instance = $page->instance();

        $this->assertInstanceOf(ListLeads::class, $instance);

        $page->call('applyTableColumnManager', $this->columnStateWith($instance->getDefaultTableColumnState(), $column))
            ->assertCanRenderTableColumn($column)
            ->assertSee('Riyadh expo');
    }

    #[Test]
    public function a_filterable_definition_narrows_the_list_without_widening_the_actors_scope(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $othersButMatching = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $mineButNotMatching = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        CustomFieldsSchema::persist($mine, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'one']], $this->actor);
        CustomFieldsSchema::persist($othersButMatching, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'one']], $this->actor);
        CustomFieldsSchema::persist($mineButNotMatching, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'two']], $this->actor);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->filterTable(CustomFieldsSchema::NAME_PREFIX.'band_key', 'one')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$othersButMatching, $mineButNotMatching]);
    }

    #[Test]
    public function a_deactivated_definition_is_on_no_page_at_all(): void
    {
        $retired = CustomField::factory()->inactive()->listed()->filterable()->create(['key' => 'retired_key', 'sort' => 0]);

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(CreateLead::class)
            ->assertDontSee($retired->display_label);

        Livewire::actingAs($this->actor)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertDontSee($retired->display_label);

        Livewire::actingAs($this->actor)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertDontSee(__('custom_fields.sections.custom'));

        $instance = Livewire::actingAs($this->actor)->test(ListLeads::class)->instance();

        $this->assertInstanceOf(ListLeads::class, $instance);
        $this->assertNull($instance->getTable()->getColumn(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
        $this->assertNull($instance->getTable()->getFilter(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
    }

    #[Test]
    public function a_choice_is_offered_and_shown_in_the_language_the_reader_uses(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'one']], $this->actor);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $band = $this->definition('band_key');

            Livewire::actingAs($this->actor)
                ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
                ->assertSee($band->display_label)
                ->assertSee($band->optionLabel('one'));
        }

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function a_date_the_picker_cannot_read_is_refused_instead_of_crashing_the_request(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(CreateLead::class)
            ->fillForm(['first_name' => 'فهد', 'last_name' => 'القحطاني'])
            ->set('data.'.CustomFieldsSchema::STATE_PATH.'.renewal_key', 'not-a-date-at-all')
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.renewal_key']);

        $this->assertDatabaseCount('leads', 0);
    }

    #[Test]
    public function the_inline_edit_action_of_the_list_table_shows_the_stored_values_and_writes_the_changed_ones(): void
    {
        $this->definitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey(), 'job_title' => 'old']);

        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Riyadh expo']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ListLeads::class)
            ->mountAction(TestAction::make('edit')->table($lead))
            ->assertActionDataSet([CustomFieldsSchema::STATE_PATH.'.focus_key' => 'Riyadh expo'])
            ->setActionData([
                'job_title' => 'new',
                CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Jeddah expo'],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $lead->refresh();

        $this->assertSame('new', $lead->job_title);
        $this->assertSame('Jeddah expo', $lead->customField('focus_key'));
    }

    #[Test]
    public function the_header_create_action_of_the_list_page_writes_the_values_too(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(ListLeads::class)
            ->callAction(TestAction::make('create'), [
                'first_name' => 'سارة',
                'last_name' => 'العتيبي',
                CustomFieldsSchema::STATE_PATH => ['focus_key' => 'Riyadh expo', 'band_key' => 'two'],
            ])
            ->assertHasNoActionErrors();

        $lead = Lead::query()->where('last_name', 'العتيبي')->firstOrFail();

        $this->assertSame('Riyadh expo', $lead->customField('focus_key'));
        $this->assertSame('two', $lead->customField('band_key'));
    }

    #[Test]
    public function a_rep_cannot_write_a_value_onto_a_lead_outside_their_reach(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $others = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->actingAs($rep);

        $this->expectException(AuthorizationException::class);

        try {
            CustomFieldActions::persist($others, ['focus_key' => 'Riyadh expo']);
        } finally {
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    /**
     * A handful of definitions of different shapes on the lead entity, in the
     * order they are presented.
     */
    private function definitions(): void
    {
        CustomField::factory()->listed()->create(['key' => 'focus_key', 'sort' => 0]);
        CustomField::factory()->select(['one', 'two'])->filterable()->create(['key' => 'band_key', 'sort' => 1]);
        CustomField::factory()->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 2]);
        CustomField::factory()->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 3]);
        CustomField::factory()->multiSelect(['one', 'two'])->create(['key' => 'cities_key', 'sort' => 4]);
    }

    /** A stored date value as text, whatever shape the cast hands back. */
    private function date(Lead $record, string $key): ?string
    {
        $value = $record->customField($key);

        return $value instanceof DateTimeInterface ? Carbon::instance($value)->format('Y-m-d') : null;
    }

    private function definition(string $key): CustomField
    {
        return CustomField::query()
            ->forEntity(CustomFieldEntity::Lead)
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
     * The table's column manager state with one toggleable column switched on
     * — what the reader does through the columns dropdown.
     *
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, array<string, mixed>>
     */
    private function columnStateWith(array $state, string $name): array
    {
        foreach ($state as $index => $item) {
            if (($item['name'] ?? null) === $name) {
                $state[$index]['isToggled'] = true;
            }
        }

        return $state;
    }
}
