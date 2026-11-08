<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\CloseReasonKind;
use App\Filament\Pages\Reports\ActivityReportPage;
use App\Filament\Pages\Reports\BaseReportPage;
use App\Filament\Pages\Reports\ConversionFunnelReportPage;
use App\Filament\Pages\Reports\ForecastReportPage;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\Reports\PipelineReportPage;
use App\Filament\Pages\Reports\SalesPerformanceReportPage;
use App\Filament\Pages\Reports\SourcePerformanceReportPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Filament\Pages\Reports\WinLossReportPage;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Deals\DealCloseService;
use App\Services\Statistics\Reports\LeadReport;
use App\Services\Statistics\Reports\PipelineReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\SalesPerformanceReport;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use App\Services\Tasks\TaskService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The commercial reports read one project (decision D-30): ReportFilters
 * resolves the viewer's current project unless the form names another or
 * asks for every project, scope() applies it to the entities that live in a
 * project (leads, contacts, accounts, deals) and ignores it for those that
 * follow their records (tasks, activities), and the report pages offer the
 * select on the commercial reports only.
 *
 * Fixture (September 2026, an admin who sees everything): the default
 * project holds 2 leads, 1 open deal (1,000) and a deal won on 15 September
 * (5,000); «إيقان» holds 3 leads, 2 open deals (2,000 + 3,000) and a deal won
 * the same day (7,000); the admin completed 2 tasks in September.
 */
