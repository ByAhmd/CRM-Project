<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CrmRole;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Filament\Pages\Reports\EmployeeDashboardPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Filament\Pages\ReportsHome;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Statistics\Reports\EmployeeDashboard;
use App\Services\Statistics\Reports\EmployeeDashboardEntry;
use App\Support\Filament\WorkspacePanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The employee dashboard page (decision D-26) as a reader meets it: the
 * figures in both languages and directions, «—» for an empty rate, task
 * titles linked only where the reader may open the task and enter the Tasks
 * workspace (A-20, D-23), the way in from the task performance report and
 * from the Reports home, and the Arabic and English strings key for key.
 *
 * Fixture: team Riyadh holds the sales manager and the employee; an
 * outsider rep, outside the team, owns a task the employee commented on.
 */
final class EmployeeDashboardPageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $manager;

    private User $employee;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));

        $this->team = $this->makeTeam();
        $this->manager = $this->salesManager($this->team);
        $this->employee = $this->makeUser(CrmRole::Employee, ['name' => 'Employee One'], $this->team);
        $this->outsider = $this->makeUser(CrmRole::SalesRep, ['name' => 'Outsider Rep']);
    }

    #[Test]
    public function the_dashboard_renders_in_both_languages_and_directions_with_an_empty_rate_as_a_dash(): void
    {
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Late report', 'due_at' => Carbon::parse('2026-10-10 10:00:00')]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $this->manager->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $html = (string) $this->actingAs($this->manager)
                ->get(EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]))
                ->assertOk()
                ->assertSee(__('reports.pages.employee.title'))
                ->assertSee(__('reports.employee.sections.now'))
                ->assertSee(__('reports.employee.sections.performance'))
                ->assertSee(__('reports.employee.sections.trend'))
                ->assertSee(__('reports.employee.lists.overdue.heading'))
                ->assertSee(__('reports.employee.lists.stalled.empty'))
                ->assertSee(__('reports.employee.lists.checklist_open.empty'))
                ->assertSee(__('reports.employee.empty.activity'))
                ->assertSee('Late report')
                ->assertSee(trans_choice('reports.employee.lists.days', 4))
                ->getContent();

            $this->assertMatchesRegularExpression('/<html\b[^>]*lang="'.$locale.'"[^>]*dir="'.$direction.'"/', $html);
            $this->assertMatchesRegularExpression('/fi-wi-stats-overview-stat-value">\s*'.preg_quote(__('common.placeholders.empty'), '/').'\s*</u', $html, 'the on-time rate of an empty month reads as a dash');
            $this->assertMatchesRegularExpression('/fi-wi-stats-overview-stat-value">\s*<span data-crm-static>'.preg_quote(__('common.placeholders.empty'), '/').'</u', $html, 'so does the average, kept out of the count-up');
        }
    }

    #[Test]
    public function with_no_employee_chosen_the_page_says_so(): void
    {
        $this->actingAs($this->manager)
            ->get(EmployeeDashboardPage::getUrl())
            ->assertOk()
            ->assertSee(__('reports.employee.empty.choose.heading'))
            ->assertSee(__('reports.employee.empty.choose.description'))
            ->assertDontSee('crm-employee-kpis', escape: false);

        $nobody = $this->userWithPermissions(null, [Permission::WorkspaceReports, Permission::ReportsView]);

        $this->actingAs($nobody)
            ->get(EmployeeDashboardPage::getUrl())
            ->assertOk()
            ->assertSee(__('reports.employee.empty.nobody.heading'));
    }

    #[Test]
    public function a_task_title_links_only_where_the_reader_may_open_the_task(): void
    {
        $own = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Employee overdue task', 'due_at' => Carbon::parse('2026-10-10 10:00:00')]);
        $this->threadEntryOn($own, $this->employee, Carbon::parse('2026-10-13 10:00:00'), TaskUpdateKind::Progress, 'Started on it');

        $foreign = $this->taskAssignedTo($this->outsider, TaskStatus::InProgress, ['title' => 'Outsider task']);
        $this->threadEntryOn($foreign, $this->employee, Carbon::parse('2026-10-13 11:00:00'), TaskUpdateKind::Comment, 'A word on it');

        $this->assertTrue($this->manager->can('view', $own));
        $this->assertFalse($this->manager->can('view', $foreign->load(['deal', 'lead', 'contact', 'account'])));

        // The manager reads the line on the outsider's task — its title as
        // plain text — but not the comment: the thread opens
        // only with the task (TaskPolicy::view).
        $this->actingAs($this->manager)
            ->get(EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]))
            ->assertOk()
            ->assertSee('href="'.$this->taskUrl($own).'"', escape: false)
            ->assertSee('Started on it')
            ->assertSee('Outsider task')
            ->assertDontSee('A word on it')
            ->assertDontSee($this->taskUrl($foreign), escape: false);

        // An administrator reads both, comment included.
        $this->actingAs($this->admin())
            ->get(EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]))
            ->assertOk()
            ->assertSee('href="'.$this->taskUrl($own).'"', escape: false)
            ->assertSee('href="'.$this->taskUrl($foreign).'"', escape: false)
            ->assertSee('Started on it')
            ->assertSee('A word on it');
    }

    #[Test]
    public function the_latest_activity_carries_no_thread_text_from_a_task_the_reader_may_not_open(): void
    {
        $own = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Employee task']);
        $this->threadEntryOn($own, $this->employee, Carbon::parse('2026-10-13 10:00:00'), TaskUpdateKind::Progress, 'Started on it');

        $foreign = $this->taskAssignedTo($this->outsider, TaskStatus::InProgress, ['title' => 'Outsider task']);
        $this->threadEntryOn($foreign, $this->employee, Carbon::parse('2026-10-13 11:00:00'), TaskUpdateKind::Comment, 'A word on it');

        $dashboard = app(EmployeeDashboard::class);
        $entries = $dashboard->snapshot($this->manager, $this->employee)->latestActivity;

        $this->assertCount(2, $entries);
        $this->assertSame(['Outsider task', 'Employee task'], array_map(static fn (EmployeeDashboardEntry $entry): string => $entry->taskTitle, $entries));
        $this->assertSame([false, true], array_map(static fn (EmployeeDashboardEntry $entry): bool => $entry->readable, $entries));
        $this->assertSame(['', 'Started on it'], array_map(static fn (EmployeeDashboardEntry $entry): string => $entry->body, $entries), 'no body from a task the reader may not open');
        $this->assertSame(TaskUpdateKind::Comment, $entries[0]->kind, 'the line still says what kind of entry it was');

        $forAdmin = $dashboard->snapshot($this->admin(), $this->employee)->latestActivity;

        $this->assertSame(['A word on it', 'Started on it'], array_map(static fn (EmployeeDashboardEntry $entry): string => $entry->body, $forAdmin));
    }

    #[Test]
    public function a_reader_outside_the_tasks_workspace_sees_the_titles_as_plain_text(): void
    {
        $task = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Employee overdue task', 'due_at' => Carbon::parse('2026-10-10 10:00:00')]);
        $reader = $this->userWithPermissions(null, [
            Permission::WorkspaceReports, Permission::ReportsView, Permission::TaskViewAny, Permission::TaskViewAll,
        ]);

        $this->actingAs($reader)
            ->get(EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]))
            ->assertOk()
            ->assertSee('Employee overdue task')
            ->assertDontSee($this->taskUrl($task), escape: false);
    }

    #[Test]
    public function each_employee_row_of_the_task_report_opens_their_dashboard(): void
    {
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-12 10:00:00')]);
        $this->taskAssignedTo($this->manager, TaskStatus::Pending);
        Task::factory()->unassigned()->create(['title' => 'Nobody yet']);

        $this->actingAs($this->manager)
            ->get(TaskPerformanceReportPage::getUrl())
            ->assertOk()
            ->assertSee('href="'.EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]).'"', escape: false)
            ->assertSee('href="'.EmployeeDashboardPage::getUrl(['employee' => $this->manager->getKey()]).'"', escape: false)
            ->assertSee(__('reports.labels.unassigned'));

        $html = (string) $this->actingAs($this->manager)->get(TaskPerformanceReportPage::getUrl())->getContent();
        preg_match_all('/<a href="([^"]+)"[^>]*class="crm-report-row-link"/', $html, $links);

        $this->assertCount(2, $links[1], 'the two people link; the unassigned line stays text');

        // A rep's report holds their own line, which opens their own dashboard.
        $rep = $this->salesRep($this->team);
        $this->taskAssignedTo($rep, TaskStatus::Pending);

        $this->actingAs($rep)
            ->get(TaskPerformanceReportPage::getUrl())
            ->assertOk()
            ->assertSee('href="'.EmployeeDashboardPage::getUrl(['employee' => $rep->getKey()]).'"', escape: false)
            ->assertDontSee(EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]), escape: false);
    }

    #[Test]
    public function the_reports_home_offers_the_dashboard_to_report_readers(): void
    {
        $this->actingAs($this->salesRep())
            ->get(ReportsHome::getUrl())
            ->assertOk()
            ->assertSee('href="'.EmployeeDashboardPage::getUrl().'"', escape: false)
            ->assertSee(__('reports.pages.employee.description'));
    }

    #[Test]
    public function the_reports_home_does_not_offer_the_dashboard_without_the_reports_permission(): void
    {
        // Its own test: a panel's navigation is built once per application,
        // so a second reader in the same test would see the first one's sidebar.
        $this->actingAs($this->userWithPermissions(null, [Permission::WorkspaceReports]))
            ->get(ReportsHome::getUrl())
            ->assertOk()
            ->assertDontSee('href="'.EmployeeDashboardPage::getUrl().'"', escape: false);
    }

    #[Test]
    public function the_page_lives_at_reports_employee_in_the_reports_group_of_the_reports_workspace(): void
    {
        $this->assertSame(url('/reports/employee'), EmployeeDashboardPage::getUrl());
        $this->assertSame('reports', WorkspacePanelRegistry::owningPanelId(EmployeeDashboardPage::class));
        $this->assertSame(NavigationGroup::Reports, EmployeeDashboardPage::getNavigationGroup());
        $this->assertSame(
            url('/reports/employee').'?employee='.$this->employee->getKey(),
            EmployeeDashboardPage::getUrl(['employee' => $this->employee->getKey()]),
        );
    }

    #[Test]
    public function the_arabic_and_english_strings_match_key_for_key(): void
    {
        $arabic = Arr::dot((array) __('reports.employee', [], 'ar'));
        $english = Arr::dot((array) __('reports.employee', [], 'en'));

        $this->assertNotEmpty($english);
        $this->assertSame(array_keys($english), array_keys($arabic));

        foreach ($english as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "en {$key}");
            $this->assertNotSame($value, $arabic[$key], "ar {$key} is not translated");
        }

        foreach (['navigation.employee', 'pages.employee.title', 'pages.employee.description'] as $key) {
            $this->assertNotSame(__('reports.'.$key, [], 'en'), __('reports.'.$key, [], 'ar'));
        }

        $this->assertSame('لوحة الموظف', __('reports.pages.employee.title', [], 'ar'));
        $this->assertSame('Employee dashboard', __('reports.pages.employee.title', [], 'en'));
    }

    private function taskUrl(Task $task): string
    {
        return TaskResource::getUrl('view', ['record' => $task]);
    }
}
