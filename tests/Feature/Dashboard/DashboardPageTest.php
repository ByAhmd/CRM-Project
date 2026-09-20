<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\MyTasksTodayWidget;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The dashboard page (plan section 3.9): it opens for every seeded role,
 * the filters form defaults to the organisation's current month and
 * validates the period, and the owner and team filters show only to a
 * viewer whose deal visibility reaches that far (D-4).
 */
final class DashboardPageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'Asia/Riyadh'));
    }

    #[Test]
    public function the_dashboard_renders_for_every_seeded_role(): void
    {
        $team = $this->makeTeam();

        foreach ([
            $this->admin(),
            $this->salesManager($team),
            $this->salesRep($team),
            $this->support(),
            $this->readOnly(),
        ] as $user) {
            $this->actingAs($user)
                ->get(Dashboard::getUrl())
                ->assertOk()
                ->assertSee(__('dashboard.title'))
                ->assertSee(__('dashboard.filters.period'))
                ->assertSee(__('dashboard.filters.reset'))
                ->assertSee(self::widgetName(SalesKpisWidget::class), false)
                ->assertSee(self::widgetName(LeadsByStatusChart::class), false)
                ->assertSee(self::widgetName(PipelineByStageChart::class), false)
                ->assertSee(self::widgetName(RevenueWonByMonthChart::class), false)
                ->assertSee(self::widgetName(MyTasksTodayWidget::class), false)
                ->assertSee(self::widgetName(UpcomingFollowUpsWidget::class), false)
                ->assertSee(self::widgetName(StaleDealsWidget::class), false)
                ->assertSee(self::widgetName(ActivityCountsWidget::class), false);

            Livewire::actingAs($user)->test(Dashboard::class)->assertOk();
        }
    }

    #[Test]
    public function the_default_period_is_the_current_month_of_the_organisation(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(Dashboard::class)
            ->assertSet('filters.from', self::onDay('2026-09-01'))
            ->assertSet('filters.to', self::onDay('2026-09-30'))
            ->assertSet('filters.pipeline_id', null)
            ->assertOk();
    }

    #[Test]
    public function the_filters_form_refuses_a_reversed_period_and_one_wider_than_a_year(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Dashboard::class);

        $page->set('filters.to', '2026-08-15')
            ->assertHasErrors(['filters.to']);

        $page->set('filters.to', '2026-09-30')
            ->assertHasNoErrors()
            ->set('filters.from', '2025-08-01')
            ->assertHasErrors(['filters.to']);

        $page->set('filters.from', '2025-09-30')
            ->assertHasNoErrors()
            ->assertSet('filters.from', self::onDay('2025-09-30'))
            ->assertSet('filters.to', self::onDay('2026-09-30'));

        $this->assertSame(DashboardFilters::MAX_RANGE_DAYS, DashboardFilters::fromArray(['from' => '2025-09-30', 'to' => '2026-09-30'], $this->admin())->days());
    }

    #[Test]
    public function the_owner_filter_shows_to_a_manager_and_an_admin_but_not_to_a_rep(): void
    {
        $team = $this->makeTeam();

        Livewire::actingAs($this->salesRep($team))
            ->test(Dashboard::class)
            ->assertSchemaComponentHidden('owner_id', 'filtersForm')
            ->assertSchemaComponentHidden('team_id', 'filtersForm')
            ->assertSchemaComponentVisible('pipeline_id', 'filtersForm');

        Livewire::actingAs($this->salesManager($team))
            ->test(Dashboard::class)
            ->assertSchemaComponentVisible('owner_id', 'filtersForm')
            ->assertSchemaComponentHidden('team_id', 'filtersForm');

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertSchemaComponentVisible('owner_id', 'filtersForm')
            ->assertSchemaComponentVisible('team_id', 'filtersForm');
    }

    #[Test]
    public function the_owner_options_are_the_users_within_the_viewer_reach(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();

        $page = Livewire::actingAs($manager)->test(Dashboard::class)->instance();
        assert($page instanceof Dashboard);

        $owner = $page->getFiltersForm()->getFlatFields(withHidden: true)['owner_id'] ?? null;
        assert($owner instanceof Select);
        $options = $owner->getOptions();

        $this->assertArrayHasKey($manager->getKey(), $options);
        $this->assertArrayHasKey($rep->getKey(), $options);
        $this->assertArrayNotHasKey($outsider->getKey(), $options);
    }

    #[Test]
    public function the_pipeline_options_are_the_active_pipelines(): void
    {
        $active = Pipeline::query()->where('is_default', true)->firstOrFail();
        $inactive = Pipeline::factory()->create(['is_active' => false, 'is_default' => false, 'name_en' => 'Legacy', 'name_ar' => 'قديم']);

        $page = Livewire::actingAs($this->admin())->test(Dashboard::class)->instance();
        assert($page instanceof Dashboard);

        $pipeline = $page->getFiltersForm()->getFlatFields(withHidden: true)['pipeline_id'] ?? null;
        assert($pipeline instanceof Select);
        $options = $pipeline->getOptions();

        $this->assertSame([$active->getKey() => $active->display_name], $options);
        $this->assertArrayNotHasKey($inactive->getKey(), $options);
    }

    #[Test]
    public function an_invalid_period_arriving_through_the_url_is_replaced_by_the_defaults_on_mount(): void
    {
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();

        // Reversed: the state the widgets use is the state the form shows, without a stale error.
        Livewire::actingAs($this->admin())
            ->test(Dashboard::class, ['filters' => ['from' => '2026-08-15', 'to' => '2026-08-01', 'pipeline_id' => $pipeline->getKey()]])
            ->assertHasNoErrors()
            ->assertSet('filters.from', self::onDay('2026-09-01'))
            ->assertSet('filters.to', self::onDay('2026-09-30'))
            ->assertSet('filters.pipeline_id', null)
            ->assertOk();

        // Wider than a year.
        Livewire::actingAs($this->admin())
            ->test(Dashboard::class, ['filters' => ['from' => '2025-08-01', 'to' => '2026-09-30']])
            ->assertHasNoErrors()
            ->assertSet('filters.from', self::onDay('2026-09-01'))
            ->assertSet('filters.to', self::onDay('2026-09-30'))
            ->assertOk();

        // A valid period is kept as it came.
        Livewire::actingAs($this->admin())
            ->test(Dashboard::class, ['filters' => ['from' => '2026-08-01', 'to' => '2026-08-31', 'pipeline_id' => $pipeline->getKey()]])
            ->assertHasNoErrors()
            ->assertSet('filters.from', self::onDay('2026-08-01'))
            ->assertSet('filters.to', self::onDay('2026-08-31'))
            ->assertSet('filters.pipeline_id', $pipeline->getKey())
            ->assertOk();
    }

    #[Test]
    public function the_reset_action_returns_the_filters_to_their_defaults(): void
    {
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->set('filters.from', '2026-08-01')
            ->set('filters.to', '2026-08-31')
            ->set('filters.pipeline_id', $pipeline->getKey())
            ->assertSet('filters.from', self::onDay('2026-08-01'))
            ->callAction('resetFilters')
            ->assertHasNoActionErrors()
            ->assertSet('filters.from', self::onDay('2026-09-01'))
            ->assertSet('filters.to', self::onDay('2026-09-30'))
            ->assertSet('filters.pipeline_id', null);
    }

    #[Test]
    public function the_page_widgets_show_figures_from_the_viewer_scope(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $other = $this->salesRep();
        Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Lead::factory()->count(2)->create(['owner_id' => $other->getKey()]);
        Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'My open deal']);
        Deal::factory()->create(['owner_id' => $other->getKey(), 'title' => 'Their open deal']);

        $this->actingAs($rep)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee(self::widgetName(SalesKpisWidget::class), false)
            ->assertSee(self::widgetName(StaleDealsWidget::class), false);

        // The widgets load lazily, so the figures are asserted on the widget itself with the page's filters.
        $kpis = Livewire::actingAs($rep)
            ->test(SalesKpisWidget::class, ['pageFilters' => ['from' => '2026-09-01', 'to' => '2026-09-30']])
            ->instance();
        assert($kpis instanceof SalesKpisWidget);
        $this->assertSame(1, $kpis->kpis()['new_leads']);
        $this->assertSame(1, $kpis->kpis()['open_deals_count']);

        // A filter arriving through the URL with an owner outside reach is ignored, not honoured.
        Livewire::actingAs($rep)
            ->test(Dashboard::class, ['filters' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'owner_id' => $other->getKey()]])
            ->assertSet('filters.owner_id', $other->getKey())
            ->assertOk();

        $this->assertNull(DashboardFilters::fromArray(['owner_id' => $other->getKey()], $rep)->ownerId);
    }

    /**
     * The responsive layout, asserted through the public API Filament reads
     * it from: the page's getColumns() feeds Grid::make(), and each widget's
     * getColumnSpan() is what the widget view hands to the gridColumn()
     * attribute macro. A scalar span only applies from the lg breakpoint, so
     * every widget must declare its 'default' breakpoint explicitly — this
     * test keeps a scalar from creeping back in.
     */
    #[Test]
    public function the_widget_grid_and_every_widget_span_are_declared_per_breakpoint(): void
    {
        $this->assertSame(['default' => 1, 'md' => 2], (new Dashboard)->getColumns());

        // Full width at every breakpoint. ActivityCounts is the LAST widget
        // and has no partner: at half width it would sit beside a permanently
        // empty cell from md up, so it spans the row like the KPI strip.
        foreach ([SalesKpisWidget::class, RevenueWonByMonthChart::class, StaleDealsWidget::class, ActivityCountsWidget::class] as $widget) {
            $this->assertSame(['default' => 'full'], (new $widget)->getColumnSpan(), $widget);
        }

        // Stacked on phones, paired from tablets up.
        foreach ([LeadsByStatusChart::class, PipelineByStageChart::class] as $widget) {
            $this->assertSame(['default' => 'full', 'md' => 1], (new $widget)->getColumnSpan(), $widget);
        }

        // Tables: full width up to lg — half a tablet cannot hold one — then paired.
        foreach ([MyTasksTodayWidget::class, UpcomingFollowUpsWidget::class] as $widget) {
            $this->assertSame(['default' => 'full', 'lg' => 1], (new $widget)->getColumnSpan(), $widget);
        }
    }

    #[Test]
    public function a_user_without_any_permission_sees_the_page_without_widgets(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee(__('dashboard.title'))
            ->assertDontSee(self::widgetName(SalesKpisWidget::class), false)
            ->assertDontSee(self::widgetName(MyTasksTodayWidget::class), false)
            ->assertDontSee(self::widgetName(ActivityCountsWidget::class), false);
    }

    /**
     * Matches a picker state on the given day: a non-native date picker
     * keeps an internal 'Y-m-d H:i:s' state, so only the day is asserted.
     */
    private static function onDay(string $day): Closure
    {
        return static fn (mixed $value): bool => is_string($value) && str_starts_with($value, $day);
    }

    /**
     * The Livewire name a widget renders under — present in the page even
     * while the widget is still a lazy placeholder.
     *
     * @param  class-string  $widget
     */
    private static function widgetName(string $widget): string
    {
        return (string) Livewire::new($widget)->getName();
    }
}
