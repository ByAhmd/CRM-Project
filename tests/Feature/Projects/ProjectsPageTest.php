<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\CloseReasonKind;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Filament\Pages\DealBoard;
use App\Filament\Pages\Projects;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Projects\CurrentProject;
use App\Support\Filament\WorkspacePanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The Sales «المشاريع» page (decision D-30): a card per active project with
 * the viewer's own counts and the open-deals value, the current project
 * marked, «دخول» that makes a project current and opens the sales
 * dashboard, and a manage link only for a reader who may enter
 * Administration.
 */
final class ProjectsPageTest extends TestCase
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
    public function the_page_and_the_resource_are_registered_in_their_workspaces(): void
    {
        $this->assertSame(WorkspacePanel::Sales->id(), WorkspacePanelRegistry::owningPanelId(Projects::class));
        $this->assertSame(WorkspacePanel::Admin->id(), WorkspacePanelRegistry::owningPanelId(ProjectResource::class));
        $this->assertSame(url('/sales/projects'), Projects::getUrl());
        $this->assertSame(url('/admin/projects'), ProjectResource::getUrl());

        // In the Sales group, before the leads: the project is chosen first.
        $this->assertSame(NavigationGroup::Sales, Projects::getNavigationGroup());
        $this->assertSame(NavigationGroup::Sales, LeadResource::getNavigationGroup());
        $this->assertLessThan(LeadResource::getNavigationSort(), Projects::getNavigationSort());
    }

    #[Test]
    public function every_sales_user_opens_the_page_and_an_employee_is_kept_out(): void
    {
        foreach ([$this->salesRep(), $this->salesManager(), $this->readOnly(), $this->superAdmin()] as $viewer) {
            $this->actingAs($viewer)->get(Projects::getUrl())->assertOk()->assertSee(__('projects.pages.projects.title'));
        }

        $this->actingAs($this->employee())->get(Projects::getUrl())->assertForbidden();
    }

    #[Test]
    public function it_shows_a_card_per_active_project_with_the_current_one_marked(): void
    {
        $rep = $this->salesRep();
        $main = $this->defaultProject();
        $iqan = $this->makeProject();
        $old = $this->makeProject('Old', 'قديم', ['is_active' => false]);

        $html = (string) $this->actingAs($rep)->get(Projects::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-project="'.$main->getKey().'"', $html);
        $this->assertStringContainsString('data-project="'.$iqan->getKey().'"', $html);
        $this->assertStringNotContainsString('data-project="'.$old->getKey().'"', $html, 'an inactive project has a card');
        $this->assertMatchesRegularExpression('/<article[^>]*data-project="'.$main->getKey().'"[^>]*aria-current="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<article[^>]*data-project="'.$iqan->getKey().'"[^>]*aria-current="true"/', $html);
        $this->assertSame(1, substr_count($html, __('projects.pages.projects.current')));
        $this->assertStringContainsString(__('projects.pages.projects.counts_scope'), $html);
        $this->assertStringContainsString(__('projects.pages.projects.subheading'), $html);
        $this->assertStringContainsString('إيقان', $html);

        // The main project card comes first: switcher order is card order.
        $this->assertLessThan(strpos($html, 'data-project="'.$iqan->getKey().'"'), strpos($html, 'data-project="'.$main->getKey().'"'));

        $this->useProject($rep, $iqan);

        $html = (string) $this->actingAs($rep)->get(Projects::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<article[^>]*data-project="'.$iqan->getKey().'"[^>]*aria-current="true"/', $html);
    }

    #[Test]
    public function the_counts_are_within_the_viewers_visibility_and_the_open_deals_are_valued(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep();
        $superAdmin = $this->superAdmin();
        $main = $this->defaultProject();
        $iqan = $this->makeProject();

        Lead::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey()]);
        Lead::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey()]);
        Lead::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $iqan->getKey()]);
        Deal::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey(), 'amount' => 1000]);
        Deal::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey(), 'amount' => 2500]);
        $won = Deal::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey(), 'amount' => 999]);
        app(DealCloseService::class)->win($won, $this->reasonOfKind(CloseReasonKind::Won), $superAdmin);

        // Everyone's records: both leads, three deals, 3500 open.
        $all = $this->cardsFor($superAdmin);

        $this->assertSame(2, $all[$main->getKey()]['counts']['leads_count']);
        $this->assertSame(3, $all[$main->getKey()]['counts']['deals_count']);
        $this->assertSame(2, $all[$main->getKey()]['counts']['open_deals_count']);
        $this->assertSame(DealBoard::money(3500.0), $all[$main->getKey()]['open_deals_amount']);
        $this->assertSame(1, $all[$iqan->getKey()]['counts']['leads_count']);
        $this->assertSame(DealBoard::money(0.0), $all[$iqan->getKey()]['open_deals_amount']);

        // The team's records: the teammate's lead and deal, not the outsider's.
        $teamScope = $this->cardsFor($manager);

        $this->assertSame(1, $teamScope[$main->getKey()]['counts']['leads_count']);
        $this->assertSame(1, $teamScope[$main->getKey()]['counts']['deals_count']);
        $this->assertSame(DealBoard::money(1000.0), $teamScope[$main->getKey()]['open_deals_amount']);
        $this->assertSame(0, $teamScope[$iqan->getKey()]['counts']['leads_count']);

        // Own records only.
        $own = $this->cardsFor($outsider);

        $this->assertSame(1, $own[$main->getKey()]['counts']['leads_count']);
        $this->assertSame(2, $own[$main->getKey()]['counts']['deals_count']);
        $this->assertSame(1, $own[$main->getKey()]['counts']['open_deals_count']);
        $this->assertSame(DealBoard::money(2500.0), $own[$main->getKey()]['open_deals_amount']);
        $this->assertSame(1, $own[$iqan->getKey()]['counts']['leads_count']);

        // The rendered page carries the viewer's own figure, in the organisation currency.
        $this->actingAs($outsider)->get(Projects::getUrl())->assertOk()->assertSee(DealBoard::money(2500.0));
    }

    #[Test]
    public function entering_a_project_makes_it_current_and_opens_the_sales_dashboard(): void
    {
        $rep = $this->salesRep();
        $iqan = $this->makeProject();

        Livewire::actingAs($rep)
            ->test(Projects::class)
            ->callAction('enter', arguments: ['project' => $iqan->getKey()])
            ->assertHasNoActionErrors()
            ->assertRedirect(SalesDashboard::getUrl())
            ->assertNotified(__('projects.notifications.switched.title'));

        $this->assertDatabaseHas('users', ['id' => $rep->getKey(), 'current_project_id' => $iqan->getKey()]);
        $this->assertTrue(app(CurrentProject::class)->for($rep)->is($iqan));
    }

    #[Test]
    public function entering_an_inactive_or_unknown_project_is_refused_with_a_toast(): void
    {
        $rep = $this->salesRep();
        $main = $this->defaultProject();
        $old = $this->makeProject('Old', 'قديم', ['is_active' => false]);

        foreach ([$old->getKey(), 999999, 'abc'] as $refused) {
            Livewire::actingAs($rep)
                ->test(Projects::class)
                ->callAction('enter', arguments: ['project' => $refused])
                ->assertNoRedirect()
                ->assertNotified(__('projects.validation.inactive'));
        }

        $this->assertDatabaseHas('users', ['id' => $rep->getKey(), 'current_project_id' => null]);
        $this->assertTrue(app(CurrentProject::class)->for($rep)->is($main));
    }

    #[Test]
    public function the_manage_link_shows_only_to_a_reader_who_may_enter_administration_and_open_the_resource(): void
    {
        $manage = ProjectResource::getUrl();
        $this->assertUrlInWorkspace(WorkspacePanel::Admin, $manage);

        $this->actingAs($this->admin())->get(Projects::getUrl())->assertOk()
            ->assertSee('href="'.$manage.'"', escape: false)
            ->assertSee(__('projects.actions.manage'));

        $this->actingAs($this->salesRep())->get(Projects::getUrl())->assertOk()
            ->assertDontSee('href="'.$manage.'"', escape: false)
            ->assertDontSee(__('projects.actions.manage'));

        // Holds the settings key but not the Administration workspace: the link would answer 403.
        $this->actingAs($this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::SettingsManage]))
            ->get(Projects::getUrl())->assertOk()
            ->assertDontSee('href="'.$manage.'"', escape: false);

        // Enters Administration but holds no settings key: the resource itself would answer 403.
        $this->actingAs($this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::WorkspaceAdmin]))
            ->get(Projects::getUrl())->assertOk()
            ->assertDontSee('href="'.$manage.'"', escape: false);
    }

    /**
     * The cards as the viewer gets them, keyed by project id.
     *
     * @return array<int, array{project: Project, current: bool, counts: array{leads_count: int, contacts_count: int, accounts_count: int, deals_count: int, open_deals_count: int, open_deals_amount: float}, open_deals_amount: string}>
     */
    private function cardsFor(User $viewer): array
    {
        $component = Livewire::actingAs($viewer)->test(Projects::class);
        $component->assertOk();

        $page = $component->instance();

        $this->assertInstanceOf(Projects::class, $page);

        $cards = [];

        foreach ($page->getCards() as $card) {
            $cards[(int) $card['project']->getKey()] = $card;
        }

        return $cards;
    }
}
