<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\NotificationPreferences;
use App\Models\User;
use App\Support\Filament\PanelUrl;
use App\Support\Filament\WorkspacePanelRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The workspace switcher in every top bar (decision D-23): it lists only the
 * workspaces the user may enter — a locked one would answer 403 — marks the
 * current one, ends with the workspace screen and names itself for screen
 * readers. Links into another panel are full page loads, never in-place
 * swaps, and so is the calendar.
 */
final class WorkspaceSwitcherTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
    }

    #[Test]
    public function it_lists_only_the_enterable_workspaces_and_marks_the_current_one(): void
    {
        $html = $this->switcherOn($this->salesRep(), WorkspacePanel::Sales);

        foreach ([WorkspacePanel::Sales, WorkspacePanel::Tasks, WorkspacePanel::Reports] as $workspace) {
            $this->assertStringContainsString('data-workspace="'.$workspace->id().'"', $html);
        }

        $this->assertStringNotContainsString('data-workspace="admin"', $html, 'a locked workspace is offered in the switcher');
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-workspace="sales"|data-workspace="sales"[^>]*aria-current="page"/', $html);
        $this->assertStringContainsString('href="'.PanelUrl::workspaceScreen().'"', $html);
        $this->assertStringContainsString('aria-label="تبديل مساحة العمل (الحالية: المبيعات)"', $html);
    }

    #[Test]
    public function an_employee_is_offered_tasks_and_the_workspace_screen_only(): void
    {
        $html = $this->switcherOn($this->employee(), WorkspacePanel::Tasks);

        $this->assertStringContainsString('data-workspace="tasks"', $html);

        foreach (['sales', 'reports', 'admin'] as $locked) {
            $this->assertStringNotContainsString('data-workspace="'.$locked.'"', $html);
        }

        $this->assertStringContainsString('href="'.PanelUrl::workspaceScreen().'"', $html);
    }

    #[Test]
    public function the_workspace_screen_item_comes_last(): void
    {
        $html = $this->switcherOn($this->superAdmin(), WorkspacePanel::Admin);

        $screen = strpos($html, 'crm-workspace-switcher-screen');

        foreach (WorkspacePanel::ordered() as $workspace) {
            $this->assertLessThan($screen, strpos($html, 'data-workspace="'.$workspace->id().'"'));
        }
    }

    #[Test]
    public function the_switcher_also_sits_in_the_home_panels_top_bar(): void
    {
        $html = (string) $this->actingAs($this->salesRep())->get(PanelUrl::workspaceScreen())->assertOk()->getContent();

        $this->assertStringContainsString('crm-workspace-switcher-trigger', $html);
        $this->assertStringContainsString('aria-label="تبديل مساحة العمل (الحالية: لوحة التحكم)"', $html);
    }

    #[Test]
    public function links_into_another_panel_and_to_the_calendar_are_full_page_loads(): void
    {
        $html = (string) $this->actingAs($this->superAdmin())->get(PanelUrl::panelHome(WorkspacePanel::Sales))->assertOk()->getContent();

        foreach ([WorkspacePanel::Tasks, WorkspacePanel::Reports, WorkspacePanel::Admin] as $other) {
            $this->assertMatchesRegularExpression('/href="'.preg_quote(PanelUrl::panelHome($other), '/').'"(?![^>]*wire:navigate)/', $html);
        }

        // Every other panel's whole address space is excepted from SPA swaps; the calendar is excepted inside Tasks.
        foreach (WorkspacePanelRegistry::panelIds() as $id) {
            Filament::setCurrentPanel($id);
            $exceptions = Filament::getPanel($id)->getSpaUrlExceptions();

            foreach (WorkspacePanelRegistry::panelIds() as $other) {
                if ($other !== $id) {
                    $this->assertContains(url(Filament::getPanel($other)->getPath()).'/*', $exceptions, "{$id} swaps pages of {$other} in place");
                }
            }
        }

        $this->assertContains(Calendar::getUrl(), Filament::getPanel(WorkspacePanel::Tasks->id())->getSpaUrlExceptions());
    }

    #[Test]
    public function every_panel_menu_leads_to_the_profile_and_the_notification_preferences(): void
    {
        $html = (string) $this->actingAs($this->employee())->get(PanelUrl::panelHome(WorkspacePanel::Tasks))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.Filament::getPanel(WorkspacePanel::HOME)->getProfileUrl().'"', $html);
        $this->assertStringContainsString('href="'.NotificationPreferences::getUrl().'"', $html);
    }

    private function switcherOn(User $user, WorkspacePanel $workspace): string
    {
        $html = (string) $this->actingAs($user)->get(PanelUrl::panelHome($workspace))->assertOk()->getContent();

        $start = strpos($html, 'crm-workspace-switcher');
        $this->assertNotFalse($start, 'the top bar carries no workspace switcher');

        return substr($html, $start, 12000);
    }
}
