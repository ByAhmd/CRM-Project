<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * `tasks.assigned_at` (decision D-21): a nullable DATETIME with its own
 * index, and the backfill that gives every task assigned before the column
 * existed its creation moment. The backfill is plain DML, so it runs here
 * inside the RefreshDatabase transaction; the column itself is in place from
 * the suite's migration run.
 */
final class TaskAssignedAtMigrationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string MIGRATION = 'migrations/2026_10_04_200001_add_assigned_at_to_tasks_table.php';

    #[Test]
    public function the_column_is_a_nullable_datetime_leading_its_own_index(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['tasks', 'assigned_at'],
        );

        $this->assertNotNull($column);
        $this->assertSame('datetime', strtolower((string) $column->type));
        $this->assertSame('YES', $column->nullable);

        $index = DB::selectOne(
            'SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND SEQ_IN_INDEX = 1',
            ['tasks', 'tasks_assigned_at_index'],
        );

        $this->assertNotNull($index);
        $this->assertSame('assigned_at', $index->name);
    }

    #[Test]
    public function the_backfill_dates_every_assigned_task_from_its_creation_and_leaves_the_rest_alone(): void
    {
        $assigned = Task::factory()->create();
        $trashed = Task::factory()->create();
        $trashed->delete();
        $unassigned = Task::factory()->unassigned()->create();
        $stamped = Task::factory()->create();

        // The rows as they stood before the column existed: no stamp, each
        // created on its own day.
        DB::table('tasks')->whereIn('id', [$assigned->getKey(), $trashed->getKey(), $unassigned->getKey()])->update(['assigned_at' => null]);
        DB::table('tasks')->where('id', $assigned->getKey())->update(['created_at' => '2026-03-01 08:15:00']);
        DB::table('tasks')->where('id', $trashed->getKey())->update(['created_at' => '2026-04-02 10:30:00']);
        DB::table('tasks')->where('id', $unassigned->getKey())->update(['created_at' => '2026-05-03 12:45:00']);
        DB::table('tasks')->where('id', $stamped->getKey())->update(['created_at' => '2026-06-04 09:00:00', 'assigned_at' => '2026-06-20 16:00:00']);

        $this->runBackfill();

        $this->assertSame('2026-03-01 08:15:00', $this->assignedAt($assigned));
        $this->assertSame('2026-04-02 10:30:00', $this->assignedAt($trashed), 'a soft-deleted task is restorable, so it is backfilled too');
        $this->assertNull($this->assignedAt($unassigned));
        $this->assertSame('2026-06-20 16:00:00', $this->assignedAt($stamped), 'a stamp already written is kept');
        $this->assertSame(
            Carbon::parse('2026-03-01 08:15:00')->format('Y-m-d H:i:s'),
            Task::query()->findOrFail($assigned->getKey())->assigned_at?->format('Y-m-d H:i:s'),
        );
    }

    private function assignedAt(Task $task): ?string
    {
        $value = DB::table('tasks')->where('id', $task->getKey())->value('assigned_at');

        return $value === null ? null : (string) $value;
    }

    /** Runs the backfill step of the migration on its own. */
    private function runBackfill(): void
    {
        $migration = require database_path(self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);
        assert(method_exists($migration, 'backfill'));

        $migration->backfill();
    }
}
