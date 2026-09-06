<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Contracts\OwnedRecord;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a report is asked for (module 23, decisions D-4, D-13): the period,
 * the optional owner / team / pipeline narrowing and the grouping.
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
 * - a grouping outside the report's options falls back to the default.
 *
 * scope() is the one gate every statistics query passes through: it starts
 * from RecordVisibilityResolver::visible() and lets the owner and team
 * filters narrow that scope further, never past it.
 */
final readonly class ReportFilters
{
    /** The widest period one report run may cover (two years, leap year included). */
    public const MAX_RANGE_DAYS = 731;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $ownerId = null,
        public ?int $teamId = null,
        public ?int $pipelineId = null,
        public ?string $groupBy = null,
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
     * @param  array<string, mixed>  $data  from, to (Y-m-d in the organisation timezone), owner_id, team_id, pipeline_id, group_by
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
        );
    }

    /**
     * The viewer's visible records of the query's entity, narrowed by the
     * owner and team filters. Every report query starts here.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
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

        $column = $query->qualifyColumn($model::ownerColumn());

        if ($this->ownerId !== null) {
            // The owner filter is a subset of the assignable users; re-checking
            // it here keeps a stale or forged id from reaching past the scope.
            $query
                ->where($column, $this->ownerId)
                ->whereIn($column, $visibility->assignableUsers($viewer, $model::permissionGroup())->select('users.id'));
        }

        if ($this->teamId !== null) {
            $query->whereIn($column, User::query()->select('users.id')->where('users.team_id', $this->teamId));
        }

        return $query;
    }

    /**
     * Rows whose column falls inside the period.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
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

    /** Stored dates are in the application timezone, whatever the organisation displays. */
    private static function inAppTimezone(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone((string) config('app.timezone'));
    }
}
