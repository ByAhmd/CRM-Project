<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Support\Database\TimestampRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The 2026_09_14 DATETIME conversions roll back to TIMESTAMP only when every
 * stored value fits the TIMESTAMP range: down() refuses first, naming the
 * table, the column and the rows, so a rollback never fails half-way through
 * an ALTER or zeroes a moment after 2038-01-19 03:14:07 UTC.
 *
 * Every refusal happens before any DDL, which is what lets these tests run
 * down() inside the RefreshDatabase transaction.
 */
final class TimestampRollbackGuardTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string BEYOND_2038 = '2040-06-01 09:30:00';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function convertedColumns(): array
    {
        return [
            'leads.scored_at' => ['2026_09_14_400006_convert_timestamps_to_datetime_on_leads_table', 'leads', 'scored_at'],
            'leads.qualified_at' => ['2026_09_14_400006_convert_timestamps_to_datetime_on_leads_table', 'leads', 'qualified_at'],
            'leads.converted_at' => ['2026_09_14_400006_convert_timestamps_to_datetime_on_leads_table', 'leads', 'converted_at'],
            'leads.last_activity_at' => ['2026_09_14_400006_convert_timestamps_to_datetime_on_leads_table', 'leads', 'last_activity_at'],
            'leads.stale_notified_at' => ['2026_09_14_400006_convert_timestamps_to_datetime_on_leads_table', 'leads', 'stale_notified_at'],
            'deals.won_at' => ['2026_09_14_400007_convert_timestamps_to_datetime_on_deals_table', 'deals', 'won_at'],
            'deals.lost_at' => ['2026_09_14_400007_convert_timestamps_to_datetime_on_deals_table', 'deals', 'lost_at'],
            'deals.last_activity_at' => ['2026_09_14_400007_convert_timestamps_to_datetime_on_deals_table', 'deals', 'last_activity_at'],
            'lead_status_logs.changed_at' => ['2026_09_14_400008_convert_changed_at_to_datetime_on_lead_status_logs_table', 'lead_status_logs', 'changed_at'],
            'deal_stage_logs.changed_at' => ['2026_09_14_400009_convert_changed_at_to_datetime_on_deal_stage_logs_table', 'deal_stage_logs', 'changed_at'],
            'notes.edited_at' => ['2026_09_14_400010_convert_edited_at_to_datetime_on_notes_table', 'notes', 'edited_at'],
        ];
    }

    #[Test]
    #[DataProvider('convertedColumns')]
    public function rolling_back_refuses_a_value_after_2038_naming_table_column_and_row(string $migration, string $table, string $column): void
    {
        $this->seedLookups();
        $id = $this->rowIn($table);
        DB::table($table)->where('id', $id)->update([$column => self::BEYOND_2038]);

        try {
            $this->rollBack($migration);
            $this->fail("down() of {$migration} converted {$table}.{$column} holding ".self::BEYOND_2038);
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString("{$table}.{$column} holds values outside the TIMESTAMP range in rows {$id}", $refusal->getMessage());
        }

        $this->assertSame('datetime', $this->typeOf($table, $column), 'the refusal must come before any DDL');
        $this->assertSame(self::BEYOND_2038, (string) DB::table($table)->where('id', $id)->value($column));
    }

    #[Test]
    public function every_offending_column_and_row_is_named_in_one_refusal(): void
    {
        $this->seedLookups();
        $first = $this->rowIn('leads');
        $second = $this->rowIn('leads');
        DB::table('leads')->whereIn('id', [$first, $second])->update(['qualified_at' => self::BEYOND_2038]);
        DB::table('leads')->where('id', $second)->update(['converted_at' => '1969-07-20 20:17:00']);

        try {
            TimestampRange::refuseValuesOutside('leads', ['scored_at', 'qualified_at', 'converted_at']);
            $this->fail('values outside the TIMESTAMP range were not refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString("leads.qualified_at holds values outside the TIMESTAMP range in rows {$first}, {$second}", $refusal->getMessage());
            $this->assertStringContainsString("leads.converted_at holds values outside the TIMESTAMP range in rows {$second}", $refusal->getMessage());
            $this->assertStringNotContainsString('leads.scored_at', $refusal->getMessage());
        }
    }

    #[Test]
    public function values_up_to_the_last_timestamp_instant_are_not_refused(): void
    {
        $this->seedLookups();
        $lastInstant = $this->sessionMoment(TimestampRange::LAST_INSTANT);
        $firstInstant = $this->sessionMoment(TimestampRange::FIRST_INSTANT);
        $id = $this->rowIn('deals');
        DB::table('deals')->where('id', $id)->update(['won_at' => $lastInstant, 'lost_at' => $firstInstant, 'last_activity_at' => null]);

        TimestampRange::refuseValuesOutside('deals', ['won_at', 'lost_at', 'last_activity_at']);

        $this->assertSame($lastInstant, (string) DB::table('deals')->where('id', $id)->value('won_at'));
    }

    private function rowIn(string $table): int
    {
        return match ($table) {
            'leads' => (int) Lead::factory()->create()->getKey(),
            'deals' => (int) Deal::factory()->create()->getKey(),
            'lead_status_logs' => (int) DB::table('lead_status_logs')->insertGetId([
                'lead_id' => Lead::factory()->create()->getKey(),
                'to_status_id' => DB::table('lead_statuses')->where('is_default', true)->value('id'),
                'changed_at' => now(),
            ]),
            'deal_stage_logs' => (int) DB::table('deal_stage_logs')->insertGetId([
                'deal_id' => ($deal = Deal::factory()->create())->getKey(),
                'to_stage_id' => $deal->stage_id,
                'changed_at' => now(),
            ]),
            'notes' => (int) Note::factory()->create([
                'lead_id' => Lead::factory()->create()->getKey(),
                'author_id' => User::factory()->create()->getKey(),
            ])->getKey(),
            default => throw new RuntimeException("no fixture row for {$table}"),
        };
    }

    private function rollBack(string $name): void
    {
        $migration = require database_path("migrations/{$name}.php");

        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'down'), "{$name} has no down()");

        $migration->down();
    }

    /** The instant as a wall clock in the session time zone, the zone the TIMESTAMP conversion reads. */
    private function sessionMoment(int $instant): string
    {
        return (string) DB::selectOne(sprintf('SELECT FROM_UNIXTIME(%d) AS moment', $instant))->moment;
    }

    private function typeOf(string $table, string $column): string
    {
        return (string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->value('DATA_TYPE');
    }
}
