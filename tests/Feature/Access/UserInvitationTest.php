<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\Users\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * D-11: accounts are invite-only. The administrator never sets a password.
 */
final class UserInvitationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function inviting_a_user_creates_a_pending_account_without_a_password_and_mails_the_link(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $team = $this->makeTeam();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'سارة العتيبي',
                'email' => 'sara@example.com',
                'phone' => '0551234567',
                'locale' => 'ar',
                'roles' => [CrmRole::SalesRep->value],
                'team_id' => $team->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'sara@example.com')->firstOrFail();

        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertNull($user->password);
        $this->assertSame($team->getKey(), $user->team_id);
        $this->assertTrue($user->hasRole(CrmRole::SalesRep->value));

        Notification::assertSentTo($user, UserInvitationNotification::class, function (UserInvitationNotification $notification, array $channels) use ($user): bool {
            $mail = $notification->toMail($user);

            return $channels === ['mail']
                && str_contains((string) $mail->actionUrl, '/admin/password-reset/reset')
                && str_contains((string) $mail->actionUrl, 'sara%40example.com');
        });

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::UserInvited->value,
            'subject_id' => $user->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::UserRolesChanged->value,
            'subject_id' => $user->getKey(),
        ]);
    }

    #[Test]
    public function the_invitation_is_rendered_in_the_invitees_language(): void
    {
        $admin = $this->admin();
        $invitee = User::factory()->pending()->english()->create();

        $service = app(UserInvitationService::class);

        Notification::fake();
        $service->invite($invitee, $admin);

        Notification::assertSentTo($invitee, UserInvitationNotification::class, function (UserInvitationNotification $notification) use ($invitee): bool {
            $this->assertSame('en', $notification->locale);

            app()->setLocale('en');
            $subject = (string) $notification->toMail($invitee)->subject;
            app()->setLocale('ar');

            return str_contains($subject, 'invited');
        });
    }

    #[Test]
    public function only_pending_users_can_be_reinvited(): void
    {
        $service = app(UserInvitationService::class);

        $this->assertTrue($service->canBeInvited(User::factory()->pending()->create()));
        $this->assertFalse($service->canBeInvited(User::factory()->create()));
        $this->assertFalse($service->canBeInvited(User::factory()->disabled()->create()));
    }

    #[Test]
    public function the_resend_action_is_offered_for_pending_users_only(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $pending = User::factory()->pending()->create();
        $active = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionVisible('resendInvitation', $pending)
            ->assertTableActionHidden('resendInvitation', $active)
            ->callTableAction('resendInvitation', $pending)
            ->assertNotified(__('users.invitation.sent_title'));

        Notification::assertSentTo($pending, UserInvitationNotification::class);
        Notification::assertNotSentTo($active, UserInvitationNotification::class);
    }

    #[Test]
    public function inviting_the_email_of_a_deleted_account_asks_to_restore_it_instead(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $this->makeUser(CrmRole::SalesRep, ['email' => 'former@example.com'])->delete();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Former Colleague',
                'email' => 'former@example.com',
                'locale' => 'en',
                'roles' => [CrmRole::SalesRep->value],
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, User::withTrashed()->where('email', 'former@example.com')->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function only_a_role_administrator_invites_a_super_admin(): void
    {
        Notification::fake();

        $superAdmin = $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Shadow Owner',
                'email' => 'shadow@example.com',
                'locale' => 'en',
                'roles' => [CrmRole::SuperAdmin->value],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles.0']);

        $this->assertDatabaseMissing('users', ['email' => 'shadow@example.com']);

        Livewire::actingAs($superAdmin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Second Owner',
                'email' => 'owner@example.com',
                'locale' => 'en',
                'roles' => [CrmRole::SuperAdmin->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::query()->where('email', 'owner@example.com')->firstOrFail()->isSuperAdmin());
    }
}
