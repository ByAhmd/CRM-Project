<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFieldsWiring;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\Account;
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
 * The custom field engine wired into the account resource (decision D-9).
 */
final class AccountCustomFieldsTest extends TestCase
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
            ->test(CreateAccount::class)
            ->fillForm([
                'name' => 'شركة الأفق للتجارة',
                CustomFieldsSchema::STATE_PATH => [
                    'brief_key' => "A long standing distributor.\nTwo lines of it.",
                    'quota_key' => 1250.75,
                    'audited_key' => true,
                    'regions_key' => ['one', 'two'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = Account::query()->where('name', 'شركة الأفق للتجارة')->firstOrFail();

        $this->assertSame("A long standing distributor.\nTwo lines of it.", $account->customField('brief_key'));
        $this->assertSame(1250.75, $this->decimal($account, 'quota_key'));
        $this->assertTrue($account->customField('audited_key'));
        $this->assertSame(['one', 'two'], $account->customField('regions_key'));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $this->definition('quota_key')->getKey(),
            'entity_type' => $account->getMorphClass(),
            'entity_id' => $account->getKey(),
            'value_decimal' => '1250.7500',
        ]);
    }

    #[Test]
    public function a_required_definition_blocks_the_create_page_and_names_itself_in_the_error(): void
    {
        $field = CustomField::factory()->forEntity(CustomFieldEntity::Account)->required()->create(['key' => 'brief_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateAccount::class)
            ->fillForm([
                'name' => 'شركة الأفق للتجارة',
                CustomFieldsSchema::STATE_PATH => ['brief_key' => ''],
            ])
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.brief_key' => 'required'])
            ->assertSee(__('custom_fields.validation.value_required', ['label' => $field->display_label]));

        $this->assertDatabaseCount('accounts', 0);
    }

    #[Test]
    public function the_edit_page_is_filled_with_the_stored_values_updates_them_and_clears_one(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($account, [
            CustomFieldsSchema::STATE_PATH => [
                'brief_key' => 'A distributor.',
                'quota_key' => 1250.75,
                'audited_key' => true,
                'regions_key' => ['one', 'two'],
            ],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->assertFormSet([
                CustomFieldsSchema::STATE_PATH.'.brief_key' => 'A distributor.',
                CustomFieldsSchema::STATE_PATH.'.quota_key' => 1250.75,
                CustomFieldsSchema::STATE_PATH.'.audited_key' => true,
                CustomFieldsSchema::STATE_PATH.'.regions_key' => ['one', 'two'],
            ])
            ->fillForm([
                CustomFieldsSchema::STATE_PATH => [
                    'brief_key' => 'A wholesaler.',
                    'quota_key' => 90.5,
                    'audited_key' => false,
                    'regions_key' => [],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();

        $this->assertSame('A wholesaler.', $account->customField('brief_key'));
        $this->assertSame(90.5, $this->decimal($account, 'quota_key'));
        $this->assertFalse($account->customField('audited_key'));
        $this->assertNull($account->customField('regions_key'));

        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_id' => $this->definition('regions_key')->getKey(),
            'entity_id' => $account->getKey(),
        ]);
    }

    #[Test]
    public function the_view_page_shows_the_section_with_the_values_a_reader_expects(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);
        $regions = $this->definition('regions_key');

        CustomFieldsSchema::persist($account, [
            CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A distributor.', 'audited_key' => false, 'regions_key' => ['one']],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ViewAccount::class, ['record' => $account->getRouteKey()])
            ->assertSee(__('custom_fields.sections.custom'))
            ->assertSee($this->definition('brief_key')->display_label)
            ->assertSee('A distributor.')
            ->assertSee($regions->optionLabel('one'))
            ->assertSee(__('custom_fields.values.no'));
    }

    #[Test]
    public function a_listed_definition_becomes_a_column_the_reader_can_switch_on(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($account, [CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A distributor.']], $this->actor);

        $column = CustomFieldsSchema::NAME_PREFIX.'brief_key';

        Livewire::actingAs($this->actor)
            ->test(ListAccounts::class)
            ->assertCanNotRenderTableColumn($column);

        $page = Livewire::actingAs($this->actor)->test(ListAccounts::class);
        $instance = $page->instance();

        $this->assertInstanceOf(ListAccounts::class, $instance);

        $page->call('applyTableColumnManager', $this->columnStateWith($instance->getDefaultTableColumnState(), $column))
            ->assertCanRenderTableColumn($column)
            ->assertSee('A distributor.');
    }

    #[Test]
    public function a_filterable_definition_narrows_the_list_without_widening_the_actors_scope(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $mine = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $othersButMatching = Account::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $mineButNotMatching = Account::factory()->create(['owner_id' => $rep->getKey()]);

        CustomFieldsSchema::persist($mine, [CustomFieldsSchema::STATE_PATH => ['audited_key' => true]], $this->actor);
        CustomFieldsSchema::persist($othersButMatching, [CustomFieldsSchema::STATE_PATH => ['audited_key' => true]], $this->actor);
        CustomFieldsSchema::persist($mineButNotMatching, [CustomFieldsSchema::STATE_PATH => ['audited_key' => false]], $this->actor);

        Livewire::actingAs($rep)
            ->test(ListAccounts::class)
            ->filterTable(CustomFieldsSchema::NAME_PREFIX.'audited_key', '1')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$othersButMatching, $mineButNotMatching]);
    }

    #[Test]
    public function a_deactivated_definition_is_on_no_page_at_all(): void
    {
        $retired = CustomField::factory()
            ->forEntity(CustomFieldEntity::Account)
            ->inactive()
            ->listed()
            ->filterable()
            ->create(['key' => 'retired_key', 'sort' => 0]);

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(CreateAccount::class)
            ->assertDontSee($retired->display_label);

        Livewire::actingAs($this->actor)
            ->test(ViewAccount::class, ['record' => $account->getRouteKey()])
            ->assertDontSee(__('custom_fields.sections.custom'));

        $instance = Livewire::actingAs($this->actor)->test(ListAccounts::class)->instance();

        $this->assertInstanceOf(ListAccounts::class, $instance);
        $this->assertNull($instance->getTable()->getColumn(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
        $this->assertNull($instance->getTable()->getFilter(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
    }

    #[Test]
    public function a_required_definition_blocks_the_edit_page_too(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Account)->required()->create(['key' => 'brief_key', 'sort' => 0]);

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($account, [CustomFieldsSchema::STATE_PATH => ['brief_key' => 'kept']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm([CustomFieldsSchema::STATE_PATH => ['brief_key' => '']])
            ->call('save')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.brief_key' => 'required']);

        $this->assertSame('kept', $account->fresh()?->customField('brief_key'));
    }

    #[Test]
    public function a_date_the_picker_cannot_read_is_refused_instead_of_crashing_the_request(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Account)->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateAccount::class)
            ->fillForm([
                'name' => 'Horizon Trading',
            ])
            ->set('data.'.CustomFieldsSchema::STATE_PATH.'.renewal_key', 'not-a-date-at-all')
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.renewal_key']);

        $this->assertDatabaseCount('accounts', 0);
    }

    #[Test]
    public function the_inline_edit_action_of_the_list_table_shows_the_stored_values_and_writes_the_changed_ones(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($account, [CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A long standing distributor.']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ListAccounts::class)
            ->mountAction(TestAction::make('edit')->table($account))
            ->assertActionDataSet([CustomFieldsSchema::STATE_PATH.'.brief_key' => 'A long standing distributor.'])
            ->setActionData([CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A newer distributor.']])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('A newer distributor.', $account->fresh()?->customField('brief_key'));
    }

    #[Test]
    public function the_header_create_action_of_the_list_page_writes_the_values_too(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(ListAccounts::class)
            ->callAction(TestAction::make('create'), [
                'name' => 'Elite Trading',
                CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A long standing distributor.'],
            ])
            ->assertHasNoActionErrors();

        $account = Account::query()->where('name', 'Elite Trading')->firstOrFail();

        $this->assertSame('A long standing distributor.', $account->customField('brief_key'));
    }

    #[Test]
    public function a_rep_cannot_write_a_value_onto_a_record_outside_their_reach(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $others = Account::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->actingAs($rep);

        $this->expectException(AuthorizationException::class);

        try {
            CustomFieldActions::persist($others, ['brief_key' => 'A long standing distributor.']);
        } finally {
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    #[Test]
    public function a_stored_value_is_shown_in_the_language_the_reader_uses(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($account, [CustomFieldsSchema::STATE_PATH => ['brief_key' => 'A long standing distributor.']], $this->actor);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $field = $this->definition('brief_key');

            Livewire::actingAs($this->actor)
                ->test(ViewAccount::class, ['record' => $account->getRouteKey()])
                ->assertSee($field->display_label)
                ->assertSee('A long standing distributor.');
        }

        app()->setLocale((string) config('app.locale'));
    }

    /** A handful of definitions of different shapes on the account entity. */
    private function definitions(): void
    {
        $factory = CustomField::factory()->forEntity(CustomFieldEntity::Account);

        $factory->ofType(CustomFieldType::Textarea)->listed()->create(['key' => 'brief_key', 'sort' => 0]);
        $factory->ofType(CustomFieldType::Decimal)->create(['key' => 'quota_key', 'sort' => 1]);
        $factory->ofType(CustomFieldType::Boolean)->filterable()->create(['key' => 'audited_key', 'sort' => 2]);
        $factory->multiSelect(['one', 'two'])->create(['key' => 'regions_key', 'sort' => 3]);
    }

    /** A stored decimal as a number, whatever precision the column keeps. */
    private function decimal(Account $record, string $key): ?float
    {
        $value = $record->customField($key);

        return is_numeric($value) ? (float) $value : null;
    }

    private function definition(string $key): CustomField
    {
        return CustomField::query()
            ->forEntity(CustomFieldEntity::Account)
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
