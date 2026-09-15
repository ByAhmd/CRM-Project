<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFieldsWiring;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\Account;
use App\Models\CustomField;
use App\Models\Deal;
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
 * The custom field engine wired into the deal resource (decision D-9),
 * including the create form the account page reuses in its relation manager.
 */
final class DealCustomFieldsTest extends TestCase
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

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(CreateDeal::class)
            ->fillForm([
                'title' => 'صفقة الأجهزة',
                'account_id' => $account->getKey(),
                'amount' => 5,
                CustomFieldsSchema::STATE_PATH => [
                    'tender_key' => 'TND-2026-114',
                    'kickoff_key' => '2026-05-09 14:30',
                    'sponsor_key' => 'sponsor@example.com',
                    'services_key' => ['one', 'two'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deal = Deal::query()->where('title', 'صفقة الأجهزة')->firstOrFail();

        $this->assertSame('TND-2026-114', $deal->customField('tender_key'));
        $this->assertSame('2026-05-09 14:30', $this->moment($deal, 'kickoff_key'));
        $this->assertSame('sponsor@example.com', $deal->customField('sponsor_key'));
        $this->assertSame(['one', 'two'], $deal->customField('services_key'));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $this->definition('tender_key')->getKey(),
            'entity_type' => $deal->getMorphClass(),
            'entity_id' => $deal->getKey(),
            'value_string' => 'TND-2026-114',
        ]);
    }

    #[Test]
    public function a_required_definition_blocks_the_create_page_and_names_itself_in_the_error(): void
    {
        $field = CustomField::factory()->forEntity(CustomFieldEntity::Deal)->required()->create(['key' => 'tender_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateDeal::class)
            ->fillForm([
                'title' => 'صفقة الأجهزة',
                CustomFieldsSchema::STATE_PATH => ['tender_key' => ''],
            ])
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.tender_key' => 'required'])
            ->assertSee(__('custom_fields.validation.value_required', ['label' => $field->display_label]));

        $this->assertDatabaseCount('deals', 0);
    }

    #[Test]
    public function the_edit_page_is_filled_with_the_stored_values_updates_them_and_clears_one(): void
    {
        $this->definitions();

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($deal, [
            CustomFieldsSchema::STATE_PATH => [
                'tender_key' => 'TND-2026-114',
                'kickoff_key' => '2026-05-09 14:30',
                'sponsor_key' => 'sponsor@example.com',
                'services_key' => ['one'],
            ],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->assertFormSet([
                CustomFieldsSchema::STATE_PATH.'.tender_key' => 'TND-2026-114',
                CustomFieldsSchema::STATE_PATH.'.kickoff_key' => '2026-05-09 14:30',
                CustomFieldsSchema::STATE_PATH.'.sponsor_key' => 'sponsor@example.com',
                CustomFieldsSchema::STATE_PATH.'.services_key' => ['one'],
            ])
            ->fillForm([
                CustomFieldsSchema::STATE_PATH => [
                    'tender_key' => 'TND-2026-200',
                    'kickoff_key' => '2027-01-31 08:00',
                    'sponsor_key' => null,
                    'services_key' => ['two'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();

        $this->assertSame('TND-2026-200', $deal->customField('tender_key'));
        $this->assertSame('2027-01-31 08:00', $this->moment($deal, 'kickoff_key'));
        $this->assertNull($deal->customField('sponsor_key'));
        $this->assertSame(['two'], $deal->customField('services_key'));

        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_id' => $this->definition('sponsor_key')->getKey(),
            'entity_id' => $deal->getKey(),
        ]);
    }

    #[Test]
    public function the_view_page_shows_the_section_with_the_values_a_reader_expects(): void
    {
        $this->definitions();

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);
        $services = $this->definition('services_key');

        CustomFieldsSchema::persist($deal, [
            CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114', 'services_key' => ['one']],
        ], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertSee(__('custom_fields.sections.custom'))
            ->assertSee($this->definition('tender_key')->display_label)
            ->assertSee('TND-2026-114')
            ->assertSee($services->optionLabel('one'));
    }

    #[Test]
    public function a_listed_definition_becomes_a_column_the_reader_can_switch_on(): void
    {
        $this->definitions();

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($deal, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114']], $this->actor);

        $column = CustomFieldsSchema::NAME_PREFIX.'tender_key';

        Livewire::actingAs($this->actor)
            ->test(ListDeals::class)
            ->assertCanNotRenderTableColumn($column);

        $page = Livewire::actingAs($this->actor)->test(ListDeals::class);
        $instance = $page->instance();

        $this->assertInstanceOf(ListDeals::class, $instance);

        $page->call('applyTableColumnManager', $this->columnStateWith($instance->getDefaultTableColumnState(), $column))
            ->assertCanRenderTableColumn($column)
            ->assertSee('TND-2026-114');
    }

    #[Test]
    public function a_filterable_definition_narrows_the_list_without_widening_the_actors_scope(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $othersButMatching = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $mineButNotMatching = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        CustomFieldsSchema::persist($mine, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114']], $this->actor);
        CustomFieldsSchema::persist($othersButMatching, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114']], $this->actor);
        CustomFieldsSchema::persist($mineButNotMatching, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-900']], $this->actor);

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->filterTable(CustomFieldsSchema::NAME_PREFIX.'tender_key', ['value' => '2026-114'])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$othersButMatching, $mineButNotMatching]);
    }

    #[Test]
    public function creating_a_deal_from_the_account_page_writes_its_values_too(): void
    {
        $this->definitions();

        $account = Account::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(DealsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => EditAccount::class,
            ])
            ->callAction(TestAction::make('create')->table(), [
                'title' => 'صفقة الأجهزة',
                'amount' => 5,
                CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114', 'services_key' => ['two']],
            ])
            ->assertHasNoActionErrors();

        $deal = Deal::query()->where('title', 'صفقة الأجهزة')->firstOrFail();

        $this->assertSame($account->getKey(), $deal->account_id);
        $this->assertSame('TND-2026-114', $deal->customField('tender_key'));
        $this->assertSame(['two'], $deal->customField('services_key'));
    }

    #[Test]
    public function a_deactivated_definition_is_on_no_page_at_all(): void
    {
        $retired = CustomField::factory()
            ->forEntity(CustomFieldEntity::Deal)
            ->inactive()
            ->listed()
            ->filterable()
            ->create(['key' => 'retired_key', 'sort' => 0]);

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        Livewire::actingAs($this->actor)
            ->test(CreateDeal::class)
            ->assertDontSee($retired->display_label);

        Livewire::actingAs($this->actor)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertDontSee(__('custom_fields.sections.custom'));

        $instance = Livewire::actingAs($this->actor)->test(ListDeals::class)->instance();

        $this->assertInstanceOf(ListDeals::class, $instance);
        $this->assertNull($instance->getTable()->getColumn(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
        $this->assertNull($instance->getTable()->getFilter(CustomFieldsSchema::NAME_PREFIX.'retired_key'));
    }

    #[Test]
    public function a_required_definition_blocks_the_edit_page_too(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Deal)->required()->create(['key' => 'tender_key', 'sort' => 0]);

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($deal, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'kept']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm([CustomFieldsSchema::STATE_PATH => ['tender_key' => '']])
            ->call('save')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.tender_key' => 'required']);

        $this->assertSame('kept', $deal->fresh()?->customField('tender_key'));
    }

    #[Test]
    public function a_date_the_picker_cannot_read_is_refused_instead_of_crashing_the_request(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Deal)->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 0]);

        Livewire::actingAs($this->actor)
            ->test(CreateDeal::class)
            ->fillForm([
                'title' => 'Hardware deal',
            ])
            ->set('data.'.CustomFieldsSchema::STATE_PATH.'.renewal_key', 'not-a-date-at-all')
            ->call('create')
            ->assertHasFormErrors([CustomFieldsSchema::STATE_PATH.'.renewal_key']);

        $this->assertDatabaseCount('deals', 0);
    }

    #[Test]
    public function the_inline_edit_action_of_the_list_table_shows_the_stored_values_and_writes_the_changed_ones(): void
    {
        $this->definitions();

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($deal, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114']], $this->actor);

        Livewire::actingAs($this->actor)
            ->test(ListDeals::class)
            ->mountAction(TestAction::make('edit')->table($deal))
            ->assertActionDataSet([CustomFieldsSchema::STATE_PATH.'.tender_key' => 'TND-2026-114'])
            ->setActionData([CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-999']])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('TND-2026-999', $deal->fresh()?->customField('tender_key'));
    }

    #[Test]
    public function the_header_create_action_of_the_list_page_writes_the_values_too(): void
    {
        $this->definitions();

        Livewire::actingAs($this->actor)
            ->test(ListDeals::class)
            ->callAction(TestAction::make('create'), [
                'title' => 'Software deal',
                CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114'],
            ])
            ->assertHasNoActionErrors();

        $deal = Deal::query()->where('title', 'Software deal')->firstOrFail();

        $this->assertSame('TND-2026-114', $deal->customField('tender_key'));
    }

    #[Test]
    public function a_rep_cannot_write_a_value_onto_a_record_outside_their_reach(): void
    {
        $this->definitions();

        $rep = $this->salesRep();
        $others = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->actingAs($rep);

        $this->expectException(AuthorizationException::class);

        try {
            CustomFieldActions::persist($others, ['tender_key' => 'TND-2026-114']);
        } finally {
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    #[Test]
    public function a_stored_value_is_shown_in_the_language_the_reader_uses(): void
    {
        $this->definitions();

        $deal = Deal::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($deal, [CustomFieldsSchema::STATE_PATH => ['tender_key' => 'TND-2026-114']], $this->actor);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $field = $this->definition('tender_key');

            Livewire::actingAs($this->actor)
                ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
                ->assertSee($field->display_label)
                ->assertSee('TND-2026-114');
        }

        app()->setLocale((string) config('app.locale'));
    }

    /** A handful of definitions of different shapes on the deal entity. */
    private function definitions(): void
    {
        $factory = CustomField::factory()->forEntity(CustomFieldEntity::Deal);

        $factory->listed()->filterable()->create(['key' => 'tender_key', 'sort' => 0]);
        $factory->ofType(CustomFieldType::DateTime)->create(['key' => 'kickoff_key', 'sort' => 1]);
        $factory->ofType(CustomFieldType::Email)->create(['key' => 'sponsor_key', 'sort' => 2]);
        $factory->multiSelect(['one', 'two'])->create(['key' => 'services_key', 'sort' => 3]);
    }

    /** A stored moment as text, whatever shape the cast hands back. */
    private function moment(Deal $record, string $key): ?string
    {
        $value = $record->customField($key);

        return $value instanceof DateTimeInterface ? Carbon::instance($value)->format('Y-m-d H:i') : null;
    }

    private function definition(string $key): CustomField
    {
        return CustomField::query()
            ->forEntity(CustomFieldEntity::Deal)
            ->where('key', $key)
            ->firstOrFail();
    }
}
