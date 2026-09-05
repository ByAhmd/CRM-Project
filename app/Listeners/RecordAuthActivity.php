<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityLogEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Security events in the audit ledger (decision A-5): sign-in, sign-out,
 * failed attempts and password resets, plus the user's last_login_at stamp.
 *
 * Failed attempts record the attempted email only when it matches an existing
 * account; unknown addresses are counted without being stored, so the ledger
 * cannot be used to enumerate what strangers typed.
 */
final class RecordAuthActivity
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'onLogin']);
        $events->listen(Logout::class, [self::class, 'onLogout']);
        $events->listen(Failed::class, [self::class, 'onFailed']);
        $events->listen(PasswordReset::class, [self::class, 'onPasswordReset']);
    }

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record(ActivityLogEvent::AuthLogin, $user, $user, [
            'subject_label' => $user->name,
            'ip' => request()->ip(),
        ]);
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->record(ActivityLogEvent::AuthLogout, $user, $user, [
            'subject_label' => $user->name,
        ]);
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->audit->record(ActivityLogEvent::AuthFailed, $user, null, [
            'subject_label' => $user->name ?? __('activity.placeholders.unknown_account'),
            'known_account' => $user !== null,
            'ip' => request()->ip(),
        ]);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->record(ActivityLogEvent::AuthPasswordReset, $user, $user, [
            'subject_label' => $user->name,
        ]);
    }
}
