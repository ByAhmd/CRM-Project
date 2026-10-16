<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\OwnedRecord;
use App\Contracts\SharesOwnership;
use App\Enums\UserStatus;
use App\Enums\VisibilityLevel;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionRolledBack;
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
 *
 * A record that SharesOwnership (a shared task, D-28) is reached through any
 * of its owners: the owner column or a row of its co-owner model naming the
 * user (own level) or a member of the user's team (team level). Lists keep
 * that as one more subquery; per-record checks read, once per user, level and
 * request, the keys of the records shared within the user's reach. That memo
 * is dropped whenever a co-owner row is written or deleted, whenever a
 * transaction rolls back and — since at team level it is read through the
 * team's members — with the membership memo above (any saved, deleted or
 * restored user), so a check never trusts a sharing or a membership the
 * database no longer holds, and agrees with visible() within the request.
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
     * Per "model:level:userId:teamId", the keys of the shared records reached
     * through a co-owner, as keys.
     *
     * @var array<string, array<int, true>>
     */
    private array $coOwned = [];

    /**
     * Per event dispatcher, the co-owner models whose writes already flush
     * the memo above.
     *
     * @var WeakMap<Dispatcher, array<class-string, true>>|null
     */
    private static ?WeakMap $coOwnerFlushRegisteredOn = null;

    /**
     * The event dispatchers the memo flush is registered on, so the listeners
     * are wired once per application rather than once per resolved instance.
     *
     * @var WeakMap<Dispatcher, true>|null
     */
    private static ?WeakMap $flushRegisteredOn = null;

    public function __construct(private readonly Dispatcher $events)
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

        $events->listen(TransactionRolledBack::class, static function (): void {
            if (app()->resolved(self::class)) {
                app(self::class)->forgetCoOwners();
            }
        });
    }

    /** Drops the memoised shared records (a co-owner row was written, deleted or rolled back). */
    public function forgetCoOwners(): void
    {
        $this->coOwned = [];
    }

    /**
     * Drops the memoised team memberships (a user joined, left or was
     * removed) and, with them, the memoised shared records (D-28): at team
     * level those are read through the team's members, so a co-owner who
     * joins, leaves or loses their account moves the answer exactly as a
     * first owner does.
     */
    public function forgetTeamMembers(): void
    {
        $this->teamMembers = [];
        $this->forgetCoOwners();
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
        $key = $query->qualifyColumn($instance->getKeyName());
        $shared = $instance instanceof SharesOwnership ? $instance : null;

        return match ($this->levelFor($user, $instance::class)) {
            VisibilityLevel::All => $query,
            VisibilityLevel::Team => $query->where(function (Builder $nested) use ($user, $column, $key, $shared): void {
                $nested->whereIn($column, $this->teamMemberIds($user))
                    ->orWhereNull($column);

                if ($shared !== null) {
                    $nested->orWhereIn($key, self::coOwnedKeys($shared, $this->teamMemberIds($user)));
                }
            }),
            VisibilityLevel::Own => $shared === null
                ? $query->where($column, $user->getKey())
                : $query->where(static function (Builder $nested) use ($user, $column, $key, $shared): void {
                    $nested->where($column, $user->getKey())
                        ->orWhereIn($key, self::coOwnedKeys($shared, [(int) $user->getKey()]));
                }),
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
     * Only users who may open the entity at all (`{group}.view_any`, through
     * a role or a direct grant) are offered: a record handed to someone who
     * cannot read it — an `employee` owning a lead (D-15) — would vanish from
     * every list, and its assignment notice would never reach them.
     *
     * @return Builder<User>
     */
    public function assignableUsers(User $user, string $permissionGroup): Builder
    {
        $viewAny = $permissionGroup.'.view_any';

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('status', UserStatus::Active->value)
            ->where(static function (Builder $holders) use ($viewAny): void {
                $holders
                    ->whereHas('permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny))
                    ->orWhereHas('roles.permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny));
            })
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
     * The users whose records of the model the user reads by ownership —
     * the people visible() reaches, as a user query: everyone (deleted
     * accounts included, their records stay readable) at all level, the
     * user and their team's current members at team level, the user alone
     * at own level, nobody without the entity. Whatever the account's
     * status: a disabled colleague's records stay in reach. The employee
     * dashboard (D-26) lists and admits employees through this, so a
     * dashboard's reach and the records behind its figures never part.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @return Builder<User>
     */
    public function visibleOwners(User $user, string $model): Builder
    {
        return match ($this->levelFor($user, $model)) {
            VisibilityLevel::All => User::withTrashed(),
            VisibilityLevel::Team => User::query()->whereIn('users.id', $this->teamMemberIds($user)),
            VisibilityLevel::Own => User::query()->whereKey($user->getKey()),
            VisibilityLevel::None => User::query()->whereRaw('1 = 0'),
        };
    }

    /**
     * canWrite() for many records of one model at once, as a test on the
     * owner id: the level is resolved here, once, and the team's member ids
     * are read at most once, so a long list (a calendar range) costs no
     * permission lookup per record. The test answers exactly what canWrite()
     * answers for a record with that owner — and, for a shared record (D-28)
     * whose key is given, with its co-owners, read once for the whole list;
     * it knows nothing of the verb permission or of a trashed record, which
     * remain the policy's checks.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @return Closure(int|null, int|null=): bool
     */
    public function writableOwner(User $user, string $model): Closure
    {
        $level = $this->levelFor($user, $model);

        if ($level === VisibilityLevel::Team) {
            $this->memoisedTeamMemberIds($user);
        }

        $shared = is_subclass_of($model, SharesOwnership::class) ? $model : null;

        return fn (?int $ownerId, ?int $recordKey = null): bool => $this->ownerReached($level, $user, $ownerId)
            || ($shared !== null && $recordKey !== null && $this->coOwnerReached($level, $user, $shared, $recordKey));
    }

    private function reaches(VisibilityLevel $level, User $user, Model&OwnedRecord $record): bool
    {
        $ownerId = $record->getAttribute($record::ownerColumn());

        if ($this->ownerReached($level, $user, $ownerId === null ? null : (int) $ownerId)) {
            return true;
        }

        return $record instanceof SharesOwnership
            && $record->exists
            && $this->coOwnerReached($level, $user, $record::class, (int) $record->getKey());
    }

    /**
     * Whether a co-owner of the shared record is the user (own level) or in
     * the user's team (team level), from the per-request memo.
     *
     * @param  class-string<SharesOwnership&Model>  $model
     */
    private function coOwnerReached(VisibilityLevel $level, User $user, string $model, int $recordKey): bool
    {
        if ($level !== VisibilityLevel::Own && $level !== VisibilityLevel::Team) {
            return $level === VisibilityLevel::All;
        }

        $this->registerCoOwnerFlush($model);

        $memoKey = $model.':'.$level->value.':'.$user->getKey().':'.($user->team_id ?? '');

        $this->coOwned[$memoKey] ??= array_fill_keys(
            array_map(
                static fn (mixed $key): int => (int) $key,
                self::coOwnedKeys(
                    new $model,
                    $level === VisibilityLevel::Own ? [(int) $user->getKey()] : $this->memoisedTeamMemberIds($user),
                )->pluck($model::coOwnerRecordColumn())->all(),
            ),
            true,
        );

        return isset($this->coOwned[$memoKey][$recordKey]);
    }

    /**
     * The keys of the shared records one of the given users co-owns, as a
     * subquery for a `whereIn` on the record's key.
     *
     * @param  Builder<User>|list<int>  $userIds
     * @return Builder<Model>
     */
    private static function coOwnedKeys(SharesOwnership $record, Builder|array $userIds): Builder
    {
        $coOwner = $record::coOwnerModel();

        return $coOwner::query()
            ->select($record::coOwnerRecordColumn())
            ->whereIn($record::coOwnerUserColumn(), $userIds);
    }

    /**
     * Flushes the shared-record memo on every write of the model's co-owner
     * rows, wired once per event dispatcher and model — before the first memo
     * of that model is read, so no memo can outlive a change.
     *
     * @param  class-string<SharesOwnership&Model>  $model
     */
    private function registerCoOwnerFlush(string $model): void
    {
        self::$coOwnerFlushRegisteredOn ??= new WeakMap;
        $registered = self::$coOwnerFlushRegisteredOn[$this->events] ?? [];
        $coOwner = $model::coOwnerModel();

        if (isset($registered[$coOwner])) {
            return;
        }

        $registered[$coOwner] = true;
        self::$coOwnerFlushRegisteredOn[$this->events] = $registered;

        foreach (['saved', 'deleted'] as $event) {
            $this->events->listen('eloquent.'.$event.': '.$coOwner, static function (): void {
                if (app()->resolved(self::class)) {
                    app(self::class)->forgetCoOwners();
                }
            });
        }
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
