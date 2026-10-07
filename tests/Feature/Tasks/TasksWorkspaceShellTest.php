<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Filament\Pages\AdminInbox;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\InboxPage;
use App\Filament\Pages\ReportsInbox;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SalesInbox;
use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Pages\TasksInbox;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Support\App\WebManifest;
use App\Support\Filament\PanelUrl;
use App\Support\Filament\TasksShortcuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The frame of the Tasks workspace on phones and keyboards (decision D-27):
 * the bottom tab bar (Tasks only, only the tabs the reader may open, the
 * current one marked), the keyboard shortcuts and their sheet (Tasks only,
 * N only for a reader who may add tasks), the Ctrl+K / ⌘K hint, and the web
 * manifest with its icons and head tags in every panel and on the landing
 * page — public, holding no private data, with no service worker.
 */
final class TasksWorkspaceShellTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const string MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const string ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function the_tab_bar_offers_the_tasks_tabs_the_reader_may_open_and_marks_the_current_one(): void
    {
        $employee = $this->employee();

        $home = $this->bar($this->page($employee, TasksDashboard::getUrl()));
        $this->assertSame(['my_work', 'tasks', 'add', 'board', 'inbox'], $this->tabs($home));
        $this->assertSame('my_work', $this->currentTab($home));
        $this->assertStringContainsString('href="'.e(TasksDashboard::getUrl(['action' => 'quickAdd'])).'"', $home);
        $this->assertStringContainsString(e(__('tasks.tab_bar.label')), $home);

        $this->assertSame('tasks', $this->currentTab($this->bar($this->page($employee, TaskResource::getUrl('index')))));
        $this->assertSame('board', $this->currentTab($this->bar($this->page($employee, TasksBoard::getUrl()))));

        // Every tab — «الوارد» included, the workspace's own Inbox — swaps the page in place.
        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(TaskResource::getUrl('index')), '/').'"\s+wire:navigate/', $home);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(TasksInbox::getUrl()), '/').'"\s+wire:navigate/', $home);
    }

    #[Test]
    public function the_inbox_opens_inside_the_tasks_workspace_with_its_sidebar_and_bar(): void
    {
        $employee = $this->employee();
        $employee->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Notice']]);

        // «الوارد» is the workspace's own page (/tasks/inbox): the sidebar and the phone bar stay,
        // so opening it is never a dead end (the owner's screenshot, 2026-10-07).
        $this->assertStringStartsWith(PanelUrl::panelHome(WorkspacePanel::Tasks), TasksInbox::getUrl());
        $html = $this->page($employee, TasksInbox::getUrl());

        $bar = $this->bar($html);
        $this->assertSame(['my_work', 'tasks', 'add', 'board', 'inbox'], $this->tabs($bar));
        $this->assertSame('inbox', $this->currentTab($bar));
        $this->assertStringContainsString('fi-sidebar', $html, 'the Tasks sidebar is kept on the Inbox');
        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(TasksInbox::getUrl()), '/').'"[^>]*>.*?'.preg_quote(e(__('inbox.navigation.label')), '/').'/s', $html, 'the sidebar lists «الوارد»');
        $this->assertStringContainsString('Notice', $html);

        // The workspace's Inbox follows the workspace's gate; the home panel keeps a copy for everyone.
        $outsider = $this->userWithPermissions(null, []);
        $this->actingAs($outsider)->get(TasksInbox::getUrl())->assertForbidden();
        $this->assertStringNotContainsString('class="crm-tab-bar"', $this->page($outsider, Inbox::getUrl()));

        // Other home-panel pages carry no bar.
        $this->assertStringNotContainsString('class="crm-tab-bar"', $this->page($employee, PanelUrl::workspaceScreen()));
    }

    #[Test]
    public function the_user_menu_opens_the_inbox_of_the_panel_the_reader_is_in(): void
    {
        $this->assertSame(Inbox::class, InboxPage::forPanel(WorkspacePanel::HOME));
        $this->assertSame(SalesInbox::class, InboxPage::forPanel(WorkspacePanel::Sales->id()));
        $this->assertSame(TasksInbox::class, InboxPage::forPanel(WorkspacePanel::Tasks->id()));
        $this->assertSame(ReportsInbox::class, InboxPage::forPanel(WorkspacePanel::Reports->id()));
        $this->assertSame(AdminInbox::class, InboxPage::forPanel(WorkspacePanel::Admin->id()));

        $admin = $this->admin();

        foreach ([
            SalesDashboard::getUrl() => SalesInbox::getUrl(),
            TasksDashboard::getUrl() => TasksInbox::getUrl(),
            PanelUrl::panelHome(WorkspacePanel::Reports) => ReportsInbox::getUrl(),
            PanelUrl::panelHome(WorkspacePanel::Admin) => AdminInbox::getUrl(),
            PanelUrl::workspaceScreen() => Inbox::getUrl(),
        ] as $page => $inbox) {
            $this->assertStringContainsString('href="'.e($inbox).'"', $this->page($admin, $page), "{$page}: the user menu's «الوارد» must stay in this panel");
            $this->page($admin, $inbox);
        }
    }

    #[Test]
    public function the_bars_add_tab_leaves_the_page_to_the_shortcuts_script_and_the_unread_count_runs_once(): void
    {
        $employee = $this->employee();
        $employee->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Notice']]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->page($employee, TaskResource::getUrl('index'));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // No wire:navigate on «+»: Livewire would start the page swap before the script could open quick add in place.
        $this->assertDoesNotMatchRegularExpression('/data-crm-quick-add-link[^>]*wire:navigate|wire:navigate[^>]*data-crm-quick-add-link/s', $this->bar($html));

        // The sidebar badge and the bar's «الوارد» share one count per page.
        $counts = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from `notifications`')
            && str_contains($sql, 'count(*)')
            && str_contains($sql, '`read_at` is null')
            && ! str_contains($sql, 'format'));
        $this->assertCount(1, $counts, "the unread count ran more than once:\n".implode("\n", $counts));
    }

    #[Test]
    public function the_tab_bar_never_links_to_a_page_the_reader_is_refused(): void
    {
        // In the workspace without any task permission: My Work and the Inbox only.
        $bare = $this->userWithPermissions(null, [Permission::WorkspaceTasks]);
        $this->assertSame(['my_work', 'inbox'], $this->tabs($this->bar($this->page($bare, TasksDashboard::getUrl()))));

        // A reader of tasks who may not create any: no «+».
        $reader = $this->userWithPermissions(null, [Permission::WorkspaceTasks, Permission::TaskViewAny]);
        $this->assertSame(['my_work', 'tasks', 'board', 'inbox'], $this->tabs($this->bar($this->page($reader, TasksDashboard::getUrl()))));
    }

    #[Test]
    public function the_tab_bar_counts_the_unread_notices(): void
    {
        $employee = $this->employee();

        foreach (range(1, 3) as $i) {
            $employee->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Notice '.$i]]);
        }

        $bar = $this->bar($this->page($employee, TasksDashboard::getUrl()));

        $this->assertMatchesRegularExpression('/crm-tab-bar-badge[^>]*>3</', $bar);
        $this->assertStringContainsString(e(trans_choice('tasks.tab_bar.unread', 3, ['count' => 3])), $bar);
    }

    #[Test]
    public function the_tab_bar_and_the_shortcuts_live_in_the_tasks_workspace_only(): void
    {
        $admin = $this->admin();

        $tasks = $this->page($admin, TasksDashboard::getUrl());
        $this->assertStringContainsString('class="crm-tab-bar"', $tasks);
        $this->assertStringContainsString('id="crm-shortcuts-sheet"', $tasks);

        foreach ([SalesDashboard::getUrl(), PanelUrl::panelHome(WorkspacePanel::Reports), PanelUrl::panelHome(WorkspacePanel::Admin), PanelUrl::workspaceScreen(), Inbox::getUrl()] as $url) {
            $html = $this->page($admin, $url);

            $this->assertStringNotContainsString('crm-tab-bar', $html, "{$url} draws the Tasks phone bar");
            $this->assertStringNotContainsString('crm-shortcuts', $html, "{$url} carries the Tasks shortcuts");
        }
    }

    #[Test]
    public function quick_add_by_keyboard_is_offered_only_to_a_reader_who_may_add_tasks(): void
    {
        $employee = $this->employee();
        $html = $this->page($employee, TaskResource::getUrl('index'));

        $this->assertStringContainsString('data-quick-add-url="'.e(TasksDashboard::getUrl(['action' => 'quickAdd'])).'"', $html);
        $this->assertStringContainsString(e(__('tasks.shortcuts.quick_add')), $html);
        $this->assertStringContainsString('data-navigate-once', $html, 'the listener binds once across SPA navigation');
        $this->assertStringContainsString("event.code === 'KeyN'", $html, 'physical keys: N works on the Arabic layout');
        $this->assertStringContainsString('isTyping(event.target)', $html, 'keys typed into a field are ignored');
        $this->assertStringContainsString('data-crm-shortcuts-toggle', $html, 'single-key shortcuts can be switched off');

        $reader = $this->userWithPermissions(null, [Permission::WorkspaceTasks, Permission::TaskViewAny]);
        $readOnly = $this->page($reader, TaskResource::getUrl('index'));

        $this->assertStringContainsString('id="crm-shortcuts"', $readOnly);
        $this->assertStringNotContainsString('data-quick-add-url', $readOnly);
        $this->assertStringNotContainsString(e(__('tasks.shortcuts.quick_add')), $readOnly);
        $this->assertNull(TasksShortcuts::quickAddUrl($reader));
        $this->assertNull(TasksShortcuts::quickAddUrl(null));
    }

    #[Test]
    public function the_search_hint_names_the_key_the_platform_has(): void
    {
        $this->assertSame(__('tasks.shortcuts.search_hint'), TasksShortcuts::searchHint(self::WINDOWS));
        $this->assertSame(__('tasks.shortcuts.search_hint_mac'), TasksShortcuts::searchHint(self::MAC));
        $this->assertNull(TasksShortcuts::searchHint(self::IPHONE), 'a phone has no such key');
        $this->assertNull(TasksShortcuts::searchHint(self::ANDROID));
        $this->assertNull(TasksShortcuts::searchHint(null));

        $admin = $this->admin();

        $windows = (string) $this->actingAs($admin)->withHeader('User-Agent', self::WINDOWS)->get(TaskResource::getUrl('index'))->getContent();
        $this->assertStringContainsString('x-mousetrap.global.mod-k=', $windows, 'one binding: Cmd on a Mac, Ctrl elsewhere');
        $this->assertMatchesRegularExpression('/fi-input-wrp-suffix[^>]*>\s*(<[^>]+>\s*)*'.preg_quote(__('tasks.shortcuts.search_hint'), '/').'/', $windows);

        $mac = (string) $this->actingAs($admin)->withHeader('User-Agent', self::MAC)->get(TaskResource::getUrl('index'))->getContent();
        $this->assertStringContainsString(__('tasks.shortcuts.search_hint_mac'), $mac);

        // The Sales workspace keeps its search as it was (owner: the Sales side stays as it is).
        $sales = (string) $this->actingAs($admin)->withHeader('User-Agent', self::WINDOWS)->get(SalesDashboard::getUrl())->getContent();
        $this->assertStringContainsString('x-mousetrap.global.command-k.ctrl-k=', $sales);
    }

    #[Test]
    public function the_shortcut_sheet_is_reachable_from_the_tasks_user_menu(): void
    {
        $html = $this->page($this->employee(), TasksDashboard::getUrl());

        $this->assertStringContainsString(e(__('tasks.shortcuts.title')), $html);
        $this->assertStringContainsString('window.crmOpenShortcutSheet', $html);
    }

    #[Test]
    public function the_web_manifest_is_public_named_and_holds_nothing_private(): void
    {
        $response = $this->get(route('manifest'))->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        // Publicly cacheable, so it opens no session and sets no cookie (a browser fetches it without credentials).
        $this->assertSame([], $response->headers->getCookies(), 'the manifest sets no cookie');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'the security headers still apply');

        $manifest = $response->json();
        $this->assertIsArray($manifest);
        $this->assertSame(['id', 'name', 'short_name', 'description', 'lang', 'dir', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'], array_keys($manifest));
        $this->assertSame(__('app.name', [], 'ar'), $manifest['name']);
        $this->assertSame(__('app.short_name', [], 'ar'), $manifest['short_name']);
        $this->assertLessThanOrEqual(12, mb_strlen($manifest['short_name']), 'the label under the icon fits a launcher');
        $this->assertSame('ar', $manifest['lang']);
        $this->assertSame('rtl', $manifest['dir']);
        $this->assertSame(PanelUrl::workspaceScreen(), $manifest['start_url'], 'the app starts at the workspace screen');
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#0e7490', $manifest['theme_color']);

        $purposes = [];

        foreach ($manifest['icons'] as $icon) {
            $file = public_path(Str::after($icon['src'], url('/').'/'));
            $this->assertFileExists($file);
            $size = getimagesize($file);
            $this->assertIsArray($size);
            $this->assertSame($icon['sizes'], $size[0].'x'.$size[1]);
            $this->assertSame('image/png', $size['mime']);
            $purposes[] = $icon['purpose'];
        }

        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes);
        $this->assertSame([180, 180], array_slice((array) getimagesize(public_path(WebManifest::APPLE_TOUCH_ICON)), 0, 2));

        // Signed in, it is the same document: no name, address or record of the user.
        $user = $this->makeUser(CrmRole::Employee, ['name' => 'Private Person', 'email' => 'private.person@example.com']);
        $signedIn = (string) $this->actingAs($user)->get(route('manifest'))->getContent();

        $this->assertStringNotContainsString('Private Person', $signedIn);
        $this->assertStringNotContainsString('private.person', $signedIn);
    }

    #[Test]
    public function every_panel_and_the_landing_page_carry_the_install_tags_and_no_service_worker(): void
    {
        $admin = $this->admin();
        $pages = [
            'landing' => (string) $this->get('/')->getContent(),
            'sign in' => (string) $this->get(route('filament.app.auth.login'))->getContent(),
        ];

        foreach ([PanelUrl::workspaceScreen(), SalesDashboard::getUrl(), TasksDashboard::getUrl(), PanelUrl::panelHome(WorkspacePanel::Reports), PanelUrl::panelHome(WorkspacePanel::Admin), Inbox::getUrl()] as $url) {
            $pages[$url] = $this->page($admin, $url);
        }

        foreach ($pages as $where => $html) {
            $this->assertStringContainsString('<link rel="manifest" href="'.e(route('manifest')).'" />', $html, "{$where}: the manifest link");
            $this->assertStringContainsString('<meta name="theme-color" media="(prefers-color-scheme: light)" content="'.WebManifest::THEME_COLOUR.'" />', $html, "{$where}: the theme colour");
            $this->assertStringContainsString('<link rel="apple-touch-icon" href="'.e(asset(WebManifest::APPLE_TOUCH_ICON)).'" />', $html, "{$where}: the home-screen icon");
            $this->assertStringNotContainsString('serviceWorker', $html, "{$where}: no service worker is registered");
        }

        $this->assertFileDoesNotExist(public_path('sw.js'));
        $this->assertFileDoesNotExist(public_path('service-worker.js'));
    }

    private function page(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    private function bar(string $html): string
    {
        $this->assertSame(1, preg_match('/<nav class="crm-tab-bar".*?<\/nav>/s', $html, $match), 'the Tasks phone bar is drawn');

        return $match[0];
    }

    /**
     * @return list<string>
     */
    private function tabs(string $bar): array
    {
        preg_match_all('/data-tab="([a-z_]+)"/', $bar, $matches);

        return $matches[1];
    }

    private function currentTab(string $bar): ?string
    {
        preg_match_all('/<a\b[^>]*>/s', $bar, $links);

        foreach ($links[0] as $link) {
            if (str_contains($link, 'aria-current="page"') && preg_match('/data-tab="([a-z_]+)"/', $link, $tab) === 1) {
                return $tab[1];
            }
        }

        return null;
    }
}
