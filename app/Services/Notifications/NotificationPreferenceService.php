<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\ActivityLogEvent;
use App\Enums\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A user's notification channels per event (plan section 3.6, decision D-10).
 *
 * The matrix is the full picture: one entry per NotificationEvent, filled
 * with the enum defaults (bell on, mail off) wherever the user has no row.
 * Only rows that differ from what is stored are written, and every change
 * is audited on the user with the entries that moved. channelsFor() is the
 * single answer every notification's via() relies on: the bell when the
 * preference allows it, mail only when a real transport exists AND the user
 * opted in.
 */
final class NotificationPreferenceService
{
    private const CHANNEL_DATABASE = 'database';

    private const CHANNEL_MAIL = 'mail';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, array{database: bool, mail: bool}>
     */
    public function matrixFor(User $user): array
    {
        $rows = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy(static fn (NotificationPreference $row): string => $row->event->value);

        $matrix = [];

        foreach (NotificationEvent::cases() as $event) {
            $row = $rows->get($event->value);

            $matrix[$event->value] = [
                self::CHANNEL_DATABASE => $row instanceof NotificationPreference ? $row->database : $event->databaseByDefault(),
                self::CHANNEL_MAIL => $row instanceof NotificationPreference ? $row->mail : $event->mailByDefault(),
            ];
        }

        return $matrix;
    }

    /**
     * @return list<string>
     */
    public function channelsFor(User $user, NotificationEvent $event): array
    {
        $row = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('event', $event->value)
            ->first();

        $database = $row instanceof NotificationPreference ? $row->database : $event->databaseByDefault();
        $mail = $row instanceof NotificationPreference ? $row->mail : $event->mailByDefault();

        $channels = [];

        if ($database) {
            $channels[] = self::CHANNEL_DATABASE;
        }

        if ($mail && NotificationChannels::mailIsConfigured()) {
            $channels[] = self::CHANNEL_MAIL;
        }

        return $channels;
    }

    /**
     * Stores the given matrix for the user. Every key must be a
     * NotificationEvent value and every entry two booleans; events left out
     * of the matrix keep what they have.
     *
     * @param  array<array-key, mixed>  $matrix  event value => ['database' => bool, 'mail' => bool]
     */
    public function update(User $user, array $matrix, User $actor): void
    {
        $clean = $this->validate($matrix);

        DB::transaction(function () use ($user, $clean, $actor): void {
            $current = $this->matrixFor($user);
            $changes = [];

            foreach ($clean as $event => $channels) {
                if ($current[$event] === $channels) {
                    continue;
                }

                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'event' => $event],
                    $channels,
                );

                $changes[$event] = $channels;
            }

            if ($changes === []) {
                return;
            }

            $this->audit->record(ActivityLogEvent::UserNotificationPreferencesUpdated, $user, $actor, [
                'subject_label' => $user->name,
                'changes' => $changes,
            ]);
        });
    }

    /**
     * @param  array<array-key, mixed>  $matrix
     * @return array<string, array{database: bool, mail: bool}>
     */
    private function validate(array $matrix): array
    {
        $clean = [];

        foreach ($matrix as $event => $channels) {
            if (! is_string($event) || NotificationEvent::tryFrom($event) === null) {
                throw new InvalidArgumentException(__('notifications.validation.unknown_event', ['event' => (string) $event]));
            }

            if (! is_array($channels)) {
                throw new InvalidArgumentException(__('notifications.validation.invalid_channel', ['event' => $event]));
            }

            foreach ([self::CHANNEL_DATABASE, self::CHANNEL_MAIL] as $channel) {
                if (! array_key_exists($channel, $channels) || ! is_bool($channels[$channel])) {
                    throw new InvalidArgumentException(__('notifications.validation.invalid_channel', ['event' => $event]));
                }
            }

            $clean[$event] = [
                self::CHANNEL_DATABASE => $channels[self::CHANNEL_DATABASE],
                self::CHANNEL_MAIL => $channels[self::CHANNEL_MAIL],
            ];
        }

        return $clean;
    }
}
