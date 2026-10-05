<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Tests\TestCase;

/**
 * Decision D-25 in `app:demo-data`: the task the administrator hands to the
 * rep is a multi-step job with a checklist, its first step ticked by the rep
 * through the service, and `--fresh` removes every checklist row the run
 * created.
 */
final class TaskChecklistDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('crm.attachments.disk'));
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function the_demo_seeds_a_checklist_through_the_service_and_fresh_removes_it(): void
    {
        $this->assertSame(0, Artisan::call('app:demo-data', ['--force' => true]));

        $items = TaskChecklistItem::query()->orderBy('task_id')->orderBy('position')->get();
        $this->assertCount(4, $items, 'one handed-out demo task carries four steps');
        $this->assertSame(1, $items->whereNotNull('completed_at')->count(), 'the rep ticked the first step');
        $first = $items->firstOrFail();
        $this->assertNotNull($first->completed_at);
        $this->assertNotNull($first->completed_by);

        $task = Task::query()->findOrFail($first->task_id);
        $this->assertNotNull($task->assigned_by);
        $this->assertTrue(ActivityLog::query()->where('description', ActivityLogEvent::TaskChecklistTicked->value)->where('subject_id', $task->getKey())->exists());

        $registered = app(DemoRegistry::class)->ids('task_checklist_items');
        sort($registered);
        $this->assertSame($items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(), $registered);

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(0, TaskChecklistItem::query()->count());
    }
}
