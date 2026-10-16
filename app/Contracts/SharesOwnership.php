<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * An owned record that more than one user may hold (decision D-28): beside
 * its owner column, each row of a co-owner model names one further owner.
 *
 * RecordVisibilityResolver reaches the record through any of its owners — at
 * the own level when the user is one of them, at the team level when one of
 * them is in the user's team — so every list, policy, export and report that
 * asks the resolver follows each holder without knowing the table. Shared
 * tasks are the one implementation (Task, TaskCoAssignee).
 */
interface SharesOwnership extends OwnedRecord
{
    /**
     * The model whose rows name the further owners.
     *
     * @return class-string<Model>
     */
    public static function coOwnerModel(): string;

    /** The co-owner model's column holding the record's key. */
    public static function coOwnerRecordColumn(): string;

    /** The co-owner model's column holding the further owner's user id. */
    public static function coOwnerUserColumn(): string;

    /**
     * The co-owner model's column naming who added that owner (D-14 for a
     * record that TracksAssigner), carried over when RecordAssignmentService
     * makes the co-owner the record's owner; null when nobody is recorded.
     */
    public static function coOwnerAssignerColumn(): ?string;
}
