<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Enums\LeadStatusKind;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\QueryBuilderFilters;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadStatus;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The advanced "query" filter (plan module row 17, decision A-8) narrows the
 * lists through Filament's rule builder and never reaches past the actor's
 * scope (D-4).
 */
final class QueryBuilderFiltersTest extends TestCase
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

    /**
     * One rule in the QueryBuilder filter's state shape: a keyed rule whose
     * block is the constraint name and whose data holds the operator and its
     * settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function rule(string $constraint, string $operator, array $settings): array
    {
        return [
            'rules' => [
                'rule' => [
                    'type' => $constraint,
                    'data' => ['operator' => $operator, 'settings' => $settings],
                ],
            ],
        ];
    }

    #[Test]
    public function a_text_constraint_narrows_the_lead_list(): void
    {
        $admin = $this->admin();
        $zeta = Lead::factory()->create(['owner_id' => $admin->getKey(), 'company_name' => 'Zeta Trading']);
        $alpha = Lead::factory()->create(['owner_id' => $admin->getKey(), 'company_name' => 'Alpha Foods']);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$zeta, $alpha])
            ->filterTable(QueryBuilderFilters::NAME, self::rule('company_name', 'contains', ['text' => 'Zeta']))
            ->assertCanSeeTableRecords([$zeta])
            ->assertCanNotSeeTableRecords([$alpha]);
    }

    #[Test]
    public function a_relationship_constraint_narrows_leads_by_status(): void
    {
        $admin = $this->admin();
        $unqualified = LeadStatus::query()->where('kind', LeadStatusKind::Unqualified->value)->firstOrFail();
        $open = Lead::factory()->create(['owner_id' => $admin->getKey()]);
        $closed = Lead::factory()->create(['owner_id' => $admin->getKey(), 'lead_status_id' => $unqualified->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$open, $closed])
            ->filterTable(QueryBuilderFilters::NAME, self::rule('status', 'isRelatedTo', ['value' => [$unqualified->getKey()]]))
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open]);
    }

    #[Test]
    public function a_constraint_matching_another_reps_lead_never_widens_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Zeta Trading']);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Zeta Trading']);
        $onlyTheirs = Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Omega Logistics']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->filterTable(QueryBuilderFilters::NAME, self::rule('company_name', 'contains', ['text' => 'Zeta']))
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $onlyTheirs])
            ->filterTable(QueryBuilderFilters::NAME, self::rule('company_name', 'contains', ['text' => 'Omega']))
            ->assertCanNotSeeTableRecords([$mine, $theirs, $onlyTheirs]);
    }

    #[Test]
    public function an_inverse_text_constraint_still_stays_inside_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Alpha Foods']);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Alpha Foods']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->filterTable(QueryBuilderFilters::NAME, self::rule('company_name', 'contains.inverse', ['text' => 'Zeta']))
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    #[Test]
    public function a_text_constraint_narrows_the_deal_list(): void
    {
        $admin = $this->admin();
        $zeta = Deal::factory()->create(['owner_id' => $admin->getKey(), 'title' => 'Zeta rollout']);
        $alpha = Deal::factory()->create(['owner_id' => $admin->getKey(), 'title' => 'Alpha renewal']);

        Livewire::actingAs($admin)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$zeta, $alpha])
            ->filterTable(QueryBuilderFilters::NAME, self::rule('title', 'contains', ['text' => 'Zeta']))
            ->assertCanSeeTableRecords([$zeta])
            ->assertCanNotSeeTableRecords([$alpha]);
    }

    #[Test]
    public function a_relationship_constraint_narrows_deals_by_account(): void
    {
        $admin = $this->admin();
        $account = Account::factory()->create(['owner_id' => $admin->getKey()]);
        $inAccount = Deal::factory()->create(['owner_id' => $admin->getKey(), 'account_id' => $account->getKey()]);
        $elsewhere = Deal::factory()->create(['owner_id' => $admin->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->filterTable(QueryBuilderFilters::NAME, self::rule('account', 'isRelatedTo', ['value' => [$account->getKey()]]))
            ->assertCanSeeTableRecords([$inAccount])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    #[Test]
    public function a_constraint_matching_another_reps_deal_never_widens_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Zeta rollout']);
        $theirs = Deal::factory()->create(['owner_id' => $other->getKey(), 'title' => 'Zeta expansion']);

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->filterTable(QueryBuilderFilters::NAME, self::rule('title', 'contains', ['text' => 'Zeta']))
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->filterTable(QueryBuilderFilters::NAME, self::rule('title', 'contains', ['text' => 'expansion']))
            ->assertCanNotSeeTableRecords([$mine, $theirs]);
    }

    #[Test]
    public function the_four_main_lists_offer_the_query_filter_in_a_modal_with_translated_constraints(): void
    {
        $admin = $this->admin();

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class] as $page) {
            $component = Livewire::actingAs($admin)->test($page)->assertTableFilterExists(QueryBuilderFilters::NAME);

            $instance = $component->instance();
            assert($instance instanceof $page);

            $table = $instance->getTable();
            $filter = $table->getFilter(QueryBuilderFilters::NAME);

            $this->assertInstanceOf(QueryBuilder::class, $filter, $page);
            $this->assertSame(FiltersLayout::Modal, $table->getFiltersLayout(), $page);

            foreach ($filter->getConstraints() as $constraint) {
                $this->assertSame(
                    __('query_builder.fields.'.$constraint->getName()),
                    $constraint->getLabel(),
                    $page.' constraint '.$constraint->getName().' is not labelled from lang/query_builder.php',
                );
            }
        }
    }
}
