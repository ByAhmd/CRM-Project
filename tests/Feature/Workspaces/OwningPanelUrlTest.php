<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspacePanel;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Concerns\BuildsUrlsInOwningPanel;
use App\Filament\Pages\AdminHome;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\ReportsHome;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\System\Backups;
use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Pages\Workspaces;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use App\Support\Filament\WorkspacePanelRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Every resource and page builds its URLs in the panel that registers it
 * (decision D-23), whatever panel the request, queued job or console
 * command runs in — so a notification sent from a sales page, a scheduled
 * reminder or a queued mail never points at a panel that does not serve
 * the page. And every screen belongs to exactly one panel.
 */
final class OwningPanelUrlTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function urls_point_into_the_owning_panel_whatever_the_current_panel(): void
    {
        $expected = [
            '/sales/leads/5' => static fn (): string => LeadResource::getUrl('view', ['record' => 5]),
            '/tasks/tasks/5' => static fn (): string => TaskResource::getUrl('view', ['record' => 5]),
            '/admin/users' => static fn (): string => UserResource::getUrl(),
            '/sales' => static fn (): string => SalesDashboard::getUrl(),
            '/tasks' => static fn (): string => TasksDashboard::getUrl(),
            '/tasks/tasks-board' => static fn (): string => TasksBoard::getUrl(),
            '/tasks/calendar' => static fn (): string => Calendar::getUrl(),
            '/reports' => static fn (): string => ReportsHome::getUrl(),
            '/reports/leads' => static fn (): string => LeadReportPage::getUrl(),
            '/admin' => static fn (): string => AdminHome::getUrl(),
            '/admin/backups' => static fn (): string => Backups::getUrl(),
            '/app' => static fn (): string => Workspaces::getUrl(),
            '/app/notification-preferences' => static fn (): string => NotificationPreferences::getUrl(),
        ];

        foreach ([null, ...WorkspacePanelRegistry::panelIds()] as $current) {
            Filament::setCurrentPanel($current);

            foreach ($expected as $path => $build) {
                $this->assertSame(url($path), $build(), 'built while the current panel is '.($current ?? 'none'));
            }
        }
    }

    #[Test]
    public function the_home_panel_is_the_default_and_hosts_the_only_sign_in(): void
    {
        $this->assertSame(WorkspacePanel::HOME, Filament::getDefaultPanel()->getId());
        $this->assertSame(WorkspacePanelRegistry::panelIds(), array_keys(Filament::getPanels()));

        foreach (Filament::getPanels() as $id => $panel) {
            $this->assertSame($id === WorkspacePanel::HOME, $panel->hasLogin(), "{$id} login");
            $this->assertSame($id === WorkspacePanel::HOME, $panel->hasPasswordReset(), "{$id} password reset");
            $this->assertSame($id === WorkspacePanel::HOME, $panel->hasProfile(), "{$id} profile");
            $this->assertSame($id === WorkspacePanel::HOME, $panel->hasMultiFactorAuthentication(), "{$id} two-step verification");
            $this->assertFalse($panel->hasRegistration(), "{$id} registration (D-11 invite-only)");
        }
    }

    #[Test]
    public function every_screen_is_registered_in_exactly_one_panel_and_builds_its_urls_there(): void
    {
        $seen = [];

        foreach (Filament::getPanels() as $id => $panel) {
            foreach ([...$panel->getResources(), ...$panel->getPages(), ...$panel->getWidgets()] as $class) {
                $this->assertArrayNotHasKey($class, $seen, "{$class} is registered in {$id} and ".($seen[$class] ?? ''));
                $seen[$class] = $id;
                $this->assertSame($id, WorkspacePanelRegistry::owningPanelId($class));
            }

            foreach ($panel->getResources() as $resource) {
                $this->assertContains(BuildsUrlsInOwningPanel::class, class_uses_recursive($resource), "{$resource} builds URLs in the current panel");
            }

            foreach ($panel->getPages() as $page) {
                $this->assertContains(BuildsPageUrlsInOwningPanel::class, class_uses_recursive($page), "{$page} builds URLs in the current panel");
            }
        }

        // Nothing under app/Filament is left out of every panel (no discovery would catch it).
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Filament/Resources'))->depth(1)->name('*Resource.php') as $file) {
            $classes[] = 'App\\Filament\\Resources\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
        }

        foreach (['Pages', 'Widgets'] as $folder) {
            foreach (Finder::create()->files()->in(app_path('Filament/'.$folder))->name('*.php') as $file) {
                $classes[] = 'App\\Filament\\'.$folder.'\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
            }
        }

        $this->assertGreaterThan(40, count($classes), 'sanity: the walk found the screens');

        foreach ($classes as $class) {
            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $this->assertArrayHasKey($class, $seen, "{$class} belongs to no panel");
        }
    }

    #[Test]
    public function a_reminder_sent_by_the_scheduler_links_into_the_tasks_workspace(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));

        $employee = $this->employee();
        $task = Task::factory()->create(['assignee_id' => $employee->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:30:00')]);

        Filament::setCurrentPanel(null);
        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $notification = $employee->notifications()->sole();
        $actions = $notification->data['actions'] ?? [];

        $this->assertSame(url('/'.WorkspacePanel::Tasks->path().'/tasks/'.$task->getKey()), $actions[0]['url'] ?? null);
    }

    #[Test]
    public function a_queued_mail_rendered_by_a_worker_links_into_the_owning_workspace(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        // What the worker unserialises: no request, no current panel.
        $notification = unserialize(serialize(new RecordAssignedNotification($lead, $this->admin())));
        Filament::setCurrentPanel(null);

        $this->assertInstanceOf(RecordAssignedNotification::class, $notification);
        $this->assertSame(url('/'.WorkspacePanel::Sales->path().'/leads/'.$lead->getKey()), $notification->toMail($rep)->actionUrl);
    }
}
