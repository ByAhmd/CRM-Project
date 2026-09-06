<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's saved table state for one resource (decision A-8).
 *
 * The row is a snapshot of what Filament keeps in the session for a list
 * page — filter form state, sort, search, toggled columns — keyed by the
 * resource slug. It is only ever *applied* to a table whose base query is
 * already scoped by RecordVisibilityResolver (D-4), so a view can narrow what
 * a user sees but never widen it. Private unless `is_shared`; `is_default`
 * is the owner's own opening view for that resource.
 *
 * @property ?array<string, mixed> $filters
 * @property ?array<string, bool> $columns
 * @property bool $is_shared
 * @property bool $is_default
 */
#[Fillable([
    'user_id', 'resource', 'name', 'filters', 'sort_column', 'sort_direction', 'search', 'columns',
    'is_shared', 'is_default',
])]
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'is_shared' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<SavedView>  $query
     * @return Builder<SavedView>
     */
    public function scopeForResource(Builder $query, string $resource): Builder
    {
        return $query->where('saved_views.resource', $resource);
    }

    /**
     * The views a user may apply: their own plus everyone's shared ones.
     *
     * @param  Builder<SavedView>  $query
     * @return Builder<SavedView>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $nested) use ($user): void {
            $nested->where('saved_views.user_id', $user->getKey())
                ->orWhere('saved_views.is_shared', true);
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey();
    }
}
