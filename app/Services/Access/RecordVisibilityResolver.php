<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
use App\Enums\UserStatus;
use App\Enums\VisibilityLevel;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

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
 *
 * Performance: list queries keep the team as a subquery (one statement),
 * while per-record checks (a policy on every table row, every calendar
 * entry) compare against the team's member ids, read once per user per
 * request — the class is container-scoped for that reason. The memo is keyed
 * by the user and their team, and any saved, deleted or restored user clears
 * it, so a membership change inside the same request is never served stale.
 */
#[Scoped]
final class RecordVisibilityResolver
{
    /**
     * Team member ids per "userId:teamId" for the current request.
     *
     * @var array<string, list<int>>
     */
    private array $teamMembers = [];

    /**
     * The event dispatchers the memo flush is registered on, so the listeners
     * are wired once per application rather than once per resolved instance.
     *
     * @var WeakMap<Dispatcher, true>|null
     */
    private static ?WeakMap $flushRegisteredOn = null;

    public function __construct(Dispatcher $events)
    {
        self::$flushRegisteredOn ??= new WeakMap;

        if (isset(self::$flushRegisteredOn[$events])) {
            return;
        }

        self::$flushRegisteredOn[$events] = true;

        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            $events->listen('eloquent.'.$event.': '.User::class, static function (): void {
                if (app()->resolved(self::class)) {
                    app(self::class)->forgetTeamMembers();
                }
            });
        }
    }

    /** Drops the memoised team memberships (a user joined, left or was removed). */
    public function forgetTeamMembers(): void
    {
        $this->teamMembers = [];
    }

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
     * Constrain a query to the records the user may read. The query's model
     * must implement OwnedRecord.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(User $user, Builder $query): Builder
    {
        $instance = $query->getModel();

        if (! $instance instanceof OwnedRecord) {
            throw new \InvalidArgumentException(sprintf('%s does not implement %s.', $instance::class, OwnedRecord::class));
        }

        $column = $query->qualifyColumn($instance::ownerColumn());

        return match ($this->levelFor($user, $instance::class)) {
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
     * Active users the given user may assign records to: own team at team
     * level, everyone at all level, only themselves at own level. Disabled,
     * pending and deleted users are never offered or accepted as a new
     * owner, a mention or an owner filter value (D-4, D-11).
     *
     * @return Builder<User>
     */
    public function assignableUsers(User $user, string $permissionGroup): Builder
    {
        $query = User::query()
            ->whereNull('deleted_at')
            ->where('status', UserStatus::Active->value)
            ->orderBy('name');

        if ($user->can($permissionGroup.'.view_all')) {
            return $query;
        }

        if ($user->can($permissionGroup.'.view_team')) {
            return $query->whereIn('id', $this->teamMemberIds($user));
        }

        return $query->whereKey($user->getKey());
    }

    /**
     * canWrite() for many records of one model at once, as a test on the
     * owner id: the level is resolved here, once, and the team's member ids
     * are read at most once, so a long list (a calendar range) costs no
     * permission lookup per record. The test answers exactly what canWrite()
     * answers for a record with that owner; it knows nothing of the verb
     * permission or of a trashed record, which remain the policy's checks.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @return Closure(int|null): bool
     */
    public function writableOwner(User $user, string $model): Closure
    {
        $level = $this->levelFor($user, $model);

        if ($level === VisibilityLevel::Team) {
            $this->memoisedTeamMemberIds($user);
        }

        return fn (?int $ownerId): bool => $this->ownerReached($level, $user, $ownerId);
    }

    private function reaches(VisibilityLevel $level, User $user, Model&OwnedRecord $record): bool
    {
        $ownerId = $record->getAttribute($record::ownerColumn());

        return $this->ownerReached($level, $user, $ownerId === null ? null : (int) $ownerId);
    }

    private function ownerReached(VisibilityLevel $level, User $user, ?int $ownerId): bool
    {
        return match ($level) {
            VisibilityLevel::All => true,
            VisibilityLevel::Team => $ownerId === null
                || in_array($ownerId, $this->memoisedTeamMemberIds($user), true),
            VisibilityLevel::Own => $ownerId !== null && $ownerId === (int) $user->getKey(),
            VisibilityLevel::None => false,
        };
    }

    /**
     * teamMemberIds() as a list, read once per user and team per request.
     *
     * @return list<int>
     */
    private function memoisedTeamMemberIds(User $user): array
    {
        $key = $user->getKey().':'.($user->team_id ?? '');

        return $this->teamMembers[$key] ??= array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->teamMemberIds($user)->pluck('id')->all(),
        ));
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
