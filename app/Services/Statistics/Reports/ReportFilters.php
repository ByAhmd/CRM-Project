<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Contracts\OwnedRecord;
use App\Models\Concerns\BelongsToProject;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Projects\CurrentProject;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a report is asked for (module 23, decisions D-4, D-13, D-30): the
 * period, the optional owner / team / pipeline narrowing, the project and
 * the grouping.
 *
 * Built by resolve() from the validated filter form, which is the only
 * place a viewer's input becomes a filter and the place every clamp lives:
 * - the period arrives as dates in the organisation timezone and is stored
 *   as the first and last instant of those days in the application
 *   timezone, which is what every stored date is in;
 * - an owner outside the viewer's assignable users (the resolver's own
 *   reach rule) is dropped, never widened to;
 * - a team is honoured only for viewers who see everything — for a team
 *   viewer it could only widen, so it is dropped;
 * - the project (D-30) is the one the form named while it is active,
 *   ALL_PROJECTS for every project (null here), and otherwise — the field
 *   missing, or a stale id of an inactive or unknown project — the viewer's
 *   current project, so a report never silently widens to every project;
 * - a grouping outside the report's options falls back to the default.
 *
 * scope() is the one gate every statistics query passes through: it starts
 * from RecordVisibilityResolver::visible(), narrows a project-partitioned
 * entity (one whose model carries Models\Concerns\BelongsToProject: leads,
 * contacts, accounts, deals) to the chosen project, and lets the owner and
 * team filters narrow that scope further, never past it. An entity that
 * follows its record rather than living in a project — tasks, activities —
 * ignores the project. narrow() is the owner and team filters alone, for a
 * report whose lines follow another user column than the owner — the task
 * report's assignees of a shared task (D-28) — on a query that already
 * starts from visible().
 */
