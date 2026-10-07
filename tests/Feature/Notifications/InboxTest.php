<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\AdminInbox;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\ReportsInbox;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SalesInbox;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Pages\TasksInbox;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Notifications\TaskCommentNotification;
use App\Services\Notifications\InboxService;
use App\Support\Filament\PanelUrl;
use App\Support\Filament\WorkspacePanelRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The Inbox «الوارد» (decision D-27): every active user's own notifications
 * on one page in the home panel — unread first, All / Unread, open (marked
 * read, then the link followed only when it points into this site), mark one
 * read or unread, mark all read — and never another user's notification,
 * whatever id the browser sends.
 */
final class InboxTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $reader;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));

        $this->reader = $this->employee();
        $this->other = $this->employee();
    }

    #[Test]
    public function every_active_user_opens_the_inbox_and_a_guest_signs_in_first(): void
    {
        $team = $this->makeTeam();

        foreach ([$this->superAdmin(), $this->admin(), $this->salesManager($team), $this->salesRep($team), $this->support(), $this->readOnly(), $this->reader] as $user) {
            $this->actingAs($user)->get(Inbox::getUrl())->assertOk()->assertSee(__('inbox.pages.title'));
        }

        // An account with no permission at all still has an inbox (the home panel admits every active user).
        $this->actingAs(User::factory()->create())->get(Inbox::getUrl())->assertOk();

        $this->assertSame(WorkspacePanel::HOME, WorkspacePanelRegistry::owningPanelId(Inbox::class));

        auth()->logout();
        $this->get(Inbox::getUrl())->assertRedirect(route('filament.app.auth.login'));
    }

    #[Test]
    public function the_inbox_lists_only_the_readers_own_notifications_unread_first(): void
    {
        $readOld = $this->notice($this->reader, 'Read long ago', readAt: '2026-10-01 09:00:00', at: '2026-10-01 08:00:00');
        $unreadOld = $this->notice($this->reader, 'Unread from Monday', at: '2026-10-05 08:00:00');
        $readNew = $this->notice($this->reader, 'Read this morning', readAt: '2026-10-07 09:00:00', at: '2026-10-07 08:00:00');
        $unreadNew = $this->notice($this->reader, 'Unread this morning', at: '2026-10-07 09:30:00');
        $foreign = $this->notice($this->other, 'Someone else\'s notice');

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertCanSeeTableRecords([$unreadNew, $unreadOld, $readNew, $readOld], inOrder: true)
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertDontSee('Someone else')
            ->set('activeTab', Inbox::TAB_UNREAD)
            ->assertCanSeeTableRecords([$unreadNew, $unreadOld], inOrder: true)
            ->assertCanNotSeeTableRecords([$readNew, $readOld, $foreign]);

        $this->assertSame(2, app(InboxService::class)->unreadCount($this->reader));
        $this->assertSame(1, app(InboxService::class)->unreadCount($this->other));
    }

    #[Test]
    public function opening_a_notice_marks_it_read_and_follows_only_a_link_into_this_site(): void
    {
        $taskUrl = url('/tasks/tasks/1');
        $inside = $this->notice($this->reader, 'A comment on your task', url: $taskUrl);

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertTableActionVisible('open', $inside)
            ->callTableAction('open', $inside)
            ->assertRedirect($taskUrl)
            ->assertDispatched('databaseNotificationsSent');

        $this->assertNotNull($inside->refresh()->read_at);

        // A link off this site (a forged or stale payload) is never followed: the row only marks it read.
        $outside = $this->notice($this->reader, 'Suspicious', url: 'https://evil.example/steal');
        $protocolRelative = $this->notice($this->reader, 'Protocol relative', url: '//evil.example/steal');

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertTableActionHidden('open', $outside)
            ->assertTableActionHidden('open', $protocolRelative)
            ->callTableAction('markRead', $outside)
            ->assertNoRedirect();

        $this->assertNotNull($outside->refresh()->read_at);

        $request = Request::create(url('/app/inbox'));
        $this->assertNull(app(InboxService::class)->open($this->reader, $protocolRelative, $request));
        $this->assertSame($taskUrl, app(InboxService::class)->open($this->reader, $inside, $request));
    }

    #[Test]
    public function a_link_into_a_workspace_the_reader_may_no_longer_enter_is_not_offered(): void
    {
        // The employee holds no Sales key: a notice that leads there (sent before a role change, say)
        // must not open onto a refusal. It only marks read.
        $this->assertFalse($this->reader->can(WorkspacePanel::Sales->permission()->value), 'precondition: the employee may not enter Sales');
        $salesUrl = SalesDashboard::getUrl();
        $this->actingAs($this->reader)->get($salesUrl)->assertForbidden();

        $intoSales = $this->notice($this->reader, 'A deal you followed', url: $salesUrl);
        $intoTasks = $this->notice($this->reader, 'A task of yours', url: TasksDashboard::getUrl());

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertTableActionHidden('open', $intoSales)
            ->assertTableActionVisible('open', $intoTasks)
            ->assertTableActionVisible('markRead', $intoSales)
            // A forged open of the Sales notice marks it read and goes nowhere.
            ->call('mountTableAction', 'open', (string) $intoSales->getKey())
            ->assertNoRedirect();

        $request = Request::create(url('/app/inbox'));
        $this->assertNull(app(InboxService::class)->open($this->reader, $intoSales, $request));
        $this->assertNotNull($intoSales->refresh()->read_at);
        $this->assertFalse(app(InboxService::class)->hasLink($this->reader, $intoSales, $request));
        $this->assertTrue(app(InboxService::class)->hasLink($this->reader, $intoTasks, $request));

        // The same notice opens for a reader who may enter Sales.
        $manager = $this->salesManager();
        $forManager = $this->notice($manager, 'A deal you followed', url: $salesUrl);
        $this->assertSame($salesUrl, app(InboxService::class)->open($manager, $forManager, $request));
    }

    #[Test]
    public function a_notice_can_be_marked_read_and_unread_again(): void
    {
        $notice = $this->notice($this->reader, 'Assigned to you');

        $page = Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertTableActionVisible('markRead', $notice)
            ->assertTableActionHidden('markUnread', $notice)
            ->callTableAction('markRead', $notice);

        $this->assertNotNull($notice->refresh()->read_at);

        $page->assertTableActionHidden('markRead', $notice)
            ->callTableAction('markUnread', $notice);

        $this->assertNull($notice->refresh()->read_at);
    }

    #[Test]
    public function mark_all_read_and_the_bulk_mark_touch_only_the_readers_notifications(): void
    {
        $first = $this->notice($this->reader, 'First');
        $second = $this->notice($this->reader, 'Second');
        $foreign = $this->notice($this->other, 'Foreign');

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->callTableBulkAction('markSelectedRead', [$first])
            ->assertNotified(trans_choice('inbox.notifications.marked_read_many', 1, ['count' => 1]));

        $this->assertNotNull($first->refresh()->read_at);
        $this->assertNull($second->refresh()->read_at);

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertActionVisible('markAllRead')
            ->callAction('markAllRead')
            ->assertNotified(trans_choice('inbox.notifications.marked_read_many', 1, ['count' => 1]))
            ->assertActionHidden('markAllRead');

        $this->assertNotNull($second->refresh()->read_at);
        $this->assertNull($foreign->refresh()->read_at, 'another user\'s notice stays unread');

        // The service refuses another user's notice outright, whatever the screen sends.
        $this->expectException(AuthorizationException::class);
        app(InboxService::class)->markRead($this->reader, $foreign);
    }

    #[Test]
    public function another_users_notice_cannot_be_reached_through_the_table(): void
    {
        $foreign = $this->notice($this->other, 'Foreign', url: url('/tasks'));

        $page = Livewire::actingAs($this->reader)->test(Inbox::class);

        foreach (['open', 'markRead'] as $action) {
            $page->call('mountAction', $action, [], ['table' => true, 'recordKey' => $foreign->getKey()])
                ->assertActionNotMounted($action)
                ->assertNoRedirect();
        }

        $this->assertNull($foreign->refresh()->read_at);
        $this->assertSame(0, app(InboxService::class)->markManyRead($this->reader, [$foreign]));
        $this->assertNull($foreign->refresh()->read_at);
    }

    #[Test]
    public function the_inbox_pages_its_notifications(): void
    {
        $notices = [];

        for ($i = 0; $i < 30; $i++) {
            $notices[] = $this->notice($this->reader, 'Notice '.$i, at: Carbon::parse('2026-10-01 08:00:00')->addMinutes($i)->toDateTimeString());
        }

        $newestFirst = array_reverse($notices);

        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertCanSeeTableRecords(array_slice($newestFirst, 0, 25), inOrder: true)
            ->assertCanNotSeeTableRecords(array_slice($newestFirst, 25))
            ->call('gotoPage', 2, 'page')
            ->assertCanSeeTableRecords(array_slice($newestFirst, 25));
    }

    #[Test]
    public function an_empty_inbox_says_so_with_one_next_step(): void
    {
        Livewire::actingAs($this->reader)
            ->test(Inbox::class)
            ->assertSee(__('inbox.empty.heading'))
            ->assertSee(__('inbox.actions.preferences'))
            ->assertActionHidden('markAllRead')
            ->set('activeTab', Inbox::TAB_UNREAD)
            ->assertSee(__('inbox.empty.unread_heading'));
    }

    #[Test]
    public function a_notices_text_is_shown_escaped_and_never_runs(): void
    {
        $this->reader->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TaskCommentNotification::class,
            'data' => FilamentNotification::make()->title('Hello <script>alert(1)</script> & <b>bold</b>')->body(e('Ahmed & Co'))->getDatabaseMessage(),
        ]);

        $html = Livewire::actingAs($this->reader)->test(Inbox::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Ahmed &amp; Co', $html);
    }

    #[Test]
    public function every_panel_links_to_the_inbox_and_the_tasks_sidebar_counts_the_unread(): void
    {
        $admin = $this->admin();
        $this->notice($admin, 'One');
        $this->notice($admin, 'Two');

        // The Tasks sidebar: the workspace's own Inbox (it stays in the panel, so the
        // sidebar is kept — the owner's screenshot, 2026-10-07) with the unread count.
        $href = 'href="'.e(TasksInbox::getUrl()).'"';
        $html = (string) $this->actingAs($admin)->get(TaskResource::getUrl('index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<a\s+'.preg_quote($href, '/').'[^>]*class="fi-sidebar-item-btn"[^>]*>.*?<\/a>/s', $html, $item), 'the Inbox is a Tasks sidebar item');
        $this->assertStringContainsString('wire:navigate', $item[0], 'the Inbox swaps in place inside the workspace, no full load');
        $this->assertMatchesRegularExpression('/fi-badge.*?>\s*2\s*</s', $item[0], 'the sidebar item counts the two unread notices');

        // Every panel's user menu leads to the Inbox of that panel.
        foreach ([
            PanelUrl::workspaceScreen() => Inbox::getUrl(),
            SalesDashboard::getUrl() => SalesInbox::getUrl(),
            TasksDashboard::getUrl() => TasksInbox::getUrl(),
            PanelUrl::panelHome(WorkspacePanel::Reports) => ReportsInbox::getUrl(),
            PanelUrl::panelHome(WorkspacePanel::Admin) => AdminInbox::getUrl(),
        ] as $url => $inbox) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('href="'.e($inbox).'"', escape: false);
        }
    }

    #[Test]
    public function the_inbox_reads_in_both_languages(): void
    {
        $this->notice($this->reader, 'Hello');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $this->reader->forceFill(['locale' => $locale])->save();

            $this->actingAs($this->reader)
                ->get(Inbox::getUrl())
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', escape: false)
                ->assertSee(__('inbox.pages.title', [], $locale))
                ->assertSee(__('inbox.filters.unread', [], $locale))
                ->assertSee(__('inbox.fields.unread', [], $locale));
        }

        $this->assertNotSame(__('inbox.pages.title', [], 'ar'), __('inbox.pages.title', [], 'en'));
    }

    /** A Filament database notice for the user, as the CRM's notifications store it. */
    private function notice(User $user, string $title, ?string $url = null, ?string $readAt = null, ?string $at = null): DatabaseNotification
    {
        $notice = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TaskCommentNotification::class,
            'data' => FilamentNotification::make()
                ->title(e($title))
                ->body(e('About '.$title))
                ->actions($url === null ? [] : [Action::make('open')->label('Open')->url($url)->markAsRead()])
                ->getDatabaseMessage(),
            'read_at' => $readAt,
        ]);

        if ($at !== null) {
            $notice->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $notice;
    }
}
