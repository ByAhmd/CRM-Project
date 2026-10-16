<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
use App\Contracts\SharesOwnership;
use App\Contracts\TracksAssigner;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Access\UnassignableUserException;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\RecordLabel;
use Illuminate\Database\Eloquent\Builder;
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
 * import row's savepoint): a rolled-back assignment announces nothing. A
 * caller may also ask for no notice at all.
 *
 * A record several users hold (SharesOwnership, D-28: a shared task) keeps
 * its holders whole on every path that reaches here — the assign actions, the
 * owner field, an edit through TaskService:
 *
 * - a co-owner made the owner already holds the record, so they are neither
 *   re-checked against the actor's reach (a disabled colleague still holding
 *   it may move up) nor told again, and the record keeps who handed it to
 *   them — their row's assigner — rather than naming the actor; the moment
 *   it reached them stays theirs too (TaskAssignmentStampObserver), and the
 *   guard observer then removes their co-owner row;
 * - clearing the owner while co-owners remain makes the earliest of them the
 *   owner ("removing them makes the next one first"), so a shared record
 *   never drops to unowned — which would open it to every team-level reader
 *   (D-4) — while others still hold it.
 *
 * assign() returns the owner the record ends with.
 */
final class RecordAssignmentService
{
    public function __construct(
        private readonly RecordVisibilityResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Writes the record's new owner and returns the owner it ends with.
     *
     * @phpstan-impure
     */
    public function assign(Model&OwnedRecord $record, ?User $newOwner, User $actor, bool $announce = true): ?User
    {
        $column = $record::ownerColumn();
        $previousId = $record->getAttribute($column);
        $previousId = $previousId === null ? null : (int) $previousId;

        $holding = $this->coOwnerRow($record, $newOwner);

        if ($newOwner === null && $record instanceof SharesOwnership && $record->exists) {
            $holding = $this->nextCoOwnerRow($record);
            $newOwner = $holding === null ? null : User::withTrashed()->find($holding->getAttribute($record::coOwnerUserColumn()));
        }

        if ($newOwner !== null && $holding === null && ! $this->resolver->assignableUsers($actor, $record::permissionGroup())->whereKey($newOwner->getKey())->exists()) {
            throw UnassignableUserException::make();
        }

        if ($previousId === $newOwner?->getKey()) {
            return $newOwner;
        }

        DB::transaction(function () use ($record, $column, $previousId, $newOwner, $holding, $actor): void {
            $attributes = [$column => $newOwner?->getKey()];

            if ($record instanceof TracksAssigner) {
                $attributes[$record::assignerColumn()] = match (true) {
                    $newOwner === null => null,
                    $holding !== null => $this->coOwnerAssigner($record, $holding),
                    default => $actor->getKey(),
                };
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

        if ($newOwner !== null && $holding === null && $announce) {
            $this->announce($record, $newOwner, $actor);
        }

        return $newOwner;
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

    /** The user's co-owner row on a shared record (D-28): null when they do not already hold it beside its owner. */
    private function coOwnerRow(Model&OwnedRecord $record, ?User $user): ?Model
    {
        if ($user === null || ! $record instanceof SharesOwnership || ! $record->exists) {
            return null;
        }

        return $this->coOwnerRows($record)->where($record::coOwnerUserColumn(), $user->getKey())->first();
    }

    /**
     * The co-owner row of a shared record (D-28) that moves up when the owner
     * is removed: the earliest whose account is not deleted, so the record is
     * not filed under a removed account while a colleague still holds it — or
     * the earliest of all when only deleted accounts remain.
     */
    private function nextCoOwnerRow(Model&SharesOwnership $record): ?Model
    {
        $rows = $this->coOwnerRows($record);

        return $rows
            ->orderBy(User::withTrashed()
                ->selectRaw('CASE WHEN users.deleted_at IS NULL THEN 0 ELSE 1 END')
                ->whereColumn('users.id', $rows->qualifyColumn($record::coOwnerUserColumn())))
            ->orderBy($rows->getModel()->getQualifiedKeyName())
            ->first();
    }

    /**
     * The co-owner rows of a shared record, through its contract (D-28).
     *
     * @return Builder<Model>
     */
    private function coOwnerRows(Model&SharesOwnership $record): Builder
    {
        return $record::coOwnerModel()::query()->where($record::coOwnerRecordColumn(), $record->getKey());
    }

    /** Who added the co-owner (D-14): their row's assigner, when the record keeps one. */
    private function coOwnerAssigner(Model&OwnedRecord $record, Model $holding): mixed
    {
        $column = $record instanceof SharesOwnership ? $record::coOwnerAssignerColumn() : null;

        return $column === null ? null : $holding->getAttribute($column);
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
