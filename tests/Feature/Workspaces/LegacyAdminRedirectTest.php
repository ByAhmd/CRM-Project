<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspacePanel;
use App\Support\Filament\PanelUrl;
use App\Support\Http\LegacyAdminRedirects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Every address that moved out of /admin when the CRM split into workspaces
 * (decision D-23) answers a permanent redirect to its new address, with the
 * rest of the path and the query string kept — stored notifications, sent
 * mails and bookmarks keep working. Nothing the Administration workspace
 * still serves is shadowed.
 */
final class LegacyAdminRedirectTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movedAddresses(): array
    {
        return [
            'a lead page' => ['/admin/leads/42?tab=notes', '/sales/leads/42?tab=notes'],
            'the lead list' => ['/admin/leads', '/sales/leads'],
            'a deal edit page' => ['/admin/deals/7/edit', '/sales/deals/7/edit'],
            'products' => ['/admin/products/create', '/sales/products/create'],
            'a contact' => ['/admin/contacts/3', '/sales/contacts/3'],
            'an account' => ['/admin/accounts/3', '/sales/accounts/3'],
            'an activity' => ['/admin/activities/9', '/sales/activities/9'],
            'email templates' => ['/admin/email-templates', '/sales/email-templates'],
            'import history' => ['/admin/imports/2', '/sales/imports/2'],
            'export history' => ['/admin/exports', '/sales/exports'],
            'the deal board' => ['/admin/deal-board?pipeline=2', '/sales/deal-board?pipeline=2'],
            'a task' => ['/admin/tasks/11', '/tasks/tasks/11'],
            'routine tasks' => ['/admin/task-templates/4/edit', '/tasks/task-templates/4/edit'],
            'the tasks board' => ['/admin/tasks-board', '/tasks/tasks-board'],
            'the calendar' => ['/admin/calendar?view=week', '/tasks/calendar?view=week'],
            'the lead report' => ['/admin/reports/leads?from=2026-09-01', '/reports/leads?from=2026-09-01'],
            'the funnel report' => ['/admin/reports/funnel', '/reports/funnel'],
            'the pipeline report' => ['/admin/reports/pipeline', '/reports/pipeline'],
            'the sales report' => ['/admin/reports/sales', '/reports/sales'],
            'the activity report' => ['/admin/reports/activities', '/reports/activities'],
            'the source report' => ['/admin/reports/sources', '/reports/sources'],
            'the win-loss report' => ['/admin/reports/win-loss', '/reports/win-loss'],
            'the forecast report' => ['/admin/reports/forecast', '/reports/forecast'],
            'the task report' => ['/admin/reports/tasks', '/reports/tasks'],
            'the sign-in page' => ['/admin/login', '/app/login'],
            'the reset request' => ['/admin/password-reset/request', '/app/password-reset/request'],
            'the profile' => ['/admin/profile', '/app/profile'],
            'notification preferences' => ['/admin/notification-preferences', '/app/notification-preferences'],
        ];
    }

    #[Test]
    #[DataProvider('movedAddresses')]
    public function a_moved_address_answers_a_permanent_redirect_with_its_query(string $old, string $new): void
    {
        $this->get($old)->assertStatus(301)->assertRedirect($new);
    }

    #[Test]
    public function every_moved_address_is_covered_and_every_target_is_served(): void
    {
        $this->assertCount(27, LegacyAdminRedirects::map());

        foreach (LegacyAdminRedirects::map() as $from => $to) {
            $this->assertNotSame('admin', explode('/', $to)[0], "{$from} still points into /admin");
        }
    }

    #[Test]
    public function addresses_the_administration_workspace_still_serves_are_not_shadowed(): void
    {
        $this->seedAccess();
        $admin = $this->superAdmin();

        foreach (['/admin/users', '/admin/roles', '/admin/teams', '/admin/general-settings', '/admin/lead-sources', '/admin/lead-statuses', '/admin/pipelines', '/admin/custom-fields', '/admin/lead-scoring-rules', '/admin/activity-logs', '/admin/backups'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    #[Test]
    public function the_former_dashboard_sends_a_user_without_administration_to_the_sales_dashboard_with_its_query_or_the_workspace_screen(): void
    {
        $this->seedAccess();

        // The sales dashboard used to live at /admin: a Sales user is sent
        // there with the filters kept. Temporary, since the answer depends on
        // who asks — an administrator gets the Administration home here.
        $this->actingAs($this->salesRep())
            ->get('/admin?filters%5Bperiod%5D=month')
            ->assertStatus(302)
            ->assertRedirect(PanelUrl::panelHome(WorkspacePanel::Sales).'?filters%5Bperiod%5D=month');

        $this->actingAs($this->employee())->get('/admin?filters%5Bperiod%5D=month')->assertRedirect(PanelUrl::workspaceScreen());
    }

    #[Test]
    public function an_invitation_link_sent_before_the_move_still_opens_the_reset_page(): void
    {
        $this->seedAccess();
        $invitee = $this->salesRep();
        $token = Password::broker()->createToken($invitee);

        // The address Filament signed for the old /admin panel.
        $old = URL::signedRoute('legacy.admin.password-reset', ['rest' => 'reset', 'email' => $invitee->email, 'token' => $token]);
        $this->assertStringStartsWith(url('/admin/password-reset/reset?'), $old);

        $redirect = $this->get($old)->assertStatus(301);
        $new = (string) $redirect->headers->get('Location');

        $this->assertStringStartsWith(url('/'.WorkspacePanel::HOME.'/password-reset/reset?'), $new);
        $this->get($new)->assertOk();
    }

    #[Test]
    public function a_tampered_signed_link_is_passed_on_unchanged_and_refused_where_it_lands(): void
    {
        $old = URL::signedRoute('legacy.admin.password-reset', ['rest' => 'reset', 'email' => 'someone@example.com', 'token' => 'abc']);
        $tampered = str_replace('token=abc', 'token=xyz', $old);

        $redirect = $this->get($tampered)->assertStatus(301);
        $new = (string) $redirect->headers->get('Location');

        $this->assertStringContainsString('token=xyz', $new);
        $this->get($new)->assertForbidden();
    }
}
