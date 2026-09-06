<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Enums\LeadStatusKind;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\QueryBuilderFilters;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\SavedView;
use App\Services\Views\SavedViewService;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Saved views on the list pages (decision A-8): saving the live table state,
 * applying it back, the default view on first open, sharing visibility and
 * the manage modal.
 */
final class SavedViewsPageTest extends TestCase
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

    private function unqualifiedStatus(): LeadStatus
    {
        return LeadStatus::query()->where('kind', LeadStatusKind::Unqualified->value)->firstOrFail();
    }

    #[Test]
    public function a_rep_saves_the_current_filters_search_and_sort_as_a_view(): void
    {
        $rep = $this->salesRep();
        $status = $this->unqualifiedStatus();

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->filterTable('lead_status_id', [$status->getKey()])
            ->searchTable('Zeta')
            ->sortTable('created_at', 'asc')
            ->callAction('saveView', data: ['name' => 'Hot leads', 'is_default' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__('saved_views.notifications.saved', ['name' => 'Hot leads']));

        $view = SavedView::query()->sole();

        $this->assertSame($rep->getKey(), $view->user_id);
        $this->assertSame('leads', $view->resource);
        $this->assertSame('Hot leads', $view->name);
        $this->assertEquals([$status->getKey()], $view->filters['lead_status_id']['values'] ?? null);
        $this->assertSame('Zeta', $view->search);
        $this->assertSame('created_at', $view->sort_column);
        $this->assertSame('asc', $view->sort_direction);
        $this->assertTrue($view->is_default);
        $this->assertFalse($view->is_shared);
        $this->assertIsArray($view->columns);
        $this->assertArrayHasKey('company_name', $view->columns);
    }

    #[Test]
    public function applying_a_view_on_a_fresh_page_restores_filters_search_sort_and_columns(): void
    {
        $rep = $this->salesRep();
        $status = $this->unqualifiedStatus();
        $match = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Zeta Trading', 'lead_status_id' => $status->getKey()]);
        $wrongStatus = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Zeta Foods']);
        $wrongName = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Alpha', 'lead_status_id' => $status->getKey()]);

        $view = SavedView::factory()->create([
            'user_id' => $rep->getKey(),
            'name' => 'Hot leads',
            'filters' => ['lead_status_id' => ['values' => [$status->getKey()]]],
            'sort_column' => 'created_at',
            'sort_direction' => 'desc',
            'search' => 'Zeta',
            'columns' => ['phone' => true, 'company_name' => false],
        ]);

        $component = Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertSet('tableSearch', '');

        $fresh = $component->instance();
        assert($fresh instanceof ListLeads);

        $this->assertTrue($fresh->isTableColumnToggledHidden('phone'));
        $this->assertFalse($fresh->isTableColumnToggledHidden('company_name'));

        $component
            ->assertActionExists('view_'.$view->getKey())
            ->callAction('view_'.$view->getKey())
            ->assertNotified(__('saved_views.notifications.applied', ['name' => 'Hot leads']))
            ->assertSet('tableSearch', 'Zeta')
            ->assertSet('tableSort', 'created_at:desc')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$wrongStatus, $wrongName]);

        $page = $component->instance();
        assert($page instanceof ListLeads);

        $this->assertEquals([$status->getKey()], $page->tableFilters['lead_status_id']['values'] ?? null);
        $this->assertSame('created_at', $page->getTableSortColumn());
        $this->assertSame('desc', $page->getTableSortDirection());
        $this->assertFalse($page->isTableColumnToggledHidden('phone'));
        $this->assertTrue($page->isTableColumnToggledHidden('company_name'));
    }

    #[Test]
    public function the_default_view_is_applied_on_mount_only_when_no_table_state_is_persisted_yet(): void
    {
        $rep = $this->salesRep();
        SavedView::factory()->asDefault()->create(['user_id' => $rep->getKey(), 'name' => 'Opening', 'search' => 'Zeta']);

        session()->flush();

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertSet('tableSearch', 'Zeta');

        session()->flush();
        session()->put((new ListLeads)->getTableSearchSessionKey(), 'Omega');

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertSet('tableSearch', 'Omega');

        session()->flush();

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->assertSet('tableSearch', '');
    }

    #[Test]
    public function a_shared_default_from_a_manager_never_opens_the_reps_list(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        SavedView::factory()->shared()->asDefault()->create(['user_id' => $manager->getKey(), 'name' => 'Team', 'search' => 'Team search']);

        session()->flush();

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertSet('tableSearch', '')
            ->assertSet('tableSort', null);

        session()->flush();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->assertSet('tableSearch', 'Team search');
    }

    #[Test]
    public function a_default_view_with_state_the_table_no_longer_knows_applies_without_error_and_stays_scoped(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Zeta Trading']);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Zeta Trading']);
        $alpha = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Alpha Foods']);

        SavedView::factory()->asDefault()->create([
            'user_id' => $rep->getKey(),
            'name' => 'Stale',
            'filters' => [
                'gone_filter' => ['values' => [1]],
                QueryBuilderFilters::NAME => [
                    'rules' => [
                        'gone' => ['type' => 'gone_column', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'x']]],
                        'noop' => ['type' => 'company_name', 'data' => ['operator' => 'gone_operator', 'settings' => ['text' => 'x']]],
                        'kept' => ['type' => 'company_name', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'Zeta']]],
                    ],
                ],
            ],
            'sort_column' => 'gone_column',
            'sort_direction' => 'desc',
            'search' => 'Zeta',
            'columns' => ['gone_column' => false, 'phone' => true],
        ]);

        session()->flush();

        $component = Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertSet('tableSearch', 'Zeta')
            ->assertSet('tableSort', null)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $alpha]);

        $component->assertOk();

        $page = $component->instance();
        assert($page instanceof ListLeads);

        $this->assertArrayNotHasKey('gone_filter', $page->tableFilters ?? []);
        $rules = array_values($page->tableFilters[QueryBuilderFilters::NAME]['rules'] ?? []);

        $this->assertCount(1, $rules);
        $this->assertSame('company_name', $rules[0]['type'] ?? null);
        $this->assertSame('Zeta', $rules[0]['data']['settings']['text'] ?? null);
        $this->assertFalse($page->isTableColumnToggledHidden('phone'));
    }

    #[Test]
    public function a_shared_view_from_a_manager_appears_for_the_rep_and_a_private_one_does_not(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $shared = SavedView::factory()->shared()->create(['user_id' => $manager->getKey(), 'name' => 'Team', 'search' => 'Team search']);
        $private = SavedView::factory()->create(['user_id' => $manager->getKey(), 'name' => 'Mine only']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertActionExists('view_'.$shared->getKey())
            ->assertActionDoesNotExist('view_'.$private->getKey())
            ->callAction('view_'.$shared->getKey())
            ->assertSet('tableSearch', 'Team search');

        $page = Livewire::actingAs($rep)->test(ListLeads::class)->instance();
        assert($page instanceof ListLeads);

        $this->assertStringContainsString(__('saved_views.labels.shared_suffix'), (string) $page->getAction('view_'.$shared->getKey())?->getLabel());
    }

    #[Test]
    public function a_rep_sets_a_default_and_deletes_their_own_views_from_the_manage_modal(): void
    {
        $rep = $this->salesRep();
        $first = SavedView::factory()->create(['user_id' => $rep->getKey(), 'name' => 'First']);
        $second = SavedView::factory()->create(['user_id' => $rep->getKey(), 'name' => 'Second']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->callAction('manage', data: ['saved_view_id' => $first->getKey()], arguments: ['operation' => 'default'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('saved_views.notifications.default_set', ['name' => 'First']))
            ->callAction('manage', data: ['saved_view_id' => $second->getKey()], arguments: ['operation' => 'delete'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('saved_views.notifications.deleted', ['name' => 'Second']));

        $this->assertTrue($first->refresh()->is_default);
        $this->assertDatabaseMissing('saved_views', ['id' => $second->getKey()]);
    }

    #[Test]
    public function the_manage_modal_never_touches_the_private_views_of_others(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $theirs = SavedView::factory()->create(['user_id' => $manager->getKey(), 'name' => 'Private']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertActionHidden('manage');

        SavedView::factory()->create(['user_id' => $rep->getKey(), 'name' => 'Mine']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->callAction('manage', data: ['saved_view_id' => $theirs->getKey()], arguments: ['operation' => 'delete'])
            ->assertHasActionErrors(['saved_view_id']);

        $this->assertDatabaseHas('saved_views', ['id' => $theirs->getKey()]);
    }

    #[Test]
    public function a_user_manager_deletes_a_shared_view_of_someone_else_from_the_manage_modal(): void
    {
        $manager = $this->salesManager();
        $admin = $this->admin();
        $shared = SavedView::factory()->shared()->create(['user_id' => $manager->getKey(), 'name' => 'Team']);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->callAction('manage', data: ['saved_view_id' => $shared->getKey()], arguments: ['operation' => 'delete'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('saved_views', ['id' => $shared->getKey()]);
    }

    #[Test]
    public function a_duplicate_name_is_rejected_on_the_save_form(): void
    {
        $rep = $this->salesRep();
        SavedView::factory()->create(['user_id' => $rep->getKey(), 'name' => 'Dup']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->callAction('saveView', data: ['name' => 'Dup'])
            ->assertHasActionErrors(['name']);

        $this->assertDatabaseCount('saved_views', 1);
    }

    #[Test]
    public function an_oversized_search_is_rejected_on_the_save_form_with_a_translated_message(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->searchTable(str_repeat('a', SavedViewService::SEARCH_MAX_LENGTH + 1))
            ->callAction('saveView', data: ['name' => 'Too long'])
            ->assertHasActionErrors([
                'name' => fn (array $rules, array $messages): bool => in_array(
                    __('saved_views.validation.search_too_long', ['max' => SavedViewService::SEARCH_MAX_LENGTH]),
                    $messages,
                    true,
                ),
            ]);

        $this->assertDatabaseCount('saved_views', 0);
    }

    #[Test]
    public function a_user_manager_cannot_set_a_foreign_shared_view_as_default(): void
    {
        $manager = $this->salesManager();
        $admin = $this->admin();
        $shared = SavedView::factory()->shared()->create(['user_id' => $manager->getKey(), 'name' => 'Team']);
        $own = SavedView::factory()->create(['user_id' => $admin->getKey(), 'name' => 'Mine']);

        $component = Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->mountAction('manage')
            ->setActionData(['saved_view_id' => $shared->getKey()]);

        $this->assertFalse($this->setDefaultFooterAction($component->instance())->isVisible());

        $component->setActionData(['saved_view_id' => $own->getKey()]);

        $this->assertTrue($this->setDefaultFooterAction($component->instance())->isVisible());

        $component
            ->unmountAction()
            ->callAction('manage', data: ['saved_view_id' => $shared->getKey()], arguments: ['operation' => 'default'])
            ->assertNotified(__('saved_views.validation.update_forbidden'));

        $this->assertFalse($shared->refresh()->is_default);
    }

    private function setDefaultFooterAction(object $page): Action
    {
        assert($page instanceof ListLeads);

        $action = $page->getMountedAction()?->getExtraModalFooterActions()['setDefault'] ?? null;
        assert($action instanceof Action);

        return $action;
    }

    #[Test]
    public function a_rep_cannot_share_a_view_from_the_form_while_a_manager_can(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->callAction('saveView', data: ['name' => 'Mine', 'is_shared' => true])
            ->assertHasNoActionErrors();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('saveView', data: ['name' => 'Team', 'is_shared' => true])
            ->assertHasNoActionErrors();

        $this->assertFalse(SavedView::query()->where('name', 'Mine')->sole()->is_shared);
        $this->assertTrue(SavedView::query()->where('name', 'Team')->sole()->is_shared);
    }

    #[Test]
    public function clearing_the_view_returns_the_table_to_its_defaults(): void
    {
        $rep = $this->salesRep();
        $status = $this->unqualifiedStatus();
        $view = SavedView::factory()->create([
            'user_id' => $rep->getKey(),
            'filters' => ['lead_status_id' => ['values' => [$status->getKey()]]],
            'sort_column' => 'created_at',
            'sort_direction' => 'asc',
            'search' => 'Zeta',
            'columns' => ['phone' => true],
        ]);

        $component = Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->callAction('view_'.$view->getKey())
            ->assertSet('tableSearch', 'Zeta');

        $applied = $component->instance();
        assert($applied instanceof ListLeads);

        $this->assertFalse($applied->isTableColumnToggledHidden('phone'));

        $component
            ->callAction('clearView')
            ->assertSet('tableSearch', '')
            ->assertSet('tableSort', null);

        $page = $component->instance();
        assert($page instanceof ListLeads);

        $this->assertEmpty($page->tableFilters['lead_status_id']['values'] ?? []);
        $this->assertTrue($page->isTableColumnToggledHidden('phone'));
    }

    #[Test]
    public function every_list_page_with_saved_views_keys_them_by_the_resource_slug(): void
    {
        $this->assertSame('leads', ListLeads::savedViewResourceKey());
        $this->assertSame('deals', ListDeals::savedViewResourceKey());
    }
}
