<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Completes an invitation: Pending becomes Active once the invitee sets a
 * password (D-11). Setting the password IS accepting the invitation.
 *
 * Laravel fires PasswordReset for an ordinary reset too. That is harmless: an
 * active user is left alone, and a disabled account is deliberately not
 * revived — re-enabling someone who was switched off is an administrator's
 * decision, not a side effect of a password change.
 */
final class ActivateInvitedUser
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->status !== UserStatus::Pending) {
            return;
        }

        $user->forceFill(['status' => UserStatus::Active])->save();
    }
}
