<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Lead;
use App\Services\Tasks\CalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: the columns the hot queries filter or sort on lead an index
 * (plan section 8, DATABASE_DESIGN.md), the default list pages have a
 * composite index for their filter and sort, the lead status tabs and the
 * calendar feed are written so those indexes can serve them, and the
 * date-range filters of the growing tables compare the raw column so that
 * index can serve them.
 */
final class IndexCoverageTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /**
     * Each column, and the query that needs it.
     *
     * @return array<string, array{string, string}>
     */
    public static function hotColumns(): array
    {
        return [
            // ListLeads defaultSort('created_at', 'desc'); dashboard new leads; LeadReport, SourcePerformanceReport, ConversionFunnelReport period.
            'leads.created_at' => ['leads', 'created_at'],
            // LeadFunnelMetrics::qualifiedInPeriod() whereBetween('leads.qualified_at').
            'leads.qualified_at' => ['leads', 'qualified_at'],
            // ListDeals defaultSort('created_at', 'desc').
            'deals.created_at' => ['deals', 'created_at'],
            // RevenueMetrics lost in period, WinLossReport lost branch, DealBoard lost column (won_at already has one).
            'deals.lost_at' => ['deals', 'lost_at'],
            // CalendarFeed::tasks() slice starting inside the range: starts_at in [start, end), ordered by starts_at, id.
            'tasks.starts_at' => ['tasks', 'starts_at'],
            // CalendarFeed::tasks() slice started before the range and running into it: ends_at >= start.
            'tasks.ends_at' => ['tasks', 'ends_at'],
            // CalendarFeed::tasks() due-only slice: due_at in [start, end), ordered by due_at, id.
            'tasks.due_at' => ['tasks', 'due_at'],
            // ConversionFunnelReport::logs() whereBetween('lead_status_logs.changed_at') (deal_stage_logs.changed_at already has one).
            'lead_status_logs.changed_at' => ['lead_status_logs', 'changed_at'],
        ];
    }

    #[Test]
    #[DataProvider('hotColumns')]
    public function a_hot_filter_or_sort_column_leads_an_index(string $table, string $column): void
    {
        $leading = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('seq_in_index', 1)
            ->exists();

        $this->assertTrue($leading, sprintf('No index starts with %s.%s.', $table, $column));
    }

    /**
     * The default list pages. Deals: the status and soft-delete equalities
     * lead and the default sort column follows, so a tab page is read in
     * index order and stops after the page, and its count is index-only.
     * Leads: the status tab filters several status ids, which no index can
     * return in created_at order, so the page reads leads_created_at_index
     * backwards and the soft-delete + status composite answers the
     * pagination count from the index alone (measured, see the migration).
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function listIndexes(): array
    {
        return [
            // ListLeads pagination count: deleted_at IS NULL and lead_status_id IN (...) (the page reads leads_created_at_index backwards).
            'leads status tab count' => ['leads', 'leads_deleted_at_lead_status_id_index', ['deleted_at', 'lead_status_id']],
            // ListDeals status tab: status = ? and deleted_at IS NULL, order by created_at desc, id desc.
            'deals status tab' => ['deals', 'deals_status_deleted_at_created_at_index', ['status', 'deleted_at', 'created_at']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    #[Test]
    #[DataProvider('listIndexes')]
    public function a_default_list_page_has_a_composite_index_for_its_filter_sort_or_count(string $table, string $index, array $columns): void
    {
        $actual = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->orderBy('seq_in_index')
            ->selectRaw('column_name AS indexed_column')
            ->pluck('indexed_column')
            ->map(static fn (mixed $column): string => (string) $column)
            ->all();

        $this->assertSame($columns, $actual, sprintf('%s.%s does not index (%s).', $table, $index, implode(', ', $columns)));
    }

    #[Test]
    public function the_lead_status_tabs_filter_the_status_ids_and_not_an_exists_over_the_statuses(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $admin = $this->superAdmin();
        Lead::factory()->create(['owner_id' => $admin->getKey()]);

        $queries = $this->queriesDuring(fn () => Livewire::actingAs($admin)->test(ListLeads::class)->assertOk());
        $listed = array_values(array_filter(
            $this->queriesTouching($queries, 'leads'),
            static fn (string $sql): bool => preg_match('/^select (\*|count\(\*\) as `aggregate`) from `leads`/i', $sql) === 1,
        ));

        $this->assertCount(2, $listed, 'the open tab issued no lead list query: '.implode("\n", $this->queriesTouching($queries, 'leads')));

        foreach ($listed as $sql) {
            $this->assertStringContainsString('`leads`.`lead_status_id` in (', $sql);
            $this->assertStringNotContainsString('exists', strtolower($sql), 'the status tab joins lead_statuses through EXISTS again: '.$sql);
        }
    }

    #[Test]
    public function the_calendar_feed_reads_tasks_through_bounded_ranges_on_their_own_indexes(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $queries = $this->queriesDuring(fn () => app(CalendarFeed::class)->range(
            $admin,
            Carbon::parse('2026-03-01 00:00:00'),
            Carbon::parse('2026-04-05 00:00:00'),
            TaskResource::getEloquentQuery(),
            ActivityResource::getEloquentQuery(),
        ));
        $tasks = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select * from `tasks`')));

        $this->assertCount(3, $tasks, implode("\n", $tasks));

        // Each slice: a range closed on both sides (or, for the running-into slice, bounded by the range start on
        // ends_at and by starts_at < start), ordered by the column its index leads with, never by an expression.
        $slices = [
            '/`tasks`\.`starts_at` >= \? and `tasks`\.`starts_at` < \? .*order by `tasks`\.`starts_at` asc, `tasks`\.`id` asc limit \d+$/',
            '/`tasks`\.`ends_at` >= \? and `tasks`\.`starts_at` < \? .*order by `tasks`\.`starts_at` asc, `tasks`\.`id` asc limit \d+$/',
            '/`tasks`\.`due_at` >= \? and `tasks`\.`due_at` < \? and `tasks`\.`starts_at` is null .*order by `tasks`\.`due_at` asc, `tasks`\.`id` asc limit \d+$/',
        ];

        foreach ($slices as $slice) {
            $matching = array_filter($tasks, static fn (string $sql): bool => preg_match($slice, $sql) === 1);
            $this->assertCount(1, $matching, $slice."\n".implode("\n", $tasks));
        }

        foreach ($tasks as $sql) {
            $this->assertStringNotContainsString('coalesce', strtolower($sql), 'an expression sort cannot be served by an index: '.$sql);
            $this->assertStringNotContainsString(' or `tasks`.`due_at`', $sql, 'the slices are OR-ed into one scan again: '.$sql);
        }
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function dateRangeFilters(): array
    {
        return [
            'audit log created_at' => [ListActivityLogs::class, 'date_range', 'created_at'],
            'activities occurred_at' => [ListActivities::class, 'occurred_at', 'occurred_at'],
            'tasks due_at' => [ListTasks::class, 'due_at', 'due_at'],
            'deals expected_close_date' => [ListDeals::class, 'expected_close_date', 'expected_close_date'],
        ];
    }

    /**
     * @param  class-string  $page
     */
    #[Test]
    #[DataProvider('dateRangeFilters')]
    public function a_date_range_filter_compares_the_raw_indexed_column(string $page, string $filter, string $column): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin)->test($page);

        $queries = $this->queriesDuring(fn () => $component
            ->filterTable($filter, ['from' => '2026-09-01', 'until' => '2026-09-30'])
            ->assertOk());

        $wrapped = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => (bool) preg_match('/date\(\s*(`\w+`\.)?`'.preg_quote($column, '/').'`\s*\)/i', $sql),
        ));

        $this->assertNotEmpty($queries);
        $this->assertSame([], $wrapped, sprintf('%s filter "%s" wraps %s in DATE(), so its index cannot serve the range.', $page, $filter, $column));
    }
}
