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
 * out. Every answer is null or a user that can be notified, so the workflows
 * stay free of recipient arithmetic.
 */
final class NotificationRecipients
{
    /** The record's owner, unless the owner is the actor or the record is unowned. */
    public function ownerOf(Model&OwnedRecord $record, User $actor): ?User
    {
        $owner = $this->resolveOwner($record);

        if ($owner === null || $owner->is($actor)) {
            return null;
        }

        return $owner;
    }

    /** The owner's team manager, unless there is none or it is the actor or the owner. */
    public function managerOf(?User $owner, User $actor): ?User
    {
        $manager = $owner?->team?->manager;

        if (! $manager instanceof User || $manager->is($actor) || $manager->is($owner)) {
            return null;
        }

        return $manager;
    }

    /**
     * The owner and the owner's team manager, without duplicates and without the actor.
     *
     * @return list<User>
     */
    public function ownerAndManagerOf(Model&OwnedRecord $record, User $actor): array
    {
        $owner = $this->resolveOwner($record);
        $recipients = [];

        if ($owner !== null && ! $owner->is($actor)) {
            $recipients[] = $owner;
        }

        $manager = $this->managerOf($owner, $actor);

        if ($manager !== null) {
            $recipients[] = $manager;
        }

        return $recipients;
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
