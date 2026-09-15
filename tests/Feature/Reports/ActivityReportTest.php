<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Statistics\Reports\ActivityReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Activity report (module 23): activities per kind per user, per day or
 * per week, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (September 2026, team Riyadh = manager + rep A; rep B has no team):
 * - rep A: two calls, a meeting and a note on 3 Sep; an email on 10 Sep;
 * - rep B: a call on 3 Sep; a task-completion entry on 10 Sep;
 * - rep A: a call in August (outside the period).
 */
final class ActivityReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->repA = $this->salesRep($this->team);
        $this->repB = $this->salesRep();

        $this->log($this->repA, ActivityKind::Call, '2026-09-03 09:00:00');
        $this->log($this->repA, ActivityKind::Call, '2026-09-03 11:00:00');
        $this->log($this->repA, ActivityKind::Meeting, '2026-09-03 14:00:00');
        $this->log($this->repA, ActivityKind::Note, '2026-09-03 16:00:00');
        $this->log($this->repA, ActivityKind::Email, '2026-09-10 10:00:00');
        $this->log($this->repB, ActivityKind::Call, '2026-09-03 10:00:00');
        $this->log($this->repB, ActivityKind::Task, '2026-09-10 10:00:00');
        $this->log($this->repA, ActivityKind::Call, '2026-08-28 10:00:00');
    }

    #[Test]
    public function an_admin_sees_one_line_per_user_with_the_kind_counts_and_totals(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame([$this->repA->name, $this->repB->name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(['calls' => 2, 'meetings' => 1, 'emails' => 1, 'notes' => 1, 'other' => 0, 'total' => 5], $rows->first()?->values);
        $this->assertSame(['calls' => 1, 'meetings' => 0, 'emails' => 0, 'notes' => 0, 'other' => 1, 'total' => 2], $rows->last()?->values);
        $this->assertSame(['calls' => 3, 'meetings' => 1, 'emails' => 1, 'notes' => 1, 'other' => 1, 'total' => 7], $this->report()->totals($rows)->values);
        $this->assertSame(__('reports.columns.activity.label'), $this->report()->labelHeading($this->filters()));
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_activities(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repA = $this->report()->rows($this->repA, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertSame([$this->repA->name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(5, (int) $manager->first()?->value('total'));
        $this->assertSame($manager->first()?->values, $repA->first()?->values);
        $this->assertSame([$this->repB->name], $repB->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(2, (int) $repB->first()?->value('total'));
    }

    #[Test]
    public function grouping_by_day_gives_a_zero_filled_series_over_the_period(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-10', 'group_by' => ActivityReport::GROUP_DAY]));
        $byDay = $rows->keyBy(fn (ReportRow $row): string => (string) $row->meta['date']);

        $this->assertCount(10, $rows);
        $this->assertSame(['2026-09-01', '2026-09-10'], [$rows->first()?->meta['date'], $rows->last()?->meta['date']]);
        $third = $byDay->get('2026-09-03');

        $this->assertNotNull($third);
        $this->assertSame(['calls' => 3, 'meetings' => 1, 'emails' => 0, 'notes' => 1, 'other' => 0, 'total' => 5], $third->values);
        $this->assertSame(['calls' => 0, 'meetings' => 0, 'emails' => 1, 'notes' => 0, 'other' => 1, 'total' => 2], $byDay->get('2026-09-10')?->values);
        $this->assertSame(0, (int) $byDay->get('2026-09-02')?->value('total'));
        $this->assertSame(['date' => '2026-09-03'], $third->meta);

        // The label is the reader's calendar day, never the raw ISO key.
        $this->assertSame(ActivityReport::dayLabel('2026-09-03'), $third->label);
        $this->assertNotSame('2026-09-03', $third->label);
        $this->assertSame(__('reports.columns.activity.day'), $this->report()->labelHeading($this->filters(['group_by' => ActivityReport::GROUP_DAY])));
    }

    #[Test]
    public function the_days_are_the_organisation_timezone_days_when_it_differs_from_the_application_timezone(): void
    {
        // A-19: buckets are folded in PHP in the organisation timezone, which General Settings may change (D-8).
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $this->admin);
        $this->assertNotSame((string) config('app.timezone'), app(SettingsRepository::class)->timezone(), 'precondition');

        // 20:30 on 3 Sep in Riyadh (the application timezone) is 02:30 on 4 Sep in Tokyo.
        $this->log($this->repA, ActivityKind::Call, '2026-09-03 20:30:00');

        $filters = ReportFilters::resolve(
            ['from' => '2026-09-01', 'to' => '2026-09-05', 'group_by' => ActivityReport::GROUP_DAY],
            $this->admin,
            app(RecordVisibilityResolver::class),
            Activity::permissionGroup(),
            app(SettingsRepository::class)->timezone(),
            ActivityReport::GROUP_BY,
            ActivityReport::GROUP_OWNER,
        );

        $byDay = $this->report()->rows($this->admin, $filters)
            ->mapWithKeys(fn (ReportRow $row): array => [(string) $row->meta['date'] => (int) $row->value('total')])
            ->all();

        $this->assertSame(['2026-09-01' => 0, '2026-09-02' => 0, '2026-09-03' => 5, '2026-09-04' => 1, '2026-09-05' => 0], $byDay);
    }

    #[Test]
    public function grouping_by_week_folds_the_days_on_the_organisation_week_start(): void
    {
        $filters = $this->filters(['from' => '2026-09-01', 'to' => '2026-09-10', 'group_by' => ActivityReport::GROUP_WEEK]);
        $rows = $this->report()->rows($this->admin, $filters);

        // Weeks start on Sunday (the seeded default): 1 Sep 2026 is a Tuesday.
        $this->assertSame(['2026-08-30', '2026-09-06'], $rows->map(fn (ReportRow $row): string => (string) $row->meta['date'])->all());
        $this->assertSame([ActivityReport::dayLabel('2026-08-30'), ActivityReport::dayLabel('2026-09-06')], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(5, (int) $rows->first()?->value('total'));
        $this->assertSame(2, (int) $rows->last()?->value('total'));

        app(SettingsRepository::class)->update([SettingsRepository::WEEK_STARTS_ON => 1], $this->admin);
        $monday = $this->report()->rows($this->admin, $filters);

        $this->assertSame(['2026-08-31', '2026-09-07'], $monday->map(fn (ReportRow $row): string => (string) $row->meta['date'])->all());
        $this->assertSame(__('reports.columns.activity.week'), $this->report()->labelHeading($filters));
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame([$this->repB->name], $adminOnRepB->map(fn (ReportRow $row): string => $row->label)->all());

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame([$this->repA->name], $this->report()->rows($this->manager, $managerOnRepB)->map(fn (ReportRow $row): string => $row->label)->all());

        $forged = new ReportFilters($managerOnRepB->from, $managerOnRepB->to, ownerId: $this->repB->getKey());
        $this->assertTrue($this->report()->rows($this->manager, $forged)->isEmpty());
    }

    #[Test]
    public function the_chart_stacks_one_dataset_per_kind(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertSame([$this->repA->name, $this->repB->name], $chart['labels']);
        $this->assertSame([
            __('reports.chart.kinds.calls'),
            __('reports.chart.kinds.meetings'),
            __('reports.chart.kinds.emails'),
            __('reports.chart.kinds.notes'),
            __('reports.chart.kinds.other'),
        ], array_column($chart['datasets'], 'label'));
        $this->assertSame([2, 1], $chart['datasets'][0]['data']);
        $this->assertSame([0, 1], $chart['datasets'][4]['data']);
        $this->assertSame(['calls', 'meetings', 'emails', 'notes', 'other', 'total'], array_keys($this->report()->columns()));
        $this->assertSame(array_fill_keys(array_keys($this->report()->columns()), ReportRow::FORMAT_COUNT), $this->report()->formats());
    }

    private function log(User $owner, ActivityKind $kind, string $occurredAt): void
    {
        Activity::factory()->ofKind($kind)->create([
            'owner_id' => $owner->getKey(),
            'created_by' => $owner->getKey(),
            'occurred_at' => $occurredAt,
        ]);
    }

    private function report(): ActivityReport
    {
        return app(ActivityReport::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filters(array $data = [], ?User $viewer = null): ReportFilters
    {
        return ReportFilters::resolve(
            $data + ['from' => '2026-09-01', 'to' => '2026-09-30'],
            $viewer ?? $this->admin,
            app(RecordVisibilityResolver::class),
            Activity::permissionGroup(),
            'Asia/Riyadh',
            ActivityReport::GROUP_BY,
            ActivityReport::GROUP_OWNER,
        );
    }
}
