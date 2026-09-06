<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Activity report (module 23, decision A-10): the activities logged in the
 * period, counted per kind — calls, meetings, emails, notes and everything
 * else (task completions, system entries, other) — per user, or as a time
 * series per day or per week.
 *
 * The rows are the viewer's visible activities by owner (D-4, D-13) through
 * ReportFilters::scope(); the wider "linked to a record I may read" reach
 * of the activity list is deliberately not used here, so a rep's report
 * counts what the rep logged, not what colleagues logged on shared records.
 *
 * Time buckets are computed in SQL with DATE(occurred_at): stored dates are
 * in the application timezone, which is the organisation timezone (D-8), so
 * no offset conversion is needed. Weeks are folded from the daily rows in
 * PHP on the organisation's week start (SettingsRepository), and both series
 * are zero-filled across the period.
 */
final class ActivityReport
{
    public const GROUP_OWNER = 'owner';

    public const GROUP_DAY = 'day';

    public const GROUP_WEEK = 'week';

    public const GROUP_BY = [self::GROUP_OWNER, self::GROUP_DAY, self::GROUP_WEEK];

    /** Report columns to the activity kinds they count; kinds outside the map fall into "other". */
    private const KINDS = [
        'calls' => ActivityKind::Call,
        'meetings' => ActivityKind::Meeting,
        'emails' => ActivityKind::Email,
        'notes' => ActivityKind::Note,
    ];

    private const COLORS = ['calls' => 'info', 'meetings' => 'primary', 'emails' => 'warning', 'notes' => 'gray', 'other' => 'success'];

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $groupBy = $this->groupBy($filters);
        $timeSeries = $groupBy !== self::GROUP_OWNER;
        $groupColumn = $timeSeries ? 'DATE(activities.occurred_at)' : 'activities.owner_id';

        $query = $this->query($viewer, $filters)->selectRaw("{$groupColumn} AS group_key");

        foreach (self::KINDS as $column => $kind) {
            $query->selectRaw("SUM(CASE WHEN activities.kind = ? THEN 1 ELSE 0 END) AS {$column}", [$kind->value]);
        }

        $query
            ->selectRaw('SUM(CASE WHEN activities.kind NOT IN (?, ?, ?, ?) THEN 1 ELSE 0 END) AS other', array_map(static fn (ActivityKind $kind): string => $kind->value, array_values(self::KINDS)))
            ->selectRaw('COUNT(*) AS total')
            ->groupByRaw($groupColumn);

        $aggregates = [];

        foreach ($query->toBase()->get() as $aggregate) {
            $key = $timeSeries ? (string) $aggregate->group_key : (int) ($aggregate->group_key ?? 0);

            $aggregates[$key] = [
                'calls' => (int) $aggregate->calls,
                'meetings' => (int) $aggregate->meetings,
                'emails' => (int) $aggregate->emails,
                'notes' => (int) $aggregate->notes,
                'other' => (int) $aggregate->other,
                'total' => (int) $aggregate->total,
            ];
        }

        return $timeSeries ? $this->timeRows($groupBy, $aggregates, $filters) : $this->ownerRows($aggregates);
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        return ReportRow::totals(__('reports.totals'), $rows, ['calls', 'meetings', 'emails', 'notes', 'other', 'total']);
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chart(User $viewer, ReportFilters $filters): array
    {
        return $this->chartFromRows($this->rows($viewer, $filters));
    }

