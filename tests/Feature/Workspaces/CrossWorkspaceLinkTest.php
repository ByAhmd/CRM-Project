<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\Permission;
use App\Filament\Pages\System\Backups;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Notifications\RecordAssignedNotification;
use App\Services\Activities\TimelineEntry;
use App\Services\Activities\TimelineReader;
use App\Services\System\BackupFailure;
use App\Services\Tasks\CalendarEvent;
use App\Services\Tasks\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Links never dead-end (decision D-23): every link built into another
 * workspace — a notification's button, a mail's call to action, a task's
 * related record, a timeline entry, a calendar event — is shown only to a
 * reader who may enter that workspace; the same reader holding the
 * workspace sees the link, pointing into the panel that serves the page.
 */
final class CrossWorkspaceLinkTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function a_notification_into_a_workspace_the_reader_may_not_enter_carries_no_link(): void
    {
        $without = $this->userWithPermissions(null, [Permission::LeadViewAny, Permission::WorkspaceTasks]);
        $with = $this->userWithPermissions(null, [Permission::LeadViewAny, Permission::WorkspaceSales]);
        $lead = Lead::factory()->create(['owner_id' => $without->getKey()]);
        $notification = new RecordAssignedNotification($lead, $this->admin());

        $this->assertSame([], $notification->toDatabase($without)['actions'] ?? null);
        $this->assertEmpty($notification->toMail($without)->actionUrl, 'the mail carries a button into a workspace the reader may not enter');

        $url = LeadResource::getUrl('view', ['record' => $lead]);
        $this->assertStringStartsWith(url('/sales/leads/'), $url);
        $this->assertSame($url, $notification->toDatabase($with)['actions'][0]['url'] ?? null);
        $this->assertSame($url, $notification->toMail($with)->actionUrl);
    }

    #[Test]
    public function a_backup_failure_notice_links_to_backups_only_inside_administration(): void
    {
        $failure = new BackupFailure(CarbonImmutable::parse('2026-10-01 03:00:00'), 'dump_failed', 'detail');
        $notification = new BackupFailedNotification($failure);

        $without = $this->userWithPermissions(null, [Permission::RolesManage]);
        $with = $this->userWithPermissions(null, [Permission::RolesManage, Permission::WorkspaceAdmin]);

        $this->assertSame([], $notification->toDatabase($without)['actions'] ?? null);
        $this->assertEmpty($notification->toMail($without)->actionUrl, 'the mail carries a button into a workspace the reader may not enter');
        $this->assertSame(Backups::getUrl(), $notification->toMail($with)->actionUrl);
    }

    #[Test]
    public function a_tasks_related_deal_links_only_for_a_reader_who_may_enter_sales(): void
    {
        $deal = Deal::factory()->create();

        $this->actingAs($this->userWithPermissions(null, [Permission::DealViewAny, Permission::DealViewTeam, Permission::DealViewAll, Permission::WorkspaceTasks]));
        $this->assertNull(TaskResource::urlForSubject($deal));

        $this->actingAs($this->userWithPermissions(null, [Permission::DealViewAny, Permission::DealViewTeam, Permission::DealViewAll, Permission::WorkspaceTasks, Permission::WorkspaceSales]));
        $this->assertSame(url('/sales/deals/'.$deal->getKey()), TaskResource::urlForSubject($deal));
    }

    #[Test]
    public function a_task_on_a_sales_timeline_links_only_for_a_reader_who_may_enter_tasks(): void
    {
        $readsAll = [Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadViewAll, Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskViewAll];
        $lead = Lead::factory()->create();
        $task = Task::factory()->create(['lead_id' => $lead->getKey()]);

        $without = $this->userWithPermissions(null, [...$readsAll, Permission::WorkspaceSales]);
        $with = $this->userWithPermissions(null, [...$readsAll, Permission::WorkspaceSales, Permission::WorkspaceTasks]);

        $this->assertNull($this->taskEntry($lead, $without, $task)->url);
        $this->assertSame(TaskResource::getUrl('view', ['record' => $task]), $this->taskEntry($lead, $with, $task)->url);
        $this->assertStringStartsWith(url('/tasks/tasks/'), (string) $this->taskEntry($lead, $with, $task)->url);
    }

    #[Test]
    public function an_activity_on_the_calendar_links_only_for_a_reader_who_may_enter_sales(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00'));
        $readsAll = [Permission::ActivityViewAny, Permission::ActivityViewTeam, Permission::ActivityViewAll, Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadViewAll, Permission::TaskViewAny];
        $activity = Activity::factory()->create(['occurred_at' => Carbon::parse('2026-09-10 10:00:00')]);

        $without = $this->userWithPermissions(null, [...$readsAll, Permission::WorkspaceTasks]);
        $with = $this->userWithPermissions(null, [...$readsAll, Permission::WorkspaceTasks, Permission::WorkspaceSales]);

        $this->assertNull($this->calendarEvent($without, 'activity-'.$activity->getKey())->url);
        $this->assertSame(ActivityResource::getUrl('view', ['record' => $activity]), $this->calendarEvent($with, 'activity-'.$activity->getKey())->url);
    }

    private function taskEntry(Lead $lead, User $viewer, Task $task): TimelineEntry
    {
        $entry = app(TimelineReader::class)->for($lead, $viewer)->first(
            static fn (TimelineEntry $entry): bool => $entry->sourceType === 'task' && $entry->sourceId === (int) $task->getKey(),
        );

        $this->assertInstanceOf(TimelineEntry::class, $entry, 'the task is missing from the timeline');

        return $entry;
    }

    private function calendarEvent(User $viewer, string $id): CalendarEvent
    {
        $this->actingAs($viewer);

        $event = app(CalendarFeed::class)
            ->events($viewer, Carbon::parse('2026-09-01 00:00:00'), Carbon::parse('2026-10-01 00:00:00'))
            ->first(static fn (CalendarEvent $event): bool => $event->id === $id);

        $this->assertInstanceOf(CalendarEvent::class, $event, "event {$id} missing from the calendar");

        return $event;
    }
}
