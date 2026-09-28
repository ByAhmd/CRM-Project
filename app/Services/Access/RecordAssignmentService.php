<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
use App\Contracts\TracksAssigner;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Access\UnassignableUserException;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\RecordLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reassigns an owned record (decision D-4).
 *
 * Whether the actor MAY reassign is the policy's `assign` verb; this service
 * guarantees the target is someone the actor could assign to (their own
 * reach), writes the ownership change to the ledger and tells the new owner.
 * A record that tracks its assigner (D-14) remembers the actor alongside the
 * new owner, and forgets them when the record is unassigned.
 *
 * The new owner is told only once the change has committed — including the
 * caller's own transaction when assign() runs inside one (a task edit, an
 * import row's savepoint): a rolled-back assignment announces nothing.
 */
final class RecordAssignmentService
{
    public function __construct(
        private readonly RecordVisibilityResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function assign(Model&OwnedRecord $record, ?User $newOwner, User $actor): void
    {
        $column = $record::ownerColumn();
        $previousId = $record->getAttribute($column);
        $previousId = $previousId === null ? null : (int) $previousId;

        if ($newOwner !== null && ! $this->resolver->assignableUsers($actor, $record::permissionGroup())->whereKey($newOwner->getKey())->exists()) {
            throw UnassignableUserException::make();
        }

        if ($previousId === $newOwner?->getKey()) {
            return;
        }

        DB::transaction(function () use ($record, $column, $previousId, $newOwner, $actor): void {
            $attributes = [$column => $newOwner?->getKey()];

            if ($record instanceof TracksAssigner) {
                $attributes[$record::assignerColumn()] = $newOwner === null ? null : $actor->getKey();
            }

            $record->forceFill($attributes)->save();

            $this->audit->record($this->event($record), $record, $actor, [
                'subject_label' => $this->label($record),
                'owner_id' => $newOwner?->getKey(),
                'owner_name' => $newOwner?->name,
                'previous_owner_id' => $previousId,
                'previous_owner_name' => $previousId === null ? null : User::withTrashed()->whereKey($previousId)->value('name'),
            ]);
        });

        if ($newOwner !== null) {
            $this->announce($record, $newOwner, $actor);
        }
    }

    /**
     * Tells an owner that the actor handed them the record, once the write
     * that did so commits. Nobody is told about a record they gave
     * themselves. Besides assign(), TaskService calls this for a task created
     * for someone else: under D-14 that is how an administrator hands out
     * work, so the assignee hears of it exactly as of a reassignment, while
     * the ledger still records a creation (A-20).
     */
    public function announce(Model&OwnedRecord $record, User $owner, User $actor): void
    {
        if ($owner->is($actor)) {
            return;
        }

        DB::afterCommit(static function () use ($record, $owner, $actor): void {
            $owner->notify((new RecordAssignedNotification($record, $actor))->locale($owner->preferredLocale()));
        });
    }

    private function event(Model&OwnedRecord $record): ActivityLogEvent
    {
        return ActivityLogEvent::from($record::permissionGroup().'.assigned');
    }

    private function label(Model&OwnedRecord $record): string
    {
        return RecordLabel::of($record);
    }
}
