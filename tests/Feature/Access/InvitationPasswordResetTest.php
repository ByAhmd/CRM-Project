<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\ActivityLogEvent;
use App\Enums\UserStatus;
use App\Filament\Auth\Pages\RequestPasswordReset;
use App\Filament\Auth\Pages\ResetPassword;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Accepting an invitation is setting a password (D-11): pending becomes
 * active, disabled stays locked out.
 */
final class InvitationPasswordResetTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Str0ng-Passw0rd-Example';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function a_pending_invitee_sets_a_password_and_becomes_active(): void
    {
        $invitee = User::factory()->pending()->create();
        $token = Password::broker()->createToken($invitee);

        Livewire::test(ResetPassword::class, ['email' => $invitee->email, 'token' => $token])
            ->fillForm([
                'email' => $invitee->email,
                'password' => self::NEW_PASSWORD,
                'passwordConfirmation' => self::NEW_PASSWORD,
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $invitee->refresh();

        $this->assertSame(UserStatus::Active, $invitee->status);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $invitee->password));
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AuthPasswordReset->value,
            'subject_id' => $invitee->getKey(),
        ]);
    }

    #[Test]
    public function a_disabled_account_cannot_reset_its_password(): void
    {
        $disabled = User::factory()->disabled()->create(['password' => 'Old-Password-123456']);
        $token = Password::broker()->createToken($disabled);

        Livewire::test(ResetPassword::class, ['email' => $disabled->email, 'token' => $token])
            ->fillForm([
                'email' => $disabled->email,
                'password' => self::NEW_PASSWORD,
                'passwordConfirmation' => self::NEW_PASSWORD,
            ])
            ->call('resetPassword')
            ->assertNotified();

        $disabled->refresh();

        $this->assertSame(UserStatus::Disabled, $disabled->status);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, (string) $disabled->password));
    }

    #[Test]
    public function a_pending_invitee_may_request_a_fresh_link_but_a_disabled_user_may_not(): void
    {
        Notification::fake();

        $pending = User::factory()->pending()->create();
        $disabled = User::factory()->disabled()->create();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $pending->email])
            ->call('request')
            ->assertHasNoFormErrors();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $disabled->email])
            ->call('request');

        Notification::assertSentTo($pending, ResetPasswordNotification::class);
        Notification::assertNotSentTo($disabled, ResetPasswordNotification::class);
    }

    #[Test]
    public function the_password_policy_is_enforced_on_reset(): void
    {
        $invitee = User::factory()->pending()->create();
        $token = Password::broker()->createToken($invitee);

        Livewire::test(ResetPassword::class, ['email' => $invitee->email, 'token' => $token])
            ->fillForm([
                'email' => $invitee->email,
                'password' => 'short',
                'passwordConfirmation' => 'short',
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['password']);

        $this->assertSame(UserStatus::Pending, $invitee->refresh()->status);
    }
}