final readonly class ReportFilters
{
    /** The widest period one report run may cover (two years, leap year included). */
    public const MAX_RANGE_DAYS = 731;

    /** The project option meaning every project (D-30), beside the active projects' ids. */
    public const ALL_PROJECTS = 'all';

    /**
     * @param  ?int  $projectId  The project the report reads (D-30); null for every project.
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $ownerId = null,
        public ?int $teamId = null,
        public ?int $pipelineId = null,
        public ?string $groupBy = null,
        public ?int $projectId = null,
    ) {}

    /** The current calendar month of the organisation, as the default period. */
    public static function currentMonth(string $timezone): self
    {
        $now = CarbonImmutable::now($timezone);

        return new self(
            from: self::inAppTimezone($now->startOfMonth()),
            to: self::inAppTimezone($now->endOfMonth()),
        );
    }

    /**
     * The filters from the validated form state of a report page.
     *
     * @param  array<string, mixed>  $data  from, to (Y-m-d in the organisation timezone), owner_id, team_id, pipeline_id, project_id (an id or ALL_PROJECTS), group_by
     * @param  string  $permissionGroup  The permission group of the report's main entity (`lead`, `deal`, …).
     * @param  list<string>  $groupByOptions  The groupings the report offers; empty for a report without grouping.
     */
    public static function resolve(
        array $data,
        User $viewer,
        RecordVisibilityResolver $visibility,
        string $permissionGroup,
        string $timezone,
        array $groupByOptions = [],
        ?string $defaultGroupBy = null,
    ): self {
        $defaults = self::currentMonth($timezone);
        $from = self::date($data['from'] ?? null, $timezone)?->startOfDay();
        $to = self::date($data['to'] ?? null, $timezone)?->endOfDay();

        if ($from === null || $to === null || $to->lessThan($from)) {
            $from = null;
            $to = null;
        }

        $ownerId = self::int($data['owner_id'] ?? null);

        if ($ownerId !== null && ! $visibility->assignableUsers($viewer, $permissionGroup)->whereKey($ownerId)->exists()) {
            $ownerId = null;
        }

        $teamId = $viewer->can($permissionGroup.'.view_all') ? self::int($data['team_id'] ?? null) : null;
        $groupBy = $data['group_by'] ?? null;

        return new self(
            from: $from === null ? $defaults->from : self::inAppTimezone($from),
            to: $to === null ? $defaults->to : self::inAppTimezone($to),
            ownerId: $ownerId,
            teamId: $teamId,
            pipelineId: self::int($data['pipeline_id'] ?? null),
            groupBy: is_string($groupBy) && in_array($groupBy, $groupByOptions, true) ? $groupBy : $defaultGroupBy,
            projectId: self::project($data['project_id'] ?? null, $viewer),
        );
    }

    /**
     * The viewer's visible records of the query's entity, inside the chosen
     * project when the entity lives in one (D-30), narrowed by the owner and
     * team filters. Every report query starts here.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(User $viewer, RecordVisibilityResolver $visibility, Builder $query): Builder
    {
        // visible() is the gate and it refuses anything that is not an
        // OwnedRecord, so every report entity is owner-scoped by the time
        // the owner and team filters narrow it further.
        $query = $visibility->visible($viewer, $query);
        $model = $query->getModel();
        assert($model instanceof OwnedRecord);

        if ($this->projectId !== null && self::isProjectPartitioned($model)) {
            $query->where($query->qualifyColumn('project_id'), $this->projectId);
        }

        return $this->narrow($viewer, $visibility, $query, $query->qualifyColumn($model::ownerColumn()), $model::permissionGroup());
    }

    /**
     * Whether the entity lives in a project (D-30): its model carries
     * BelongsToProject. Read from the trait rather than from a list of
     * classes, so a model that joins the partition is scoped the day it does.
     */
    public static function isProjectPartitioned(Model $model): bool
    {
        return in_array(BelongsToProject::class, class_uses_recursive($model), true);
    }

    /**
     * The owner and team filters on a user column of a query already inside
     * the viewer's scope: the record's owner column for scope(), or another
     * column naming the user a line is about — the assignee of each
     * assignment on the task report, where a shared task has several (D-28).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  A qualified column holding a user id.
     * @param  string  $permissionGroup  The permission group whose assignable users bound the owner filter.
     * @return Builder<TModel>
     */
    public function narrow(User $viewer, RecordVisibilityResolver $visibility, Builder $query, string $column, string $permissionGroup): Builder
    {
        if ($this->ownerId !== null) {
            // The owner filter is a subset of the assignable users; re-checking
            // it here keeps a stale or forged id from reaching past the scope.
            $query
                ->where($column, $this->ownerId)
                ->whereIn($column, $visibility->assignableUsers($viewer, $permissionGroup)->select('users.id'));
        }

        if ($this->teamId !== null) {
            $query->whereIn($column, User::query()->select('users.id')->where('users.team_id', $this->teamId));
        }

        return $query;
    }

    /**
     * Rows whose column falls inside the period.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function period(Builder $query, string $column): Builder
    {
        return $query->whereBetween($column, [$this->from, $this->to]);
    }

    /**
     * The period bounds as query bindings, for raw CASE expressions.
     *
     * @return array{string, string}
     */
    public function bounds(): array
    {
        return [$this->from->format('Y-m-d H:i:s'), $this->to->format('Y-m-d H:i:s')];
    }

    /** The number of calendar days the period covers. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The period bounds as dates in the given timezone, for the form and the file name. */
    public function fromDate(string $timezone): string
    {
        return $this->from->setTimezone($timezone)->toDateString();
    }

    public function toDate(string $timezone): string
    {
        return $this->to->setTimezone($timezone)->toDateString();
    }

    private static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($timezone);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), $timezone);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * The project filter (D-30): ALL_PROJECTS reads every project; an id is
     * honoured while it names an active project; anything else — the field
     * missing on a report that has none, or a stale id — is the viewer's
     * current project, which CurrentProject already clamps to an active one.
     * Read through the container because the resolver is a scoped singleton
     * (one answer per request) and this is a static factory.
     */
    private static function project(mixed $value, User $viewer): ?int
    {
        if ($value === self::ALL_PROJECTS) {
            return null;
        }

        $id = self::int($value);

        if ($id !== null && Project::query()->whereKey($id)->where('is_active', true)->exists()) {
            return $id;
        }

        return (int) app(CurrentProject::class)->for($viewer)->getKey();
    }

    /** Stored dates are in the application timezone, whatever the organisation displays. */
    private static function inAppTimezone(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone((string) config('app.timezone'));
    }
}
