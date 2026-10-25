<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reads the notices Filament sent while a test ran: the toast queued for the
 * browser in the session, and the bell notice stored for a user. One copy for
 * every import/export test (CLAUDE.md section 3: helpers live in a concern,
 * never in copies across test classes).
 */
trait ReadsFilamentNotifications
{
    /**
     * The toast Filament queued for the browser under this title: still in the
     * session, or already claimed for the response by Filament's render hook.
     * Fails the test when there is none.
     *
     * @return array<string, mixed>
     */
    protected function sessionNotification(string $title): array
    {
        $notifications = [
            ...(array) session('filament.claimed_notifications', []),
            ...(array) session('filament.notifications', []),
        ];

        foreach ($notifications as $notification) {
            if (is_array($notification) && ($notification['title'] ?? null) === $title) {
                return $notification;
            }
        }

        $this->fail("no toast titled {$title} was sent");
    }

    /** The bell notice stored for the user under this title; fails the test when there is none. */
    protected function databaseNotification(User $user, string $title): DatabaseNotification
    {
        $notification = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->get()
            ->first(static fn (DatabaseNotification $notification): bool => ($notification->data['title'] ?? null) === $title);

        $this->assertInstanceOf(DatabaseNotification::class, $notification, "no bell notice titled {$title} for {$user->email}");

        return $notification;
    }
}
