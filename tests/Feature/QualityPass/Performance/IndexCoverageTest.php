<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: the columns the hot queries filter or sort on lead an index
 * (plan section 8, DATABASE_DESIGN.md), and the date-range filters of the
 * growing tables compare the raw column so that index can serve them.
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
            // CalendarFeed::tasks() timed branch starts_at < end.
            'tasks.starts_at' => ['tasks', 'starts_at'],
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
