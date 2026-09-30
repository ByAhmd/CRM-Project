<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\ActivityLogEvent;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
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
 * with the enum defaults wherever the user has no row — bell on, mail off,
 * except RecordAssigned, whose mail defaults to on (opt-out, D-14). Only
 * rows that differ from what is stored are written, and every change is
 * audited on the user with the entries that moved. channelsFor() is the
 * single answer every notification's via() relies on: nothing at all for an
 * account that may not sign in, otherwise the bell when the preference allows
 * it, mail only when a real transport exists AND the preference — stored, or
 * the event's default — allows it.
 *
 * Which events a user is OFFERED on the preferences page (D-19) is decided
 * here too, from one mapping of event to the permissions its notices depend
 * on: holding any one of them offers the event. A stored choice for an event
 * the user is not offered is kept untouched and update() never writes one,
 * so a user whose role regains the permission finds their old choice again.
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
        // A switched-off or not yet activated account lost panel access (D-11):
        // it receives nothing, neither the bell nor mail.
        if (! $user->status->canAuthenticate()) {
            return [];
        }

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
     * The permissions an event's notices depend on (D-19); holding any one of
     * them offers the event. The match is exhaustive, so a new event cannot be
     * added without deciding who may choose its channels.
     *
     * @return list<Permission>
     */
    public function requiredPermissions(NotificationEvent $event): array
    {
        return match ($event) {
            // Any owned entity the user may open can be handed to them.
            NotificationEvent::RecordAssigned => [
                Permission::LeadViewAny, Permission::ContactViewAny, Permission::AccountViewAny,
                Permission::DealViewAny, Permission::ActivityViewAny, Permission::TaskViewAny,
            ],
            NotificationEvent::TaskReminder,
            NotificationEvent::TaskOverdue,
            NotificationEvent::TaskCompleted,
            NotificationEvent::TaskProgress,
            NotificationEvent::TaskComment => [Permission::TaskViewAny],
            NotificationEvent::DealStageChanged,
            NotificationEvent::DealClosed => [Permission::DealViewAny],
            NotificationEvent::LeadConverted,
            NotificationEvent::LeadStale => [Permission::LeadViewAny],
            NotificationEvent::NoteMention => [Permission::NoteCreate],
            // The weekly summary goes to the people who hand out work (D-18).
            NotificationEvent::WeeklySummary => [Permission::TaskAssign],
            // A failed backup is reported to super admins (D-16).
            NotificationEvent::BackupFailed => [Permission::RolesManage],
        };
    }

    public function offers(User $user, NotificationEvent $event): bool
    {
        foreach ($this->requiredPermissions($event) as $permission) {
            if ($user->can($permission->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The events the user may choose channels for, in the enum's order.
     *
     * @return list<NotificationEvent>
     */
    public function offeredEvents(User $user): array
    {
        return array_values(array_filter(
            NotificationEvent::cases(),
            fn (NotificationEvent $event): bool => $this->offers($user, $event),
        ));
    }

    /**
     * Stores the given matrix for the user. Every key must be a
     * NotificationEvent value and every entry two booleans; events left out
     * of the matrix keep what they have, and so do events the user is not
     * offered (D-19): their keys are dropped, never written, and their stored
     * rows stay as they are.
     *
     * @param  array<array-key, mixed>  $matrix  event value => ['database' => bool, 'mail' => bool]
     */
    public function update(User $user, array $matrix, User $actor): void
    {
        $offered = array_map(
            static fn (NotificationEvent $event): string => $event->value,
            $this->offeredEvents($user),
        );

        $clean = array_intersect_key($this->validate($matrix), array_flip($offered));

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
