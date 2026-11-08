<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\WorkspacePanel;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Livewire\ProjectSwitcher;
use App\Models\Lead;
use App\Models\User;
use App\Services\Projects\CurrentProject;
use App\Support\Filament\PanelUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The project switcher in the Sales top bar (decision D-30): Sales only,
 * the active projects with the current one marked, a switch that is kept on
 * the user and changes what every Sales list shows, and a quiet refusal of
 * anything the switcher never offered.
 */
final class ProjectSwitcherTest extends TestCase
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
    public function the_switcher_sits_in_the_sales_top_bar_and_nowhere_else(): void
    {
        $superAdmin = $this->superAdmin();

        $sales = (string) $this->actingAs($superAdmin)->get(PanelUrl::panelHome(WorkspacePanel::Sales))->assertOk()->getContent();

        $this->assertStringContainsString('crm-project-switcher-trigger', $sales);
        $this->assertLessThan(
            strpos($sales, 'crm-project-switcher-trigger'),
            strpos($sales, 'crm-workspace-switcher-trigger'),
            'the project switcher follows the workspace switcher',
        );

        foreach ([WorkspacePanel::Tasks, WorkspacePanel::Reports, WorkspacePanel::Admin] as $other) {
            $html = (string) $this->actingAs($superAdmin)->get(PanelUrl::panelHome($other))->assertOk()->getContent();

            $this->assertStringNotContainsString('crm-project-switcher-trigger', $html, "the {$other->id()} top bar carries a project switcher");
        }

        $home = (string) $this->actingAs($superAdmin)->get(PanelUrl::workspaceScreen())->assertOk()->getContent();

        $this->assertStringNotContainsString('crm-project-switcher-trigger', $home);
    }

    #[Test]
    public function it_lists_the_active_projects_marks_the_current_one_and_names_itself(): void
    {
        $rep = $this->salesRep();
        $main = $this->defaultProject();
        $iqan = $this->makeProject();
        $old = $this->makeProject('Old', 'قديم', ['is_active' => false]);

        $html = $this->switcherOn($rep);

        $this->assertStringContainsString('data-current-project="'.$main->getKey().'"', $html);
        $this->assertStringContainsString('data-project="'.$main->getKey().'"', $html);
        $this->assertStringContainsString('data-project="'.$iqan->getKey().'"', $html);
        $this->assertStringNotContainsString('data-project="'.$old->getKey().'"', $html, 'an inactive project is offered in the switcher');
        // The click sends the page the browser is on; through Alpine, since a
        // wire:click expression resolves `document` against $wire, not the window.
        $this->assertStringContainsString('x-on:click="$wire.switchTo('.$iqan->getKey().', document.location.href)"', $html);
        $this->assertStringNotContainsString('wire:click="switchTo(', $html);
        $this->assertMatchesRegularExpression('/aria-current="true"[^>]*data-project="'.$main->getKey().'"|data-project="'.$main->getKey().'"[^>]*aria-current="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-current="true"[^>]*data-project="'.$iqan->getKey().'"|data-project="'.$iqan->getKey().'"[^>]*aria-current="true"/', $html);
        $this->assertStringContainsString('aria-label="'.e(__('projects.switcher.aria', ['project' => $main->name_ar])).'"', $html);
        // The hidden marker itself, not the aria-label that happens to start with the same words.
        $marker = '<span class="fi-sr-only">'.e(__('projects.switcher.current')).'</span>';
        $this->assertStringContainsString($marker, $html);

        $this->useProject($rep, $iqan);

        $html = $this->switcherOn($rep);

        $this->assertStringContainsString('data-current-project="'.$iqan->getKey().'"', $html);
        $this->assertStringContainsString('aria-label="'.e(__('projects.switcher.aria', ['project' => 'إيقان'])).'"', $html);
        $this->assertSame(1, substr_count($html, $marker), 'only the current item carries the screen-reader marker');
        $this->assertMatchesRegularExpression('/data-project="'.$iqan->getKey().'"[^>]*>(?:(?!<\/button>).)*'.preg_quote($marker, '/').'/s', $html);
    }

    #[Test]
    public function switching_is_kept_on_the_user_and_changes_which_leads_the_list_shows(): void
    {
        $rep = $this->salesRep();
        $main = $this->defaultProject();
        $iqan = $this->makeProject();
        $mainLead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'project_id' => $main->getKey()]);
        $iqanLead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'project_id' => $iqan->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertCanSeeTableRecords([$mainLead])
            ->assertCanNotSeeTableRecords([$iqanLead]);

        // The page to return to travels with the click (the persisted top bar
        // outlives the page it was rendered on), so the list is reloaded as it was.
        Livewire::actingAs($rep)
            ->test(ProjectSwitcher::class)
            ->call('switchTo', $iqan->getKey(), LeadResource::getUrl().'?tableSearch=x')
            ->assertRedirect(LeadResource::getUrl().'?tableSearch=x')
            ->assertNotified(__('projects.notifications.switched.title'));

        $this->assertDatabaseHas('users', ['id' => $rep->getKey(), 'current_project_id' => $iqan->getKey()]);
        $this->assertTrue(app(CurrentProject::class)->for($rep)->is($iqan));

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertCanSeeTableRecords([$iqanLead])
            ->assertCanNotSeeTableRecords([$mainLead]);

        // Remembered across devices: a fresh request as the same user starts in Iqan.
        $this->assertStringContainsString('data-current-project="'.$iqan->getKey().'"', $this->switcherOn($rep));
    }

    #[Test]
    public function an_inactive_or_unknown_project_leaves_the_current_project_unchanged(): void
    {
        $rep = $this->salesRep();
        $main = $this->defaultProject();
        $old = $this->makeProject('Old', 'قديم', ['is_active' => false]);

        foreach ([$old->getKey(), 999999, 'abc', null] as $refused) {
            Livewire::actingAs($rep)
                ->test(ProjectSwitcher::class)
                ->call('switchTo', $refused)
                ->assertNoRedirect()
                ->assertNotNotified(__('projects.notifications.switched.title'));
        }

        $this->assertDatabaseHas('users', ['id' => $rep->getKey(), 'current_project_id' => null]);
        $this->assertTrue(app(CurrentProject::class)->for($rep)->is($main));
    }

    #[Test]
    public function the_return_address_must_be_this_sites_or_the_sales_home_is_used(): void
    {
        $rep = $this->salesRep();
        $iqan = $this->makeProject();
        $main = $this->defaultProject();

        foreach (['https://evil.example/sales', 'http://evil.example\\@'.parse_url(config('app.url'), PHP_URL_HOST).'/sales/leads', null, 42] as $address) {
            Livewire::actingAs($rep)
                ->test(ProjectSwitcher::class)
                ->call('switchTo', $iqan->getKey(), $address)
                ->assertRedirect(PanelUrl::panelHome(WorkspacePanel::Sales));

            $this->assertTrue(app(CurrentProject::class)->for($rep)->is($iqan));
            $this->useProject($rep, $main);
        }

        // An address of this site is honoured as its validated path and query only.
        Livewire::actingAs($rep)
            ->test(ProjectSwitcher::class)
            ->call('switchTo', $iqan->getKey(), LeadResource::getUrl().'?tableSearch=x#fragment')
            ->assertRedirect(LeadResource::getUrl().'?tableSearch=x');
    }

    /** The markup of the project switcher on the Sales home, as the user sees it. */
    private function switcherOn(User $user): string
    {
        $html = (string) $this->actingAs($user)->get(PanelUrl::panelHome(WorkspacePanel::Sales))->assertOk()->getContent();

        $start = strpos($html, 'crm-project-switcher');
        $this->assertNotFalse($start, 'the Sales top bar carries no project switcher');

        return substr($html, $start, 12000);
    }
}
