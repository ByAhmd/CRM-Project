<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;

/**
 * Which channels a notification travels on (decision D-10).
 *
 * The in-app bell (database) is always on. Mail is added only when the
 * application has a real transport — never under the log or array mailers —
 * and, once per-user preferences exist (notifications step of the plan),
 * only when the user opted in for the event. Until then every user receives
 * mail for every event when a mailer is configured.
 */
final class NotificationChannels
{
    /**
     * @return list<string>
     */
    public static function for(User $user, string $event): array
    {
        $channels = ['database'];

        if (self::mailIsConfigured()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public static function mailIsConfigured(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
    }
}
