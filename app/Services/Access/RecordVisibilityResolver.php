<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
use App\Enums\VisibilityLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which owned records a user may see and change (decision D-4).
 *
 * Single source of truth for record scope. Every Policy, every Resource query,
 * global search, exporters, widgets and statistics services ask this class;
 * nothing reimplements the rules.
 *
 * Levels come from permissions on the entity (`{group}.view_any` = own,
 * `{group}.view_team` = own team, `{group}.view_all` = everything). A user
 * without even view_any reaches nothing — the query fails closed.
 *
 * Writes follow the same reach: a rep changes their own records, a manager
 * their team's, an admin any. Whether the user may perform the verb at all is
 * the policy's permission check; this class only answers "which records".
 *
 * Unowned records (owner column null) are visible from team level upwards,
 * never at own level.
 */
final readonly class RecordVisibilityResolver
{
    /**
     * @param  class-string<Model&OwnedRecord>  $model
     */
    public function levelFor(User $user, string $model): VisibilityLevel
    {
        $group = $model::permissionGroup();

        if ($user->can($group.'.view_all')) {
            return VisibilityLevel::All;
        }

        if ($user->can($group.'.view_team')) {
            return VisibilityLevel::Team;
        }

        if ($user->can($group.'.view_any')) {
            return VisibilityLevel::Own;
        }

        return VisibilityLevel::None;
    }

    /**
     * Constrain a query to the records the user may read.
     *
     * @template TModel of Model&OwnedRecord
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(User $user, Builder $query): Builder
    {
        /** @var class-string<TModel> $model */
        $model = $query->getModel()::class;
        $column = $query->qualifyColumn($model::ownerColumn());

        return match ($this->levelFor($user, $model)) {
            VisibilityLevel::All => $query,
            VisibilityLevel::Team => $query->where(function (Builder $nested) use ($user, $column): void {
                $nested->whereIn($column, $this->teamMemberIds($user))
                    ->orWhereNull($column);
            }),
            VisibilityLevel::Own => $query->where($column, $user->getKey()),
            VisibilityLevel::None => $query->whereRaw('1 = 0'),
        };
    }

    public function canRead(User $user, Model&OwnedRecord $record): bool
    {
        return $this->reaches($this->levelFor($user, $record::class), $user, $record);
    }

    public function canWrite(User $user, Model&OwnedRecord $record): bool
    {
        return $this->reaches($this->levelFor($user, $record::class), $user, $record);
    }

    /**
     * Users the given user may assign records to: own team at team level,
     * everyone at all level, only themselves at own level.
     *
     * @return Builder<User>
     */
    public function assignableUsers(User $user, string $permissionGroup): Builder
    {
        $query = User::query()->whereNull('deleted_at')->orderBy('name');

        if ($user->can($permissionGroup.'.view_all')) {
            return $query;
        }

        if ($user->can($permissionGroup.'.view_team')) {
            return $query->whereIn('id', $this->teamMemberIds($user));
        }

        return $query->whereKey($user->getKey());
    }

    private function reaches(VisibilityLevel $level, User $user, Model&OwnedRecord $record): bool
    {
        $ownerId = $record->getAttribute($record::ownerColumn());

        return match ($level) {
            VisibilityLevel::All => true,
            VisibilityLevel::Team => $ownerId === null
                || $this->teamMemberIds($user)->whereKey((int) $ownerId)->exists(),
            VisibilityLevel::Own => $ownerId !== null && (int) $ownerId === (int) $user->getKey(),
            VisibilityLevel::None => false,
        };
    }

    /**
     * The user plus every member of the user's team. A user without a team is
     * a team of one, so team level degrades to own level rather than widening.
     *
     * @return Builder<User>
     */
    private function teamMemberIds(User $user): Builder
    {
        $members = User::query()->select('id');

        if ($user->team_id === null) {
            return $members->whereKey($user->getKey());
        }

        return $members->where(function (Builder $nested) use ($user): void {
            $nested->where('team_id', $user->team_id)
                ->orWhereKey($user->getKey());
        });
    }
}
