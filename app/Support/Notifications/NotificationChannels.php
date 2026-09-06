<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;

/**
 * Which channels a notification travels on (plan section 3.6, decision D-10).
 *
 * The answer is the user's preference for the event: the in-app bell
 * (database) unless the user switched it off, and mail only when the
 * application has a real transport — never under the log or array mailers —
 * AND the user opted in for the event (mail is off by default). Every
 * notification's via() goes through here, so no class decides on its own.
 */
final class NotificationChannels
{
    /**
     * @return list<string>
     */
    public static function for(User $user, NotificationEvent $event): array
    {
        return app(NotificationPreferenceService::class)->channelsFor($user, $event);
    }

    public static function mailIsConfigured(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
    }
}
