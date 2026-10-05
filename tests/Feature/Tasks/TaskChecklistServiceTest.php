<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Models\ActivityLog as AuditLogEntry;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Tasks\RoutineTaskService;
use App\Services\Tasks\TaskChecklist;
use App\Services\Tasks\TaskService;
use App\Services\Tasks\WeeklySummary;
use App\Services\Tasks\WeeklySummaryTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Tests\Concerns\BuildsRoutineTasks;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-25: a task carries an ordered checklist written by whoever may
 * edit it and ticked by whoever may report on it. Every write goes through
 * TaskService: the sync touches only the edited task's items and keeps their
 * ticks, an absent key leaves the checklist alone, a closed task's checklist
 * is frozen, ticks are audited and never notified, completing with unticked
 * items is allowed and recorded, reopening keeps the ticks, and the copies a
 * repeating task and a routine make are unticked.
 */
final class TaskChecklistServiceTest extends TestCase
{
    use BuildsRoutineTasks;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->rep = $this->salesRep();
        $this->tasks = app(TaskService::class);
    }

    // --- Writing the checklist ---

    #[Test]
    public function a_task_is_created_with_its_checklist_in_order_and_the_change_is_audited(): void
    {
        $task = $this->handedOut(['Call the buyer', '  Send   the
quote  ', '', 'Book the visit']);

        $this->assertSame(['Call the buyer', 'Send the quote', 'Book the visit'], TaskChecklist::bodies($task), 'trimmed, one line each, blanks dropped');
        $this->assertSame([0, 1, 2], TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->pluck('position')->all());
        $this->assertSame(0, TaskChecklistItem::query()->whereNotNull('completed_at')->count(), 'a new checklist is unticked');

        $entry = $this->lastEntry(ActivityLogEvent::TaskChecklistChanged, $task);
        $this->assertSame(['Call the buyer', 'Send the quote', 'Book the visit'], $entry->properties->get('checklist_added'));
        $this->assertSame($this->admin->getKey(), (int) $entry->causer_id);
    }

    #[Test]
    public function an_edit_renames_reorders_adds_and_removes_while_keeping_the_ticks_of_kept_items(): void
    {
        $task = $this->handedOut(['One', 'Two', 'Three']);
        [$one, $two, $three] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$two->getKey()]);

        $this->tasks->update($task, ['checklist' => [
            ['id' => $two->getKey(), 'body' => 'Two, renamed'],
            ['id' => null, 'body' => 'Four'],
            ['id' => $one->getKey(), 'body' => 'One'],
        ]], $this->admin);

        $this->assertSame(['Two, renamed', 'Four', 'One'], TaskChecklist::bodies($task));
        $this->assertNull(TaskChecklistItem::query()->find($three->getKey()), 'the item left out is removed');

        $kept = TaskChecklistItem::query()->findOrFail($two->getKey());
        $this->assertNotNull($kept->completed_at, 'a kept item keeps its tick');
        $this->assertSame($this->rep->getKey(), $kept->completed_by);

        $entry = $this->lastEntry(ActivityLogEvent::TaskChecklistChanged, $task);
        $this->assertSame(['Four'], $entry->properties->get('checklist_added'));
        $this->assertSame(['Three'], $entry->properties->get('checklist_removed'));
        $this->assertEquals([['from' => 'Two', 'to' => 'Two, renamed']], $entry->properties->get('checklist_renamed'));
        $this->assertTrue($entry->properties->get('checklist_reordered'));

        // The audit log reads the renames in the reader's language, never as
        // raw JSON with code words (whatever key order the JSON column kept).
        $log = AuditLogEntry::query()->findOrFail($entry->getKey());

        foreach (['ar' => 'من «Two» إلى «Two, renamed»', 'en' => 'from "Two" to "Two, renamed"'] as $locale => $expected) {
            app()->setLocale($locale);
            $rows = collect(app(ActivityLogPresenter::class)->for($log)->detailRows());

            $this->assertSame($expected, $rows->firstWhere('label', __('activity.attributes.checklist_renamed'))['value'] ?? null);
            $this->assertStringNotContainsString('"from"', $rows->pluck('value')->implode(' '));
        }
    }

    #[Test]
    public function an_edit_without_the_checklist_key_leaves_it_alone_and_an_identical_one_writes_nothing(): void
    {
        $task = $this->handedOut(['One', 'Two']);
        $before = ActivityLog::query()->where('description', ActivityLogEvent::TaskChecklistChanged->value)->count();

        $this->tasks->update($task, ['title' => 'Renamed task'], $this->admin);
        $this->assertSame(['One', 'Two'], TaskChecklist::bodies($task));

        $this->tasks->update($task, ['checklist' => TaskChecklist::formState($task)], $this->admin);
        $this->assertSame(['One', 'Two'], TaskChecklist::bodies($task));
        $this->assertSame($before, ActivityLog::query()->where('description', ActivityLogEvent::TaskChecklistChanged->value)->count(), 'no change, no ledger entry');

        $this->tasks->update($task, ['checklist' => []], $this->admin);
        $this->assertSame([], TaskChecklist::bodies($task), 'an empty list submitted on purpose clears it');
    }

    #[Test]
    public function a_forged_id_never_touches_another_tasks_items(): void
    {
        $mine = $this->handedOut(['Mine']);
        $theirs = $this->handedOut(['Theirs, ticked', 'Theirs, open']);
        [$ticked, $open] = $this->items($theirs);
        $this->tasks->tickChecklist($theirs, $this->rep, [$ticked->getKey()]);

        $this->tasks->update($mine, ['checklist' => [
            ['id' => $ticked->getKey(), 'body' => 'Hijacked'],
            ['id' => $open->getKey(), 'body' => 'Also hijacked'],
            ['id' => 999999, 'body' => 'Unknown id'],
        ]], $this->admin);

        $this->assertSame(['Hijacked', 'Also hijacked', 'Unknown id'], TaskChecklist::bodies($mine), 'the forged items are created on the edited task');
        $this->assertSame(['Theirs, ticked', 'Theirs, open'], TaskChecklist::bodies($theirs), 'the other task is untouched');
        $this->assertNotNull($ticked->refresh()->completed_at);
        $this->assertSame((int) $theirs->getKey(), $ticked->task_id);

        // A repeated id keeps the first item only; the repeat becomes a new one.
        [$hijacked] = $this->items($mine);
        $this->tasks->update($mine, ['checklist' => [
            ['id' => $hijacked->getKey(), 'body' => 'First'],
            ['id' => $hijacked->getKey(), 'body' => 'Second'],
        ]], $this->admin);

        $this->assertSame(['First', 'Second'], TaskChecklist::bodies($mine));
        $this->assertSame('First', $hijacked->refresh()->body);
    }

    #[Test]
    public function the_checklist_is_validated(): void
    {
        $this->assertRefused(fn () => $this->handedOut([str_repeat('x', TaskChecklist::BODY_MAX + 1)]), __('tasks.validation.checklist_item_too_long', ['max' => TaskChecklist::BODY_MAX]));
        $this->assertRefused(fn () => $this->handedOut(array_fill(0, TaskChecklist::MAX_ITEMS + 1, 'Step')), __('tasks.validation.checklist_too_many', ['max' => TaskChecklist::MAX_ITEMS]));
        $this->assertRefused(fn () => $this->tasks->create(['title' => 'Bad', 'checklist' => 'not a list'], $this->admin), __('tasks.validation.checklist_invalid'));
        $this->assertRefused(fn () => $this->tasks->create(['title' => 'Bad', 'checklist' => [['body' => ['nested']]]], $this->admin), __('tasks.validation.checklist_invalid'));

        $this->handedOut(array_fill(0, TaskChecklist::MAX_ITEMS, 'Step'));
        $this->assertSame(TaskChecklist::MAX_ITEMS, TaskChecklistItem::query()->count(), 'exactly the limit is fine');
        $this->assertSame(0, Task::query()->where('title', 'Bad')->count(), 'a refused create writes nothing');
    }

    #[Test]
    public function a_closed_or_deleted_tasks_checklist_is_frozen_but_its_other_details_still_save(): void
    {
        $task = $this->handedOut(['One', 'Two']);
        $this->tasks->complete($task, $this->rep);

        $this->assertRefused(
            fn () => $this->tasks->update($task, ['checklist' => [['id' => null, 'body' => 'New']]], $this->admin),
            __('tasks.validation.checklist_frozen'),
        );
        $this->assertSame(['One', 'Two'], TaskChecklist::bodies($task), 'nothing was written');

        $this->tasks->update($task, ['title' => 'Closed but renamed', 'checklist' => TaskChecklist::formState($task)], $this->admin);
        $this->assertSame('Closed but renamed', $task->refresh()->title, 'an unchanged checklist does not block the save');

        $this->expectException(InvalidTaskTransitionException::class);
        $this->expectExceptionMessage(__('tasks.validation.checklist_not_open'));

        [$one] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey()]);
    }

    #[Test]
    public function a_deleted_tasks_checklist_takes_no_ticks(): void
    {
        $task = $this->handedOut(['One']);
        [$one] = $this->items($task);
        $task->delete();

        try {
            $this->tasks->tickChecklist($task, $this->admin, [$one->getKey()]);
            $this->fail('a deleted task was ticked');
        } catch (InvalidTaskTransitionException $exception) {
            $this->assertSame(__('tasks.validation.checklist_not_open'), $exception->getMessage());
        }

        $this->assertNull($one->refresh()->completed_at);
    }

    // --- Ticking ---

    #[Test]
    public function ticking_stamps_who_and_when_unticking_clears_both_and_one_entry_records_each_save(): void
    {
        $task = $this->handedOut(['One', 'Two', 'Three']);
        [$one, $two, $three] = $this->items($task);

        // Only the ticks are watched: the assignment notice belongs to the create.
        Notification::fake();

        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey(), $two->getKey()]);

        $one->refresh();
        $this->assertSame('2026-10-05 09:00:00', $one->completed_at?->format('Y-m-d H:i:s'));
        $this->assertSame($this->rep->getKey(), $one->completed_by);

        $entry = $this->lastEntry(ActivityLogEvent::TaskChecklistTicked, $task);
        $this->assertSame(['One', 'Two'], $entry->properties->get('checklist_checked'));
        $this->assertNull($entry->properties->get('checklist_unchecked'));
        $this->assertSame(2, $entry->properties->get('checklist_done'));
        $this->assertSame(3, $entry->properties->get('checklist_total'));
        $this->assertSame($this->rep->getKey(), (int) $entry->causer_id);

        $this->tasks->tickChecklist($task, $this->admin, [$three->getKey()], [$one->getKey()]);

        $this->assertNull($one->refresh()->completed_at);
        $this->assertNull($one->completed_by);
        $this->assertSame($this->admin->getKey(), $three->refresh()->completed_by);
        $this->assertSame(['Three'], $this->lastEntry(ActivityLogEvent::TaskChecklistTicked, $task)->properties->get('checklist_checked'));
        $this->assertSame(['One'], $this->lastEntry(ActivityLogEvent::TaskChecklistTicked, $task)->properties->get('checklist_unchecked'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_tick_touches_only_the_tasks_own_items_and_a_no_op_writes_nothing(): void
    {
        $task = $this->handedOut(['Mine']);
        $other = $this->handedOut(['Theirs']);
        [$mine] = $this->items($task);
        [$theirs] = $this->items($other);

        $this->tasks->tickChecklist($task, $this->rep, [$theirs->getKey(), 0, -4]);

        $this->assertNull($theirs->refresh()->completed_at, 'another task\'s item is never ticked through this one');
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::TaskChecklistTicked->value)->count(), 'nothing changed, nothing recorded');

        // In both lists: left alone.
        $this->tasks->tickChecklist($task, $this->rep, [$mine->getKey()], [$mine->getKey()]);
        $this->assertNull($mine->refresh()->completed_at);
    }

    #[Test]
    public function the_tick_columns_are_guarded_outside_the_service(): void
    {
        $task = $this->handedOut(['One']);
        [$one] = $this->items($task);

        $this->expectException(LogicException::class);

        $one->forceFill(['completed_at' => now(), 'completed_by' => $this->rep->getKey()])->save();
    }

    // --- Completion, reopening, copies ---

    #[Test]
    public function completing_with_unticked_items_is_allowed_recorded_and_told_to_the_assigner(): void
    {
        Notification::fake();

        $task = $this->handedOut(['One', 'Two', 'Three', 'Four', 'Five']);
        [$one, $two, $three] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey(), $two->getKey(), $three->getKey()]);

        $this->tasks->complete($task, $this->rep);

        $this->assertSame(TaskStatus::Completed, $task->status);

        $entry = $this->lastEntry(ActivityLogEvent::TaskCompleted, $task);
        $this->assertSame(2, $entry->properties->get('checklist_open'));
        $this->assertSame(5, $entry->properties->get('checklist_total'));

        Notification::assertSentTo($this->admin, TaskCompletedNotification::class, function (TaskCompletedNotification $notification): bool {
            app()->setLocale('en');
            $line = (string) ($notification->toMail($this->admin)->introLines[0] ?? '');

            return str_contains($line, 'Not done: 2 of its 5 checklist items');
        });
    }

    #[Test]
    public function the_completion_notice_states_the_open_count_in_both_languages_and_stays_quiet_when_all_are_done(): void
    {
        $task = $this->handedOut(['One', 'Two', 'Three', 'Four', 'Five']);

        app()->setLocale('ar');
        $arabic = (new TaskCompletedNotification($task, $this->rep, 2, 5))->toMail($this->admin)->introLines[0] ?? '';
        $this->assertStringContainsString('لم يُنجز 2 من 5 بنود', (string) $arabic);

        app()->setLocale('en');
        $english = (new TaskCompletedNotification($task, $this->rep, 2, 5))->toMail($this->admin)->introLines[0] ?? '';
        $this->assertStringContainsString('Not done: 2 of its 5 checklist items', (string) $english);

        // One open item of several: the English sentence agrees with the one, not the total.
        $one = (new TaskCompletedNotification($task, $this->rep, 1, 3))->toMail($this->admin)->introLines[0] ?? '';
        $this->assertStringContainsString('Not done: 1 of its 3 checklist items.', (string) $one);
        $this->assertStringNotContainsString('were not done', (string) $one);

        $done = (new TaskCompletedNotification($task, $this->rep, 0, 5))->toMail($this->admin)->introLines[0] ?? '';
        $this->assertSame(__('tasks.notifications.completed_body', ['task' => $task->title, 'by' => $this->rep->name]), $done);
    }

    #[Test]
    public function a_task_without_a_checklist_is_completed_without_checklist_counts(): void
    {
        $task = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        $this->tasks->complete($task, $this->rep);

        $entry = $this->lastEntry(ActivityLogEvent::TaskCompleted, $task);
        $this->assertFalse($entry->properties->has('checklist_open'));
        $this->assertFalse($entry->properties->has('checklist_total'));
    }

    #[Test]
    public function reopening_keeps_the_ticks(): void
    {
        $task = $this->handedOut(['One', 'Two']);
        [$one] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey()]);
        $this->tasks->complete($task, $this->rep);

        $this->tasks->reopen($task, $this->rep);

        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame($this->rep->getKey(), $one->refresh()->completed_by);
        $this->assertSame(['done' => 1, 'total' => 2], TaskChecklist::counts($task->refresh()));
    }

    #[Test]
    public function the_next_occurrence_of_a_repeating_task_copies_the_checklist_unticked(): void
    {
        $task = $this->tasks->create([
            'title' => 'Weekly review',
            'assignee_id' => $this->rep->getKey(),
            'due_at' => '2026-10-06 10:00:00',
            'recurrence_frequency' => RecurrenceFrequency::Weekly->value,
            'recurrence_interval' => 1,
            'checklist' => [['body' => 'Open deals'], ['body' => 'Forecast']],
        ], $this->admin);
        [$first] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$first->getKey()]);

        $this->tasks->complete($task, $this->rep);

        $next = Task::query()->where('series_id', $task->getKey())->firstOrFail();
        $this->assertSame(['Open deals', 'Forecast'], TaskChecklist::bodies($next));
        $this->assertSame(['done' => 0, 'total' => 2], TaskChecklist::counts($next));
        $this->assertSame(['done' => 1, 'total' => 2], TaskChecklist::counts($task->refresh()), 'the completed occurrence keeps its own ticks');
    }

    #[Test]
    public function a_routine_carries_its_checklist_into_every_task_it_creates_unticked(): void
    {
        $this->travelToRiyadh('2026-10-04 08:00');
        $other = $this->salesRep();

        $routine = $this->routine($this->admin, ['checklist' => ['Count the shelves', ' ', 'Report the gaps']], $this->rep, $other);

        $this->assertSame(['Count the shelves', 'Report the gaps'], TaskChecklist::templateBodies($routine));
        $entry = ActivityLog::query()->where('subject_type', $routine->getMorphClass())->where('subject_id', $routine->getKey())
            ->where('description', ActivityLogEvent::LookupUpdated->value)->get()
            ->first(static fn (ActivityLog $log): bool => $log->properties->has('checklist_added'));
        $this->assertNotNull($entry, 'the routine\'s checklist is in the ledger');
        $this->assertSame(['Count the shelves', 'Report the gaps'], $entry->properties->get('checklist_added'));

        $this->assertSame(2, app(RoutineTaskService::class)->generateDue());

        $produced = Task::query()->where('template_id', $routine->getKey())->orderBy('id')->get();
        $this->assertCount(2, $produced);

        foreach ($produced as $task) {
            $this->assertSame(['Count the shelves', 'Report the gaps'], TaskChecklist::bodies($task));
            $this->assertSame(['done' => 0, 'total' => 2], TaskChecklist::counts($task));
        }

        // Each task has its own copy: ticking one leaves the other and the routine alone.
        [$step] = $this->items($produced[0]);
        $this->tasks->tickChecklist($produced[0], $this->rep, [$step->getKey()]);
        $this->assertSame(['done' => 0, 'total' => 2], TaskChecklist::counts($produced[1]->refresh()));
    }

    #[Test]
    public function a_routine_save_without_the_checklist_key_keeps_it_and_a_reorder_is_recorded(): void
    {
        $routine = $this->routine($this->admin, ['checklist' => ['A', 'B']], $this->rep);

        app(RoutineTaskService::class)->save(['title' => 'Renamed routine'], $this->admin, $routine);
        $this->assertSame(['A', 'B'], TaskChecklist::templateBodies($routine));

        app(RoutineTaskService::class)->save(['checklist' => ['B', 'A']], $this->admin, $routine);
        $this->assertSame(['B', 'A'], TaskChecklist::templateBodies($routine));

        $entry = ActivityLog::query()->where('subject_id', $routine->getKey())->where('subject_type', $routine->getMorphClass())
            ->latest('id')->firstOrFail();
        $this->assertTrue($entry->properties->get('checklist_reordered'));

        $this->expectException(ValidationException::class);
        app(RoutineTaskService::class)->save(['checklist' => array_fill(0, TaskChecklist::MAX_ITEMS + 1, 'x')], $this->admin, $routine);
    }

    #[Test]
    public function a_recent_tick_keeps_a_task_off_the_weekly_summarys_stalled_list(): void
    {
        $quiet = $this->handedOut(['One'], 'Quiet task');
        $ticked = $this->handedOut(['One', 'Two'], 'Ticked task');
        $this->tasks->start($quiet, $this->rep);
        $this->tasks->start($ticked, $this->rep);

        $this->travelTo(Carbon::parse('2026-10-09 09:00:00'));
        [$step] = $this->items($ticked);
        $this->tasks->tickChecklist($ticked, $this->rep, [$step->getKey()]);

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $report = app(WeeklySummary::class)->report(CarbonImmutable::now());
        $stalled = array_map(static fn (WeeklySummaryTask $line): string => $line->title, $report->stalled);

        $this->assertContains('Quiet task', $stalled, 'no entry and no tick for five days');
        $this->assertNotContains('Ticked task', $stalled, 'a tick a day ago counts as progress (D-25)');
        $this->assertSame(1, $report->stalledTotal);

        // Four quiet days after that tick, it is stalled again, dated from the tick.
        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));
        $later = app(WeeklySummary::class)->report(CarbonImmutable::now());
        $line = collect($later->stalled)->first(static fn (WeeklySummaryTask $line): bool => $line->title === 'Ticked task');
        $this->assertInstanceOf(WeeklySummaryTask::class, $line);
        $this->assertSame(4, $line->days);
    }

    // --- Helpers ---

    /**
     * A task the admin hands to the rep with the given checklist.
     *
     * @param  list<string>  $items
     */
    private function handedOut(array $items, string $title = 'Prepare the visit'): Task
    {
        return $this->tasks->create([
            'title' => $title,
            'assignee_id' => $this->rep->getKey(),
            'checklist' => array_map(static fn (string $body): array => ['body' => $body], $items),
        ], $this->admin);
    }

    /**
     * @return list<TaskChecklistItem>
     */
    private function items(Task $task): array
    {
        return TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->orderBy('id')->get()->all();
    }

    private function lastEntry(ActivityLogEvent $event, Task $task): ActivityLog
    {
        return ActivityLog::query()
            ->where('description', $event->value)
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->getKey())
            ->latest('id')
            ->firstOrFail();
    }

    private function assertRefused(callable $callback, string $message): void
    {
        DB::beginTransaction();

        try {
            $callback();
            $this->fail('the service accepted it');
        } catch (ValidationException $exception) {
            $this->assertSame($message, collect($exception->errors())->flatten()->first());
        } finally {
            DB::rollBack();
        }
    }
}
