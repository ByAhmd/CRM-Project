<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner relation for records scoped by RecordVisibilityResolver (D-4).
 *
 * The owning model implements App\Contracts\OwnedRecord; this trait supplies
 * the default column and the relation. Tasks override ownerColumn() because
 * their owner is the assignee.
 */
trait HasOwner
{
    public static function ownerColumn(): string
    {
        return 'owner_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, static::ownerColumn());
    }

    public function isOwnedBy(User $user): bool
    {
        $ownerId = $this->getAttribute(static::ownerColumn());

        return $ownerId !== null && (int) $ownerId === (int) $user->getKey();
    }
}