final class ReportProjectFilterTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private Project $default;

    private Project $iqan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        $this->admin = $this->admin();
        $this->default = $this->defaultProject();
        $this->iqan = $this->makeProject();

        Lead::factory()->count(2)->create(['owner_id' => $this->admin->getKey()]);
        Lead::factory()->count(3)->create(['owner_id' => $this->admin->getKey(), 'project_id' => $this->iqan->getKey()]);

        Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'amount' => 1000]);
        Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'amount' => 2000, 'project_id' => $this->iqan->getKey()]);
        Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'amount' => 3000, 'project_id' => $this->iqan->getKey()]);

        $won = $this->reasonOfKind(CloseReasonKind::Won);
        app(DealCloseService::class)->win(Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'amount' => 5000]), $won, $this->admin);
        app(DealCloseService::class)->win(Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'amount' => 7000, 'project_id' => $this->iqan->getKey()]), $won, $this->admin);

        $tasks = app(TaskService::class);
        $tasks->complete(Task::factory()->create(['assignee_id' => $this->admin->getKey(), 'due_at' => '2026-09-20 10:00:00']), $this->admin);
        $tasks->complete(Task::factory()->create(['assignee_id' => $this->admin->getKey(), 'due_at' => '2026-09-21 10:00:00']), $this->admin);
    }

    #[Test]
    public function the_filters_resolve_the_current_project_by_default_every_project_on_all_and_fall_back_from_a_stale_id(): void
    {
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);

        $this->assertSame($this->default->getKey(), $this->leadFilters([])->projectId, 'a form without the field reads the viewer\'s current project');
        $this->assertSame($this->iqan->getKey(), $this->leadFilters(['project_id' => $this->iqan->getKey()])->projectId);
        $this->assertSame($this->iqan->getKey(), $this->leadFilters(['project_id' => (string) $this->iqan->getKey()])->projectId, 'the select sends the key as a string');
        $this->assertNull($this->leadFilters(['project_id' => ReportFilters::ALL_PROJECTS])->projectId, 'every project');
        $this->assertSame($this->default->getKey(), $this->leadFilters(['project_id' => $legacy->getKey()])->projectId, 'an inactive project falls back to the current one, never to every project');
        $this->assertSame($this->default->getKey(), $this->leadFilters(['project_id' => 999999])->projectId, 'so does an unknown one');

        $this->useProject($this->admin, $this->iqan);

        $this->assertSame($this->iqan->getKey(), $this->leadFilters([])->projectId, 'the current project follows the switcher');
        $this->assertSame($this->iqan->getKey(), $this->leadFilters(['project_id' => $legacy->getKey()])->projectId);

        // The DTO built by hand reads every project, as every report test before D-30 did.
        $this->assertNull((new ReportFilters($this->leadFilters([])->from, $this->leadFilters([])->to))->projectId);
    }

    #[Test]
    public function the_lead_report_counts_the_chosen_project_only_and_every_project_on_all(): void
    {
        $report = app(LeadReport::class);

        $this->assertSame(2, $this->total($report->rows($this->admin, $this->leadFilters([])), 'leads'), 'the current project by default');
        $this->assertSame(3, $this->total($report->rows($this->admin, $this->leadFilters(['project_id' => $this->iqan->getKey()])), 'leads'));
        $this->assertSame(5, $this->total($report->rows($this->admin, $this->leadFilters(['project_id' => ReportFilters::ALL_PROJECTS])), 'leads'));

        $this->useProject($this->admin, $this->iqan);

        $this->assertSame(3, $this->total($report->rows($this->admin, $this->leadFilters([])), 'leads'), 'after switching, the default reads «إيقان»');
    }

    #[Test]
    public function the_pipeline_report_reads_the_open_deals_of_the_chosen_project_only(): void
    {
        $report = app(PipelineReport::class);

        $default = $report->totals($report->rows($this->admin, $this->dealFilters([])));
        $this->assertSame(1, (int) $default->value('deals'));
        $this->assertSame(1000.0, $default->number('amount'));

        $iqan = $report->totals($report->rows($this->admin, $this->dealFilters(['project_id' => $this->iqan->getKey()])));
        $this->assertSame(2, (int) $iqan->value('deals'));
        $this->assertSame(5000.0, $iqan->number('amount'));

        $all = $report->totals($report->rows($this->admin, $this->dealFilters(['project_id' => ReportFilters::ALL_PROJECTS])));
        $this->assertSame(3, (int) $all->value('deals'));
        $this->assertSame(6000.0, $all->number('amount'));
    }

    #[Test]
    public function the_sales_performance_report_counts_the_closed_deals_of_the_chosen_project_only(): void
    {
        $report = app(SalesPerformanceReport::class);

        $default = $report->totals($report->rows($this->admin, $this->dealFilters([])));
        $this->assertSame(1, (int) $default->value('won_count'));
        $this->assertSame(5000.0, $default->number('won_amount'));
        $this->assertSame(1000.0, $default->number('open_amount'));

        $iqan = $report->totals($report->rows($this->admin, $this->dealFilters(['project_id' => $this->iqan->getKey()])));
        $this->assertSame(1, (int) $iqan->value('won_count'));
        $this->assertSame(7000.0, $iqan->number('won_amount'));
        $this->assertSame(5000.0, $iqan->number('open_amount'));

        $all = $report->totals($report->rows($this->admin, $this->dealFilters(['project_id' => ReportFilters::ALL_PROJECTS])));
        $this->assertSame(2, (int) $all->value('won_count'));
        $this->assertSame(12000.0, $all->number('won_amount'));
        $this->assertSame(6000.0, $all->number('open_amount'));
    }

    #[Test]
    public function the_task_report_ignores_the_project_filter(): void
    {
        $report = app(TaskPerformanceReport::class);

        $current = $this->taskReportFilters($this->admin);
        $iqan = $this->taskReportFilters($this->admin, ['project_id' => $this->iqan->getKey()]);
        $all = $this->taskReportFilters($this->admin, ['project_id' => ReportFilters::ALL_PROJECTS]);

        $this->assertSame($this->default->getKey(), $current->projectId, 'resolved like any other report\'s');
        $this->assertSame($this->iqan->getKey(), $iqan->projectId);
        $this->assertNull($all->projectId);

        // Tasks follow their records and live in no project, so the figures never move.
        foreach ([$current, $iqan, $all] as $filters) {
            $this->assertSame(2, $this->total($report->rows($this->admin, $filters), 'completed'));
        }

        $this->assertFalse(ReportFilters::isProjectPartitioned(new Task));
        $this->assertTrue(ReportFilters::isProjectPartitioned(new Lead));
        $this->assertTrue(ReportFilters::isProjectPartitioned(new Deal));
    }

    #[Test]
    public function the_project_select_is_offered_on_the_commercial_reports_only_and_starts_on_the_current_project(): void
    {
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);

        foreach ([
            LeadReportPage::class,
            ConversionFunnelReportPage::class,
            SourcePerformanceReportPage::class,
            PipelineReportPage::class,
            SalesPerformanceReportPage::class,
            WinLossReportPage::class,
            ForecastReportPage::class,
        ] as $page) {
            $component = Livewire::actingAs($this->admin)
                ->test($page)
                ->assertFormFieldExists('project_id')
                ->assertSet('applied.project_id', $this->default->getKey());

            $instance = $component->instance();
            assert($instance instanceof BaseReportPage);

            $this->assertSame($this->default->getKey(), $instance->filters()->projectId, $page);

            $select = $instance->form->getFlatFields(withHidden: true)['project_id'] ?? null;
            $this->assertInstanceOf(Select::class, $select, $page);
            $this->assertSame(__('reports.filters.project'), $select->getLabel(), $page);
            $this->assertSame([
                ReportFilters::ALL_PROJECTS => __('reports.filters.all_projects'),
                (int) $this->default->getKey() => $this->default->display_name,
                (int) $this->iqan->getKey() => $this->iqan->display_name,
            ], $select->getOptions(), "{$page}: every project, then the active projects in switcher order");
            $this->assertArrayNotHasKey((int) $legacy->getKey(), $select->getOptions(), $page);
        }

        foreach ([TaskPerformanceReportPage::class, ActivityReportPage::class] as $page) {
            $component = Livewire::actingAs($this->admin)
                ->test($page)
                ->assertFormFieldDoesNotExist('project_id');

            $instance = $component->instance();
            assert($instance instanceof BaseReportPage);

            $this->assertArrayNotHasKey('project_id', $instance->applied, "{$page}: a report over records that follow their subjects carries no project");
        }

        // The default follows the switcher.
        $this->useProject($this->admin, $this->iqan);

        Livewire::actingAs($this->admin)
            ->test(LeadReportPage::class)
            ->assertSet('applied.project_id', $this->iqan->getKey());
    }

    #[Test]
    public function running_the_form_with_every_project_widens_the_report_and_reset_returns_to_the_current_project(): void
    {
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);

        $component = Livewire::actingAs($this->admin)
            ->test(LeadReportPage::class)
            ->assertSet('applied.project_id', $this->default->getKey())
            ->fillForm(['project_id' => ReportFilters::ALL_PROJECTS])
            ->call('run')
            ->assertHasNoFormErrors()
            ->assertSet('applied.project_id', ReportFilters::ALL_PROJECTS);

        // Every Livewire round-trip hydrates a fresh instance, so the page is read again after each call.
        $page = $component->instance();
        assert($page instanceof LeadReportPage);

        $this->assertNull($page->filters()->projectId);
        $this->assertSame(5, $this->total(app(LeadReport::class)->rows($this->admin, $page->filters()), 'leads'));

        $component->fillForm(['project_id' => $this->iqan->getKey()])
            ->call('run')
            ->assertHasNoFormErrors()
            ->assertSet('applied.project_id', $this->iqan->getKey());

        $page = $component->instance();
        assert($page instanceof LeadReportPage);

        $this->assertSame($this->iqan->getKey(), $page->filters()->projectId);
        $this->assertSame(3, $this->total(app(LeadReport::class)->rows($this->admin, $page->filters()), 'leads'));

        // The export carries the applied project (D-13: a report exports what it shows).
        $csv = $component->callAction('exportCsv')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('report-leads-2026-09-01-2026-09-30.csv');
        $content = base64_decode((string) data_get($csv->effects, 'download.content'), true);
        $this->assertIsString($content);
        $this->assertStringContainsString(__('reports.totals').',3,0,0,0', $content);

        $component->call('resetFilters')
            ->assertSet('applied.project_id', $this->default->getKey());

        // An inactive project is not an option, so the form refuses it; a forged
        // id in the applied state falls back to the current project.
        Livewire::actingAs($this->admin)
            ->test(LeadReportPage::class)
            ->fillForm(['project_id' => $legacy->getKey()])
            ->call('run')
            ->assertHasFormErrors(['project_id']);

        $forged = Livewire::actingAs($this->admin)->test(LeadReportPage::class)->instance();
        assert($forged instanceof LeadReportPage);
        $forged->applied = [...$forged->applied, 'project_id' => $legacy->getKey()];

        $this->assertSame($this->default->getKey(), $forged->filters()->projectId);
    }

    /**
     * The value of one column on the report's totals line.
     *
     * @param  Collection<int, ReportRow>  $rows
     */
    private function total(Collection $rows, string $column): int
    {
        return (int) ReportRow::totals(__('reports.totals'), $rows, [$column])->value($column);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function leadFilters(array $data): ReportFilters
    {
        return ReportFilters::resolve(
            $data + ['from' => '2026-09-01', 'to' => '2026-09-30'],
            $this->admin,
            app(RecordVisibilityResolver::class),
            Lead::permissionGroup(),
            'Asia/Riyadh',
            LeadReport::GROUP_BY,
            LeadReport::GROUP_STATUS,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dealFilters(array $data): ReportFilters
    {
        return ReportFilters::resolve(
            $data + ['from' => '2026-09-01', 'to' => '2026-09-30'],
            $this->admin,
            app(RecordVisibilityResolver::class),
            Deal::permissionGroup(),
            'Asia/Riyadh',
        );
    }
}
