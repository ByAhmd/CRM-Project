<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\OwnedRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a record event is told to (plan section 3.6).
 *
 * The actor never hears about their own action: the owner is a recipient
 * only when someone else moved the record, and the owner's team manager only
 * when the team names one who is neither the actor nor the owner. Owners are
 * read through the record's owner column, so a removed user simply drops
 * out.
 *
 * A notification carries the record's title, amounts and a link, so a
 * recipient must be able to act on it: an account that may no longer sign in
 * (disabled, pending) is never a recipient, and neither is anyone who cannot
 * open the record — a team's named manager is not necessarily inside the
 * team's scope (D-4). Every answer is null or a user that can be notified, so
 * the workflows stay free of recipient arithmetic.
 */
final class NotificationRecipients
{
    /** The record's owner, unless the owner is the actor, cannot be notified or the record is unowned. */
    public function ownerOf(Model&OwnedRecord $record, User $actor): ?User
    {
        $owner = $this->resolveOwner($record);

        if ($owner === null || $owner->is($actor) || ! $this->mayBeTold($owner, $record)) {
            return null;
        }

        return $owner;
    }

    /**
     * The owner's team manager, unless there is none, it is the actor or the
     * owner, or it may not open the record.
     */
    public function managerOf(?User $owner, User $actor, (Model&OwnedRecord)|null $record = null): ?User
    {
        $manager = $owner?->team?->manager;

        if (! $manager instanceof User || $manager->is($actor) || $manager->is($owner)) {
            return null;
        }

        if (! $manager->status->canAuthenticate()) {
            return null;
        }

        if ($record !== null && ! $manager->can('view', $record)) {
            return null;
        }

        return $manager;
    }

    /**
     * The owner and the owner's team manager, without duplicates, without the
     * actor and without anyone who may not open the record.
     *
     * @return list<User>
     */
    public function ownerAndManagerOf(Model&OwnedRecord $record, User $actor): array
    {
        $owner = $this->resolveOwner($record);
        $recipients = [];

        if ($owner !== null && ! $owner->is($actor) && $this->mayBeTold($owner, $record)) {
            $recipients[] = $owner;
        }

        $manager = $this->managerOf($owner, $actor, $record);

        if ($manager !== null) {
            $recipients[] = $manager;
        }

        return $recipients;
    }

    /** An account that may sign in and may open the record. */
    private function mayBeTold(User $recipient, Model&OwnedRecord $record): bool
    {
        return $recipient->status->canAuthenticate() && $recipient->can('view', $record);
    }

    private function resolveOwner(Model&OwnedRecord $record): ?User
    {
        $ownerId = $record->getAttribute($record::ownerColumn());

        if ($ownerId === null) {
            return null;
        }

        return User::query()->find((int) $ownerId);
    }
}
