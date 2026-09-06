<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Contracts\OwnedRecord;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The dashboard filters as the statistics services read them (decision D-4).
 *
 * Built from the page's filters form through fromArray(), which never
 * widens the viewer's reach: an owner outside assignableUsers() is ignored,
 * a team is honoured only when the viewer sees every deal, an inactive or
 * unknown pipeline falls back to "all", and an unusable period (missing,
 * malformed, reversed, or wider than MAX_RANGE_DAYS) falls back to the
 * current month. The period is held in the organisation timezone (D-8) —
 * the whole first day to the whole last day — and periodStart() /
 * periodEnd() hand the bounds to queries in the application timezone,
 * which is what every stored timestamp is in.
 */
final readonly class DashboardFilters
{
    /** The widest period the dashboard aggregates: a year plus a leap day. */
    public const MAX_RANGE_DAYS = 366;

    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?int $ownerId = null,
        public ?int $pipelineId = null,
        public ?int $teamId = null,
    ) {}

    /**
     * The filters form state, sanitised for the viewer.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromArray(?array $filters, User $viewer): self
    {
        $filters ??= [];
        $timezone = self::timezone();
        [$from, $to] = self::period($filters['from'] ?? null, $filters['to'] ?? null, $timezone);

        return new self(
            from: $from,
            to: $to,
            ownerId: self::ownerWithinReach(self::int($filters['owner_id'] ?? null), $viewer),
            pipelineId: self::activePipeline(self::int($filters['pipeline_id'] ?? null)),
            teamId: self::teamWithinReach(self::int($filters['team_id'] ?? null), $viewer),
        );
    }

    /** The current month of the organisation, no owner, team or pipeline. */
    public static function currentMonth(): self
    {
        [$from, $to] = self::defaultPeriod(self::timezone());

        return new self($from, $to);
    }

    /** The first moment of the period, in the application timezone, for queries. */
    public function periodStart(): Carbon
    {
        return self::inAppTimezone($this->from);
    }

    /** The last moment of the period, in the application timezone, for queries. */
    public function periodEnd(): Carbon
    {
        return self::inAppTimezone($this->to);
    }

    /**
     * Narrow an already visibility-scoped query to the chosen owner and team.
     * Only ever narrows: the owner and the team were clamped to the viewer's
     * reach when the filters were built.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model&OwnedRecord
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrainOwner(Builder $query): Builder
    {
        $model = $query->getModel();
        $column = $query->qualifyColumn($model::ownerColumn());

        if ($this->ownerId !== null) {
            $query->where($column, $this->ownerId);
        }

        if ($this->teamId !== null) {
            $query->whereIn($column, User::query()->select('id')->where('team_id', $this->teamId));
        }

        return $query;
    }

    /**
     * Narrow a deal query to the chosen pipeline, when one was chosen.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function constrainPipeline(Builder $query): Builder
    {
        if ($this->pipelineId !== null) {
            $query->where($query->qualifyColumn('pipeline_id'), $this->pipelineId);
        }

        return $query;
    }

    /** The period's length in whole days, both ends inclusive. */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /** The organisation timezone the period is expressed in (D-8). */
    public static function timezone(): string
    {
        return app(SettingsRepository::class)->timezone();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function period(mixed $from, mixed $to, string $timezone): array
    {
        $start = self::date($from, $timezone);
        $end = self::date($to, $timezone);

        if ($start === null || $end === null) {
            return self::defaultPeriod($timezone);
        }

        $start = $start->startOfDay();
        $end = $end->endOfDay();

        if ($end->lessThan($start) || $start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            return self::defaultPeriod($timezone);
        }

        return [$start, $end];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function defaultPeriod(string $timezone): array
    {
        $now = Carbon::now($timezone);

        return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }

    private static function date(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), $timezone);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private static function ownerWithinReach(?int $ownerId, User $viewer): ?int
    {
        if ($ownerId === null) {
            return null;
        }

        $reachable = app(RecordVisibilityResolver::class)
            ->assignableUsers($viewer, Deal::permissionGroup())
            ->whereKey($ownerId)
            ->exists();

        return $reachable ? $ownerId : null;
    }

    /** A team filter is meaningful for a viewer who sees every deal; anyone else is already inside one team. */
    private static function teamWithinReach(?int $teamId, User $viewer): ?int
    {
        if ($teamId === null || ! $viewer->can(Deal::permissionGroup().'.view_all')) {
            return null;
        }

        return Team::query()->whereKey($teamId)->exists() ? $teamId : null;
    }

    private static function activePipeline(?int $pipelineId): ?int
    {
        if ($pipelineId === null) {
            return null;
        }

        return Pipeline::query()->whereKey($pipelineId)->where('is_active', true)->exists() ? $pipelineId : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function inAppTimezone(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone((string) config('app.timezone'));
    }
}
