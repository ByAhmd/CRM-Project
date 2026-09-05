<?php

declare(strict_types=1);

namespace App\Filament\Auth\Pages;

use App\Enums\UserStatus;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Password reset with the invitation-acceptance carve-out (D-11).
 *
 * Filament's stock page refuses anyone who fails canAccessPanel(). Pending
 * invitees are meant to fail that check until they set a password, so
 * accepting an invitation would be impossible without this override.
 */
final class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();

        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $mayReset = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$mayReset): void {
                if (! $this->userMayCompletePasswordReset($user)) {
                    $mayReset = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($mayReset === false) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title(__($status))
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()
            ->title(__($status))
            ->danger()
            ->send();

        return null;
    }

    /** Pending invitees may set a password before they can enter the panel; everyone else keeps Filament's gate. */
    private function userMayCompletePasswordReset(CanResetPassword|Model|Authenticatable $user): bool
    {
        if ($user instanceof User && $user->status === UserStatus::Pending) {
            return true;
        }

        if ($user instanceof FilamentUser) {
            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }

        return true;
    }
}
