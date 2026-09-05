<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
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
            $record->forceFill([$column => $newOwner?->getKey()])->save();

            $this->audit->record($this->event($record), $record, $actor, [
                'subject_label' => $this->label($record),
                'owner_id' => $newOwner?->getKey(),
                'owner_name' => $newOwner?->name,
                'previous_owner_id' => $previousId,
                'previous_owner_name' => $previousId === null ? null : User::withTrashed()->whereKey($previousId)->value('name'),
            ]);
        });

        if ($newOwner !== null && ! $newOwner->is($actor)) {
            $newOwner->notify((new RecordAssignedNotification($record, $actor))->locale($newOwner->preferredLocale()));
        }
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