    /**
     * The same chart from rows already fetched, so a page render does not
     * repeat the aggregates (D-1).
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chartFromRows(Collection $rows): array
    {
        $datasets = [];

        foreach (self::COLORS as $column => $color) {
            $datasets[] = [
                'label' => __('reports.chart.kinds.'.$column),
                'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value($column))->values()->all(),
                'color' => $color,
            ];
        }

        return [
            'labels' => $rows->map(static fn (ReportRow $row): string => $row->label)->values()->all(),
            'datasets' => $datasets,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'calls' => __('reports.columns.activity.calls'),
            'meetings' => __('reports.columns.activity.meetings'),
            'emails' => __('reports.columns.activity.emails'),
            'notes' => __('reports.columns.activity.notes'),
            'other' => __('reports.columns.activity.other'),
            'total' => __('reports.columns.activity.total'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return array_map(static fn (): string => ReportRow::FORMAT_COUNT, $this->columns());
    }

    /** The heading of the label column follows the grouping. */
    public function labelHeading(ReportFilters $filters): string
    {
        return match ($this->groupBy($filters)) {
            self::GROUP_DAY => __('reports.columns.activity.day'),
            self::GROUP_WEEK => __('reports.columns.activity.week'),
            default => __('reports.columns.activity.label'),
        };
    }

    public function groupBy(ReportFilters $filters): string
    {
        return in_array($filters->groupBy, self::GROUP_BY, true) ? $filters->groupBy : self::GROUP_OWNER;
    }

    /**
     * @return Builder<Activity>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        return $filters->period($filters->scope($viewer, $this->visibility, Activity::query()), 'activities.occurred_at');
    }

    /**
     * One line per owner with activity, by name, then the unowned entries.
     *
     * @param  array<int|string, array<string, int>>  $aggregates
     * @return Collection<int, ReportRow>
     */
    private function ownerRows(array $aggregates): Collection
    {
        $rows = new Collection;
        $ids = array_filter(array_map('intval', array_keys($aggregates)));

        foreach (User::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->get() as $owner) {
            $rows->push(new ReportRow($owner->name, $aggregates[(int) $owner->getKey()], ['owner_id' => (int) $owner->getKey()]));
        }

        if (isset($aggregates[0])) {
            $rows->push(new ReportRow(__('reports.labels.unassigned'), $aggregates[0], ['owner_id' => null]));
        }

        return $rows;
    }

    /**
     * One line per day or per week of the period, zero-filled, labelled by
     * the date (or the week's first date) in the organisation timezone as
     * the reader's calendar writes it; the ISO key stays in `meta` so the
     * series keeps sorting and exporting on it.
     *
     * @param  array<int|string, array<string, int>>  $aggregates  Keyed by Y-m-d.
     * @return Collection<int, ReportRow>
     */
    private function timeRows(string $groupBy, array $aggregates, ReportFilters $filters): Collection
    {
        $timezone = $this->settings->timezone();
        $weekStartsOn = $this->settings->weekStartsOn();
        $empty = ['calls' => 0, 'meetings' => 0, 'emails' => 0, 'notes' => 0, 'other' => 0, 'total' => 0];
        $buckets = [];

        $day = CarbonImmutable::parse($filters->fromDate($timezone), $timezone);
        $last = CarbonImmutable::parse($filters->toDate($timezone), $timezone);

        while ($day->lessThanOrEqualTo($last)) {
            $key = $groupBy === self::GROUP_WEEK ? self::weekStart($day, $weekStartsOn)->toDateString() : $day->toDateString();
            $buckets[$key] ??= $empty;

            foreach ($aggregates[$day->toDateString()] ?? [] as $column => $count) {
                $buckets[$key][$column] += $count;
            }

            $day = $day->addDay();
        }

        $rows = new Collection;

        foreach ($buckets as $key => $values) {
            $rows->push(new ReportRow(self::dayLabel((string) $key), $values, ['date' => (string) $key]));
        }

        return $rows;
    }

    /**
     * A day (or a week's first day) as the reader's calendar says it, never
     * the raw ISO key — the key stays in `meta` for sorting and the export.
     */
    public static function dayLabel(string $key): string
    {
        return CarbonImmutable::parse($key)->locale(app()->getLocale())->isoFormat('LL');
    }

    /** The first day of the week a day falls in, on the organisation's week start (0 = Sunday … 6 = Saturday). */
    private static function weekStart(CarbonImmutable $day, int $weekStartsOn): CarbonImmutable
    {
        $offset = ($day->dayOfWeek - $weekStartsOn + 7) % 7;

        return $day->subDays($offset)->startOfDay();
    }
}
