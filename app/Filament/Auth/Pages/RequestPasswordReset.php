<?php

declare(strict_types=1);

namespace App\Filament\Auth\Pages;

use App\Enums\UserStatus;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use LogicException;
use SensitiveParameter;

/**
 * Password-reset request with the invitation carve-out (D-11).
 *
 * Filament's stock page silently skips users who fail canAccessPanel(), which
 * a pending invitee does by design. They must still be able to ask for a fresh
 * link when the invitation expired, so only DISABLED accounts are refused here.
 */
final class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if (! $this->userMayRequestReset($user)) {
                    return;
                }

                if (! method_exists($user, 'notify')) {
                    throw new LogicException(sprintf('Model [%s] does not have a [notify()] method.', $user::class));
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    private function userMayRequestReset(CanResetPassword $user): bool
    {
        if ($user instanceof User) {
            return $user->status !== UserStatus::Disabled;
        }

        if ($user instanceof FilamentUser) {
            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }

        return true;
    }
}
