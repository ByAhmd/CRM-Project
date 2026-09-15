<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation an administrator sends when creating a user (D-11).
 *
 * Carries a password-reset token rather than a bespoke invitation token:
 * Laravel's broker already generates, hashes, expires and throttles these.
 *
 * Deliberately not queued. One message sent in response to one human action
 * does not need a worker behind it, and on the shared host (D-1) the queue is
 * drained only once a minute.
 */
final class UserInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $invitedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = Filament::getPanel('admin')->getResetPasswordUrl($this->token, $notifiable);

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject(__('users.invitation.subject', ['app' => __('app.name')]))
            ->greeting(__('users.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('users.invitation.intro', ['inviter' => $this->invitedBy, 'app' => __('app.name')]))
            ->action(__('users.invitation.action'), $url)
            ->line(trans_choice('users.invitation.expiry', $minutes, ['count' => $minutes]))
            ->line(__('users.invitation.ignore'));
    }
}
