<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFieldsWiring;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The custom field engine wired into the contact resource (decision D-9),
 * including the create form the account page reuses in its relation manager.
 */
final class ContactCustomFieldsTest extends TestCase
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
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'سارة',
                'last_name' => 'العتيبي',
                CustomFieldsSchema::STATE_PATH => [
                    'floor_key' => 'Third floor',
                    'seats_key' => 12,
                    'band_key' => 'one',
                    'portal_key' => 'https://portal.example.com',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = Contact::query()->where('last_name', 'العتيبي')->firstOrFail();

        $this->assertSame('Third floor', $contact->customField('floor_key'));
        $this->assertSame(12, $contact->customField('seats_key'));
        $this->assertSame('one', $contact->customField('band_key'));
        $this->assertSame('https://portal.example.com', $contact->customField('portal_key'));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $this->definition('seats_key')->getKey(),
            'entity_type' => $contact->getMorphClass(),
            'entity_id' => $contact->getKey(),
            'value_integer' => 12,
        ]);
    }

    #[Test]
    public function a_required_definition_blocks_the_create_page_and_names_itself_in_the_error(): void
    {
        $field = CustomField::factory()->forEntity(CustomFieldEntity::Contact)->required()->create(['key' => 'floor_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'سارة',
                'last_name' => 'العتيبي',
                CustomFieldsSchema::STATE_PATH => ['floor_key' => ''],
            ])
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.floor_key' => 'required'])
            ->assertSee(__('custom_fields.validation.value_required', ['label' => $field->display_label]));

        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function the_edit_page_is_filled_with_the_stored_values_updates_them_and_clears_one(): void
    {
        $this->definitions();

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($contact, [
            CustomFieldsSchema::STATE_PATH => [
                'floor_key' => 'Third floor',
                'seats_key' => 12,
                'band_key' => 'one',
                'portal_key' => 'https://portal.example.com',
            ],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditContact::class, ['record' => $contact->getRouteKey()])
            ->assertFormSet([
                CustomFieldsSchema::STATE_PATH.'.floor_key' => 'Third floor',
                CustomFieldsSchema::STATE_PATH.'.seats_key' => 12,
                CustomFieldsSchema::STATE_PATH.'.band_key' => 'one',
            ])
            ->fillForm([
                CustomFieldsSchema::STATE_PATH => [
                    'floor_key' => 'Fourth floor',
                    'seats_key' => 30,
                    'band_key' => 'two',
                    'portal_key' => null,
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $contact->refresh();

        $this->assertSame('Fourth floor', $contact->customField('floor_key'));
        $this->assertSame(30, $contact->customField('seats_key'));
        $this->assertSame('two', $contact->customField('band_key'));
        $this->assertNull($contact->customField('portal_key'));

        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_id' => $this->definition('portal_key')->getKey(),
            'entity_id' => $contact->getKey(),
        ]);
    }

    #[Test]
    public function the_view_page_shows_the_section_with_the_values_a_reader_expects(): void
    {
        $this->definitions();

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);
        $band = $this->definition('band_key');

        CustomFieldsSchema::persist($contact, [
            CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor', 'band_key' => 'one'],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee(__('custom_fields.sections.custom'))
            ->assertSee($this->definition('floor_key')->display_label)
            ->assertSee('Third floor')
            ->assertSee($band->optionLabel('one'));
    }

    #[Test]
    public function a_listed_definition_becomes_a_column_the_reader_can_switch_on(): void
    {
        $this->definitions();

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($contact, [CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor']], $this->actor);

        $column = CustomFieldsSchema::NAME_PREFIX.'floor_key';

        Livewire::actingAs($this->actor)
            ->test(ListContacts::class)
            ->assertCanNotRenderTableColumn($column);

        $page = Livewire::actingAs($this->actor)->test(ListContacts::class);
        $instance = $page->instance();

        $this->assertInstanceOf(ListContacts::class, $instance);

        $page->call('applyTableColumnManager', $this->columnStateWith($instance->getDefaultTableColumnState(), $column))
            ->assertCanRenderTableColumn($column)
            ->assertSee('Third floor');
    }

    #[Test]
    public function a_filterable_definition_narrows_the_list_without_widening_the_actors_scope(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $mine = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $othersButMatching = Contact::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $mineButNotMatching = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        CustomFieldsSchema::persist($mine, [CustomFieldsSchema::STATE_PATH => ['seats_key' => 12]], $this->actor);
        CustomFieldsSchema::persist($othersButMatching, [CustomFieldsSchema::STATE_PATH => ['seats_key' => 12]], $this->actor);
        CustomFieldsSchema::persist($mineButNotMatching, [CustomFieldsSchema::STATE_PATH => ['seats_key' => 90]], $this->actor);

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->filterTable(CustomFieldsSchema::NAME_PREFIX.'seats_key', ['from' => 5, 'to' => 20])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$othersButMatching, $mineButNotMatching]);
    }

    #[Test]
    public function creating_a_contact_from_the_account_page_writes_its_values_too(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(ContactsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => EditAccount::class,
            ])
            ->callAction(TestAction::make('create')->table(), [
                'first_name' => 'سارة',
                'last_name' => 'العتيبي',
                CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor', 'band_key' => 'two'],
            ])
            ->assertHasNoActionErrors();

        $contact = Contact::query()->where('last_name', 'العتيبي')->firstOrFail();

        $this->assertSame($account->getKey(), $contact->account_id);
        $this->assertSame('Third floor', $contact->customField('floor_key'));
        $this->assertSame('two', $contact->customField('band_key'));
    }

    #[Test]
    public function editing_a_contact_from_the_account_page_fills_and_writes_its_values_too(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);
        $contact = Contact::factory()->create([
            'owner_id' => $this->actor->getKey(),
            'account_id' => $account->getKey(),
        ]);

        CustomFieldsSchema::persist($contact, [CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ContactsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => EditAccount::class,
            ])
            ->mountAction(TestAction::make('edit')->table($contact))
            ->assertActionDataSet([CustomFieldsSchema::STATE_PATH.'.floor_key' => 'Third floor'])
            ->setActionData([
                'job_title' => 'CTO',
                CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Jeddah floor'],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $contact->refresh();

        $this->assertSame('CTO', $contact->job_title);
        $this->assertSame('Jeddah floor', $contact->customField('floor_key'));
    }

    #[Test]
    public function a_deactivated_definition_is_on_no_page_at_all(): void
    {
        $retired = CustomField::factory()
            ->forEntity(CustomFieldEntity::Contact)
            ->inactive()
            ->listed()
            ->filterable()
            ->create(['key' => 'retired_key', 'sort' => 0]);

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(CreateContact::class)
            ->assertDontSee($retired->display_label);

        Livewire::actingAs($this->actor)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertDontSee(__('custom_fields.sections.custom'));

        $instance = Livewire::actingAs($this->actor)->test(ListContacts::class)->instance();

        $this->assertInstanceOf(ListContacts::class, $instance);
        $this->assertNull($instance->getTable()->getColumn(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
        $this->assertNull($instance->getTable()->getFilter(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
    }

    #[Test]
    public function a_required_definition_blocks_the_edit_page_too(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Contact)->required()->create(['key' => 'floor_key', 'sort' => 0]);

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($contact, [CustomFieldsSchema::STATE_PATH => ['floor_key' => 'kept']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditContact::class, ['record' => $contact->getRouteKey()])
            ->fillForm([CustomFieldsSchema::STATE_PATH => ['floor_key' => '']])
            ->call('save')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.floor_key' => 'required']);

        $this->assertSame('kept', $contact->fresh()?->customField('floor_key'));
    }

    #[Test]
    public function a_date_the_picker_cannot_read_is_refused_instead_of_crashing_the_request(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Contact)->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'Sara',
                'last_name' => 'Alotaibi',
            ])
            ->set('data.'.CustomFieldsSchema::STATE_PATH.'.renewal_key', 'not-a-date-at-all')
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.renewal_key']);

        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function the_inline_edit_action_of_the_list_table_shows_the_stored_values_and_writes_the_changed_ones(): void
    {
        $this->definitions();

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($contact, [CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ListContacts::class)
            ->mountAction(TestAction::make('edit')->table($contact))
            ->assertActionDataSet([CustomFieldsSchema::STATE_PATH.'.floor_key' => 'Third floor'])
            ->setActionData([CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Jeddah floor']])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Jeddah floor', $contact->fresh()?->customField('floor_key'));
    }

    #[Test]
    public function the_header_create_action_of_the_list_page_writes_the_values_too(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(ListContacts::class)
            ->callAction(TestAction::make('create'), [
                'first_name' => 'Noura',
                'last_name' => 'Alharbi',
                CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor'],
            ])
            ->assertHasNoActionErrors();

        $contact = Contact::query()->where('last_name', 'Alharbi')->firstOrFail();

        $this->assertSame('Third floor', $contact->customField('floor_key'));
    }

    #[Test]
    public function a_rep_cannot_write_a_value_onto_a_record_outside_their_reach(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $others = Contact::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->actingAs($rep);

        $this->expectException(AuthorizationException::class);

        try {
            CustomFieldActions::persist($others, ['floor_key' => 'Third floor']);
        } finally {
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    #[Test]
    public function a_stored_value_is_shown_in_the_language_the_reader_uses(): void
    {
        $this->definitions();

        $contact = Contact::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($contact, [CustomFieldsSchema::STATE_PATH => ['floor_key' => 'Third floor']], $this->actor);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $field = $this->definition('floor_key');

            Livewire::actingAs($this->actor)
                ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
                ->assertSee($field->display_label)
                ->assertSee('Third floor');
        }

        app()->setLocale((string) config('app.locale'));
    }

    /** A handful of definitions of different shapes on the contact entity. */
    private function definitions(): void
    {
        $factory = CustomField::factory()->forEntity(CustomFieldEntity::Contact);

        $factory->listed()->create(['key' => 'floor_key', 'sort' => 0]);
        $factory->ofType(CustomFieldType::Number)->filterable()->create(['key' => 'seats_key', 'sort' => 1]);
        $factory->select(['one', 'two'])->create(['key' => 'band_key', 'sort' => 2]);
        $factory->ofType(CustomFieldType::Url)->create(['key' => 'portal_key', 'sort' => 3]);
    }

    private function definition(string $key): CustomField
    {
        return CustomField::query()
            ->forEntity(CustomFieldEntity::Contact)
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
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
