<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe item 1: DATABASE_DESIGN.md types these moments as DATETIME, and the
 * tasks / activities / custom-field migrations follow it, but the leads,
 * deals, status/stage log and notes migrations use TIMESTAMP — a 2038 range
 * and a value silently converted through the MySQL session time zone, which
 * nothing pins (config/database.php sets no connection timezone while
 * app.timezone is Asia/Riyadh).
 */
final class DateTimeColumnTypeProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const array DESIGNED_AS_DATETIME = [
        'leads' => ['scored_at', 'qualified_at', 'converted_at', 'last_activity_at', 'stale_notified_at'],
        'deals' => ['won_at', 'lost_at', 'last_activity_at'],
        'lead_status_logs' => ['changed_at'],
        'deal_stage_logs' => ['changed_at'],
        'notes' => ['edited_at'],
        'tasks' => ['due_at', 'starts_at', 'ends_at', 'completed_at', 'reminder_at', 'reminder_sent_at', 'overdue_notified_at'],
        'activities' => ['occurred_at'],
    ];

    #[Test]
    public function every_moment_the_design_types_as_datetime_is_a_datetime_column(): void
    {
        $mismatches = [];

        foreach (self::DESIGNED_AS_DATETIME as $table => $columns) {
            foreach ($columns as $column) {
                $type = DB::selectOne(
                    'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column],
                )?->type;

                if ($type !== 'datetime') {
                    $mismatches[] = "{$table}.{$column} is ".var_export($type, true);
                }
            }
        }

        $this->assertSame([], $mismatches);
    }
}
