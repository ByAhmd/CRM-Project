<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\ActivityLogEvent;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Password;

/**
 * Sends the invitation that lets a newly created user set their own password
 * (decision D-11: invite-only accounts).
 *
 * The administrator supplies name, email, roles and team; the invitee supplies
 * the credential. Until they do, the account is Pending and cannot sign in.
 * The token comes from Laravel's password broker, so expiry and throttling are
 * the framework's concern and nothing here stores a secret.
 */
final class UserInvitationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue (or re-issue) an invitation. The broker replaces any outstanding
     * token for the address, so an earlier link stops working.
     */
    public function invite(User $user, ?User $invitedBy = null): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify((new UserInvitationNotification(
            token: $token,
            invitedBy: $invitedBy->name ?? (string) config('app.name'),
        ))->locale($user->preferredLocale()));

        $this->audit->record(ActivityLogEvent::UserInvited, $user, $invitedBy, [
            'subject_label' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * Only a user who has not yet accepted may be (re)invited. Re-inviting an
     * active user would invalidate a password reset they legitimately
     * requested, and a disabled account must not be handed a route back in.
     */
    public function canBeInvited(User $user): bool
    {
        return $user->status === UserStatus::Pending;
    }
}
