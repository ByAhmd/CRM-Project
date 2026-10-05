<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Filament\Auth\Http\Responses\LoginResponse;
use App\Filament\Auth\Http\Responses\LogoutResponse;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\User;
use App\Support\Filament\PanelUrl;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Where signing in lands (decision D-23): the workspace screen, unless the
 * user was following a link to a page they may open. The remembered address
 * is never trusted further than that — another host, an address PHP and a
 * browser would read differently, an unknown path, a workspace, page or
 * record the user may not open falls back to the workspace screen, so the
 * login can neither be turned into an open redirect nor drop a user on a
 * 403 or a 404.
 */
final class LoginResponseTest extends TestCase
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
    public function the_crm_responses_replace_filaments(): void
    {
        $this->assertInstanceOf(LoginResponse::class, app(LoginResponseContract::class));
        $this->assertInstanceOf(LogoutResponse::class, app(LogoutResponseContract::class));
        $this->assertContains(FilamentUser::class, class_implements(User::class), 'ISS-051: without FilamentUser the panel gate is never asked');
    }

    #[Test]
    public function signing_in_without_a_followed_link_lands_on_the_workspace_screen(): void
    {
        $this->signIn($this->salesRep())->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function a_followed_link_into_an_enterable_workspace_is_honoured(): void
    {
        $intended = TasksBoard::getUrl().'?view=mine';
        session()->put('url.intended', $intended);

        $this->signIn($this->employee())->assertRedirect($intended);
    }

    #[Test]
    public function a_followed_link_into_a_locked_workspace_lands_on_the_workspace_screen(): void
    {
        session()->put('url.intended', LeadResource::getUrl());

        $this->signIn($this->employee())->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function a_followed_link_to_another_host_is_never_followed(): void
    {
        session()->put('url.intended', 'https://evil.example/'.WorkspacePanel::Tasks->path().'/tasks-board');

        $this->signIn($this->superAdmin())->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function a_followed_link_a_browser_would_read_as_another_host_is_never_followed(): void
    {
        foreach (['http://evil.example\\@localhost/tasks/tasks-board', 'http://attacker@localhost/tasks/tasks-board', "http://localhost/tasks/tasks-board\t"] as $intended) {
            session()->put('url.intended', $intended);

            $this->signIn($this->superAdmin())->assertRedirect(PanelUrl::workspaceScreen());
            Filament::auth()->logout();
        }
    }

    #[Test]
    public function a_followed_link_to_a_record_the_user_may_open_is_honoured_and_one_outside_their_scope_is_not(): void
    {
        $rep = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $mineUrl = LeadResource::getUrl('view', ['record' => $mine]).'?tab=notes';
        $theirsUrl = LeadResource::getUrl('view', ['record' => $theirs]);
        $missingUrl = LeadResource::getUrl('view', ['record' => (int) $theirs->getKey() + 1000]);

        session()->put('url.intended', $mineUrl);
        $this->signIn($rep)->assertRedirect($mineUrl);
        Filament::auth()->logout();

        foreach ([$theirsUrl, $missingUrl] as $intended) {
            session()->put('url.intended', $intended);
            $this->signIn($rep)->assertRedirect(PanelUrl::workspaceScreen());
            Filament::auth()->logout();
        }
    }

    #[Test]
    public function a_followed_link_to_a_page_the_user_may_not_open_inside_an_enterable_workspace_is_not_honoured(): void
    {
        $user = $this->userWithPermissions(null, [Permission::WorkspaceAdmin, Permission::TeamsManage]);

        session()->put('url.intended', UserResource::getUrl());
        $this->signIn($user)->assertRedirect(PanelUrl::workspaceScreen());
        Filament::auth()->logout();

        session()->put('url.intended', UserResource::getUrl());
        $this->signIn($this->admin())->assertRedirect(UserResource::getUrl());
    }

    #[Test]
    public function a_followed_attachment_link_is_honoured_for_a_reader_who_may_download_it(): void
    {
        $rep = $this->salesRep();
        $own = Attachment::factory()->forSubject(Lead::factory()->create(['owner_id' => $rep->getKey()]))->create();
        $foreign = Attachment::factory()->forSubject(Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]))->create();

        session()->put('url.intended', $own->downloadUrl());
        $this->signIn($rep)->assertRedirect($own->downloadUrl());
        Filament::auth()->logout();

        session()->put('url.intended', $foreign->downloadUrl());
        $this->signIn($rep)->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function a_followed_backup_link_is_honoured_for_the_super_admin_only(): void
    {
        $link = route('backups.download', ['backup' => '20261001-220000', 'file' => 'database']);

        session()->put('url.intended', $link);
        $this->signIn($this->superAdmin())->assertRedirect($link);
        Filament::auth()->logout();

        session()->put('url.intended', $link);
        $this->signIn($this->salesRep())->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function a_followed_link_to_the_former_dashboard_leads_a_sales_user_to_the_sales_dashboard_with_its_query(): void
    {
        session()->put('url.intended', url('/'.WorkspacePanel::Admin->path()).'?filters[period]=month');
        $this->signIn($this->salesRep())->assertRedirect(PanelUrl::panelHome(WorkspacePanel::Sales).'?filters[period]=month');
        Filament::auth()->logout();

        session()->put('url.intended', url('/'.WorkspacePanel::Admin->path()));
        $this->signIn($this->employee())->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function signing_out_of_a_workspace_leads_to_the_one_sign_in_page(): void
    {
        $this->actingAs($this->salesRep())
            ->post('/'.WorkspacePanel::Sales->path().'/logout')
            ->assertRedirect('/'.WorkspacePanel::HOME.'/login');
    }

    #[Test]
    public function a_user_whose_workspace_was_revoked_can_still_sign_out_from_it(): void
    {
        $user = $this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::LeadViewAny]);
        $this->actingAs($user)->get(LeadResource::getUrl())->assertOk();

        $user->revokePermissionTo(Permission::WorkspaceSales->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh() ?? $user)->get(LeadResource::getUrl())->assertForbidden();

        $this->post('/'.WorkspacePanel::Sales->path().'/logout')
            ->assertRedirect('/'.WorkspacePanel::HOME.'/login');

        $this->assertGuest();
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function addresses(): array
    {
        return [
            'a workspace page' => ['http://localhost/sales/leads/5?tab=notes', 'sales'],
            'a workspace root' => ['http://localhost/tasks', 'tasks'],
            'the home panel' => ['http://localhost/app/notification-preferences', 'app'],
            'a relative path' => ['/reports/leads', 'reports'],
            'another host' => ['https://evil.example/sales', null],
            'a protocol-relative host' => ['//evil.example/sales', null],
            'another port' => ['http://localhost:8081/sales', null],
            'a script scheme' => ['javascript:alert(1)', null],
            'a path outside every panel' => ['http://localhost/attachments/abc', null],
            'a panel lookalike' => ['http://localhost/salesforce/leads', null],
            'dot segments' => ['http://localhost/sales/../admin', null],
            'encoded dot segments' => ['http://localhost/sales/%2e%2E/admin', null],
            'a bare word' => ['sales/leads', null],
            'a backslash before userinfo' => ['http://evil.com\\@localhost/sales', null],
            'a backslash in a relative path' => ['/\\evil.com/sales', null],
            'userinfo' => ['http://attacker@localhost/sales', null],
            'a user and password' => ['http://a:b@localhost/sales', null],
            'a tab' => ["http://localhost/sa\tles", null],
            'a line break' => ["/sales\n/leads", null],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function only_an_address_inside_a_panel_of_this_site_is_recognised(string $url, ?string $panelId): void
    {
        $this->assertSame($panelId, PanelUrl::panelIdForUrl($url, Request::create('http://localhost/app/login')));
    }

    #[Test]
    public function the_followed_address_is_rebuilt_from_its_checked_parts(): void
    {
        $request = Request::create('http://localhost/app/login');

        $this->assertSame(url('/tasks/tasks-board').'?view=mine', PanelUrl::sameSiteAddress('/tasks/tasks-board?view=mine#top', $request));
        $this->assertSame(url('/sales'), PanelUrl::sameSiteAddress('http://LOCALHOST/sales?', $request));
        $this->assertNull(PanelUrl::sameSiteAddress('http://evil.com\\@localhost/sales', $request));
    }

    private function signIn(User $user): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
    }
}
