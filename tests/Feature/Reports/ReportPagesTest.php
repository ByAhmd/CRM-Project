<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\LeadStatusKind;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
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
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\Role;
use App\Models\User;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * What the nine report pages share (module 23): the `reports.view` gate,
 * the navigation group, the filter form and its validation, the locked
 * applied state, and the CSV / XLSX export of the rendered table.
 *
 * The seeded matrix grants `reports.view` to every role including
 * sales_rep (D-13: reps report and export inside their own scope), so the
 * negative gate cases use a role without the permission and a user with
 * no role at all.
 */
final class ReportPagesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** @var list<class-string<BaseReportPage>> */
    private const PAGES = [
        LeadReportPage::class,
        ConversionFunnelReportPage::class,
        PipelineReportPage::class,
        SalesPerformanceReportPage::class,
        ActivityReportPage::class,
        SourcePerformanceReportPage::class,
        WinLossReportPage::class,
        ForecastReportPage::class,
        TaskPerformanceReportPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function every_report_page_renders_for_a_sales_manager(): void
    {
        $manager = $this->salesManager($this->makeTeam());

        foreach (self::PAGES as $page) {
            $this->actingAs($manager)
                ->get($page::getUrl())
                ->assertOk()
                ->assertSee(__('reports.pages.'.$page::reportKey().'.title'))
                ->assertSee(__('reports.filters.run'));

            Livewire::actingAs($manager)->test($page)->assertOk();
        }
    }

    #[Test]
    public function every_report_page_is_refused_without_the_reports_permission_and_to_a_user_without_roles(): void
    {
        $viewer = $this->userWithoutReportsPermission();
        $nobody = User::factory()->create();

        foreach (self::PAGES as $page) {
            $this->actingAs($viewer);
            $this->assertFalse($page::canAccess(), $page);
            $this->get($page::getUrl())->assertForbidden();

            $this->actingAs($nobody);
            $this->assertFalse($page::canAccess(), $page);
            $this->get($page::getUrl())->assertForbidden();
        }
    }

    #[Test]
    public function every_seeded_role_with_the_permission_may_open_the_reports(): void
    {
        foreach ([$this->support(), $this->readOnly(), $this->admin(), $this->salesRep()] as $user) {
            $this->actingAs($user);
            $this->assertTrue(LeadReportPage::canAccess());
            $this->get(LeadReportPage::getUrl())->assertOk();
        }
    }

    #[Test]
    public function the_navigation_lists_the_nine_report_pages_to_reports_view_holders(): void
    {
        $this->actingAs($this->salesManager());
        $group = $this->reportsGroup();

        $this->assertNotNull($group);

        $labels = collect($group->getItems())->map(fn (mixed $item): string => (string) $item->getLabel())->all();

        $this->assertCount(9, $labels);

        foreach (self::PAGES as $page) {
            $this->assertContains($page::getNavigationLabel(), $labels);
        }
    }

    #[Test]
    public function the_navigation_hides_the_reports_group_without_the_permission(): void
    {
        $this->actingAs($this->userWithoutReportsPermission());

        $this->assertNull($this->reportsGroup());

        foreach (self::PAGES as $page) {
            $this->assertFalse($page::canAccess(), $page);
        }
    }

    #[Test]
    public function the_export_actions_download_the_table_as_csv_and_xlsx(): void
    {
        $manager = $this->salesManager();
        Lead::factory()->count(2)->create(['owner_id' => $manager->getKey()]);

        $csv = Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->callAction('exportCsv')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('report-leads-2026-09-01-2026-09-30.csv');

        $content = base64_decode((string) data_get($csv->effects, 'download.content'), true);

        $this->assertIsString($content);
        $this->assertStringContainsString(__('reports.filters.options.group_by.status'), $content);
        $this->assertStringContainsString(__('reports.columns.lead.leads'), $content);
        $this->assertStringContainsString(LeadStatus::query()->where('kind', LeadStatusKind::New->value)->firstOrFail()->display_name.',2,0,0,0', $content);
        $this->assertStringContainsString(__('reports.totals').',2,0,0,0', $content);

        $xlsx = Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->callAction('exportXlsx')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('report-leads-2026-09-01-2026-09-30.xlsx');

        $binary = base64_decode((string) data_get($xlsx->effects, 'download.content'), true);

        $this->assertIsString($binary);
        $this->assertStringStartsWith('PK', $binary, 'an XLSX file is a zip archive');
    }

    #[Test]
    public function the_export_actions_are_gated_by_the_reports_permission_and_stay_inside_the_scope(): void
    {
        Livewire::actingAs($this->userWithoutReportsPermission())
            ->test(LeadReportPage::class)
            ->assertForbidden();

        $rep = $this->salesRep();
        Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Lead::factory()->count(2)->create(['owner_id' => $this->salesRep()->getKey()]);

        $csv = Livewire::actingAs($rep)
            ->test(LeadReportPage::class)
            ->callAction('exportCsv')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('report-leads-2026-09-01-2026-09-30.csv');

        $content = base64_decode((string) data_get($csv->effects, 'download.content'), true);

        $this->assertIsString($content);
        $this->assertStringContainsString(__('reports.totals').',1,0,0,0', $content, 'a rep exports their own leads only (D-13)');
    }

    #[Test]
    public function the_filters_form_refuses_an_end_before_the_start_and_an_oversized_range(): void
    {
        $manager = $this->salesManager();

        Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->fillForm(['from' => '2026-09-10', 'to' => '2026-09-01'])
            ->call('run')
            ->assertHasFormErrors(['to']);

        Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->fillForm(['from' => '2024-01-01', 'to' => '2026-09-01'])
            ->call('run')
            ->assertHasFormErrors(['to']);

        Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->fillForm(['from' => '', 'to' => '2026-09-01'])
            ->call('run')
            ->assertHasFormErrors(['from']);
    }

    #[Test]
    public function running_the_form_applies_the_filters_and_reset_returns_to_the_current_month(): void
    {
        $manager = $this->salesManager();

        $component = Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->assertSet('applied.from', '2026-09-01')
            ->assertSet('applied.to', '2026-09-30')
            ->assertSet('applied.group_by', 'status')
            ->fillForm(['from' => '2026-08-01', 'to' => '2026-08-31', 'group_by' => 'owner'])
            ->call('run')
            ->assertHasNoFormErrors()
            ->assertSet('applied.from', '2026-08-01')
            ->assertSet('applied.to', '2026-08-31')
            ->assertSet('applied.group_by', 'owner');

        $page = $component->instance();
        assert($page instanceof LeadReportPage);

        $filters = $page->filters();

        $this->assertSame('2026-08-01', $filters->fromDate('Asia/Riyadh'));
        $this->assertSame('2026-08-31', $filters->toDate('Asia/Riyadh'));
        $this->assertSame('owner', $filters->groupBy);
        $this->assertSame(31, $filters->days());

        $component->call('resetFilters')
            ->assertSet('applied.from', '2026-09-01')
            ->assertSet('applied.group_by', 'status');
    }

    #[Test]
    public function an_owner_outside_the_viewer_scope_is_dropped_from_the_filters(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();

        $inside = Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->fillForm(['owner_id' => $member->getKey()])
            ->call('run')
            ->assertHasNoFormErrors()
            ->instance();
        assert($inside instanceof LeadReportPage);

        $this->assertSame($member->getKey(), $inside->filters()->ownerId);

        $forged = Livewire::actingAs($manager)->test(LeadReportPage::class)->instance();
        assert($forged instanceof LeadReportPage);
        $forged->applied = [...$forged->applied, 'owner_id' => $outsider->getKey(), 'team_id' => $team->getKey()];

        $this->assertNull($forged->filters()->ownerId, 'an owner outside the assignable users is dropped');
        $this->assertNull($forged->filters()->teamId, 'a team filter is honoured only for viewers who see everything');
    }

    #[Test]
    public function the_applied_filters_cannot_be_set_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->salesManager())
            ->test(LeadReportPage::class)
            ->set('applied', ['from' => '2020-01-01', 'to' => '2026-12-31']);
    }

    #[Test]
    public function the_team_filter_is_offered_to_viewers_who_see_everything_only(): void
    {
        Livewire::actingAs($this->admin())
            ->test(LeadReportPage::class)
            ->assertFormFieldExists('team_id')
            ->assertFormFieldExists('owner_id')
            ->assertFormFieldExists('group_by');

        Livewire::actingAs($this->salesManager())
            ->test(LeadReportPage::class)
            ->assertFormFieldDoesNotExist('team_id')
            ->assertFormFieldExists('owner_id');
    }

    #[Test]
    public function snapshot_reports_offer_the_pipeline_filter_instead_of_the_period(): void
    {
        $manager = $this->salesManager();

        foreach ([PipelineReportPage::class, ForecastReportPage::class] as $page) {
            Livewire::actingAs($manager)
                ->test($page)
                ->assertFormFieldExists('pipeline_id')
                ->assertFormFieldDoesNotExist('from')
                ->assertFormFieldDoesNotExist('to')
                ->assertFormFieldDoesNotExist('group_by');
        }

        Livewire::actingAs($manager)
            ->test(SalesPerformanceReportPage::class)
            ->assertFormFieldExists('pipeline_id')
            ->assertFormFieldExists('from')
            ->assertFormFieldExists('to');

        Livewire::actingAs($manager)
            ->test(ConversionFunnelReportPage::class)
            ->assertFormFieldDoesNotExist('pipeline_id')
            ->assertFormFieldDoesNotExist('group_by');
    }

    #[Test]
    public function only_the_snapshot_reports_are_scoped_to_one_pipeline_by_default(): void
    {
        $manager = $this->salesManager();
        $default = (int) Pipeline::query()->where('is_default', true)->value('id');

        foreach ([PipelineReportPage::class, ForecastReportPage::class] as $page) {
            Livewire::actingAs($manager)
                ->test($page)
                ->assertSet('applied.pipeline_id', $default);
        }

        // Sales performance and win / loss are defined over every deal in
        // scope; the pipeline filter narrows them, it does not define them,
        // so it starts empty and "all pipelines" stays reachable.
        foreach ([SalesPerformanceReportPage::class, WinLossReportPage::class] as $page) {
            $component = Livewire::actingAs($manager)
                ->test($page)
                ->assertSet('applied.pipeline_id', null);

            $instance = $component->instance();
            assert($instance instanceof BaseReportPage);

            $this->assertNull($instance->filters()->pipelineId);

            $component->fillForm(['pipeline_id' => $default])
                ->call('run')
                ->assertHasNoFormErrors()
                ->assertSet('applied.pipeline_id', $default);
        }
    }

    #[Test]
    public function an_empty_report_shows_the_empty_state_and_a_filled_one_shows_the_table_and_the_chart(): void
    {
        $manager = $this->salesManager();

        Livewire::actingAs($manager)
            ->test(SalesPerformanceReportPage::class)
            ->assertSee(__('reports.empty.no_data'))
            ->assertDontSee(__('reports.sections.chart'));

        // A zero-filling report emits one line per active lookup whatever the
        // period, so emptiness is read from the figures, not the row count.
        Livewire::actingAs($manager)
            ->test(SourcePerformanceReportPage::class)
            ->fillForm(['from' => '2020-01-01', 'to' => '2020-01-31'])
            ->call('run')
            ->assertHasNoFormErrors()
            ->assertSee(__('reports.empty.no_data'))
            ->assertDontSee(__('reports.sections.table'));

        Lead::factory()->create(['owner_id' => $manager->getKey()]);

        Livewire::actingAs($manager)
            ->test(LeadReportPage::class)
            ->assertDontSee(__('reports.empty.no_data'))
            ->assertSee(__('reports.sections.chart'))
            ->assertSee(__('reports.totals'))
            ->assertSee('crm-report-chart-leads')
            ->assertSee('x-load-src=', false)
            ->assertSee('data-crm-chart-color="primary"', false);
    }

    #[Test]
    public function figures_are_formatted_for_the_locale_and_the_organisation_currency(): void
    {
        app()->setLocale('en');

        $this->assertSame('SAR 1,234.50', BaseReportPage::format(ReportRow::FORMAT_MONEY, 1234.5));
        $this->assertSame('66.7%', BaseReportPage::format(ReportRow::FORMAT_PERCENT, 66.7));
        $this->assertSame('12.0', BaseReportPage::format(ReportRow::FORMAT_DECIMAL, 12));
        $this->assertSame('1,200', BaseReportPage::format(ReportRow::FORMAT_COUNT, 1200));
        $this->assertSame('Won', BaseReportPage::format(ReportRow::FORMAT_TEXT, 'Won'));

        app()->setLocale('ar');

        $this->assertNotSame('', BaseReportPage::format(ReportRow::FORMAT_MONEY, 1234.5));
        $this->assertSame(ReportFilters::MAX_RANGE_DAYS, 731);

        app()->setLocale((string) config('app.locale'));
    }

    /** A user whose role reads leads but was never granted `reports.view`. */
    private function userWithoutReportsPermission(): User
    {
        $role = Role::query()->create(['name' => 'viewer', 'guard_name' => 'web', 'name_ar' => 'مشاهد', 'name_en' => 'Viewer']);
        $role->givePermissionTo(Permission::LeadViewAny->value);

        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }

    private function reportsGroup(): ?FilamentNavigationGroup
    {
        foreach (Filament::getNavigation() as $group) {
            if ((string) $group->getLabel() === NavigationGroup::Reports->getLabel()) {
                return $group;
            }
        }

        return null;
    }
}
