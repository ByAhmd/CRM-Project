<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Projects\CurrentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The activity numbers of the dashboard (decisions D-4, D-30, A-10).
 *
 * Every query starts from the viewer's visibility scope inside the project
 * the viewer is working in — an activity has no project of its own and
 * follows the record it was logged against (Activity::inProject()) — and is
 * narrowed by the filters (owner, team). countsByKind() follows the period;
 * perDay() is a fixed trailing window of organisation days (D-8) for the
 * small bar chart. Daily buckets are folded in PHP: the activities of the
 * window are selected as rows and each occurred_at is converted from the
 * application timezone — what every stored timestamp is in — to the
 * organisation timezone before it is counted, so the days agree with
 * countsByKind() whatever timezone an administrator picks in General
 * Settings. No SQL DATE() (it would name the day in the application
 * timezone) and no CONVERT_TZ() (it needs the MySQL timezone tables, which
 * shared hosting, D-1, does not guarantee).
 */
final class ActivityMetrics
{
    public const TRAILING_DAYS = 14;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly CurrentProject $currentProject,
    ) {}

    /**
     * Activities that occurred in the period, counted per kind; every kind
     * is present, at zero when empty.
     *
     * @return array<string, int>
     */
    public function countsByKind(User $viewer, DashboardFilters $filters): array
    {
        $counts = array_fill_keys(array_map(static fn (ActivityKind $kind): string => $kind->value, ActivityKind::cases()), 0);

        $rows = $this->scoped($viewer, $filters)
            ->whereBetween('activities.occurred_at', [$filters->periodStart(), $filters->periodEnd()])
            ->selectRaw('activities.kind AS kind, COUNT(*) AS total')
            ->groupBy('activities.kind')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->kind] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Activities per day over the last N organisation days up to today,
     * oldest first, days without an activity included at zero.
     *
     * @return list<array{day: string, count: int}> day as 'Y-m-d'
     */
    public function perDay(User $viewer, DashboardFilters $filters, int $days = self::TRAILING_DAYS): array
    {
        $days = max(1, $days);
        $timezone = DashboardFilters::timezone();
        $today = Carbon::now($timezone)->startOfDay();
        $first = $today->copy()->subDays($days - 1);

        $buckets = [];

        for ($day = $first->copy(); $day->lessThanOrEqualTo($today); $day->addDay()) {
            $buckets[$day->toDateString()] = 0;
        }

        $appTimezone = (string) config('app.timezone');

        $rows = $this->scoped($viewer, $filters)
            ->whereBetween('activities.occurred_at', [
                $first->copy()->setTimezone($appTimezone),
                $today->copy()->endOfDay()->setTimezone($appTimezone),
            ])
            ->select(['activities.occurred_at'])
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = Carbon::parse((string) $row->occurred_at, $appTimezone)->setTimezone($timezone)->toDateString();

            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        $series = [];

        foreach ($buckets as $day => $count) {
            $series[] = ['day' => $day, 'count' => $count];
        }

        return $series;
    }

    /**
     * The viewer's visible activities on the records of their current project
     * (D-30), narrowed by the owner and team filters (D-4). The project is
     * read at query time from the viewer, as DashboardFilters::constrainProject()
     * does for leads and deals, so a switch is honoured on the next render.
     *
     * @return Builder<Activity>
     */
    private function scoped(User $viewer, DashboardFilters $filters): Builder
    {
        return $filters->constrainOwner(
            $this->visibility->visible($viewer, Activity::query())->inProject($this->currentProject->for($viewer)),
        );
    }
}
