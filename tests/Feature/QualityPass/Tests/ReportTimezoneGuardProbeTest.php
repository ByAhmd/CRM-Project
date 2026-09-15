<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Enums\ActivityKind;
use App\Models\Activity;
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
 * Test-suite audit probe: ReportPagesTest::the_organisation_timezone_matches_the_application_timezone
 * claims to fail "if an administrator ever splits them", but it only compares
 * the seeded default with config — General Settings may change the timezone at
 * runtime (D-8) and no report test does so. A-19 requires time buckets folded
 * in PHP in the organisation timezone; ActivityReport (and WinLossReport) bucket
 * with SQL DATE() in the application timezone. The dashboard has this test
 * (DashboardMetricsTest, Asia/Tokyo); the reports do not.
 */
final class ReportTimezoneGuardProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_activity_report_buckets_days_in_the_organisation_timezone_when_it_differs_from_the_application_one(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        $admin = $this->admin();
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $admin);

        // 20:30 on 2 Sep in Riyadh is 02:30 on 3 Sep in Tokyo.
        Activity::factory()->ofKind(ActivityKind::Call)->create([
            'owner_id' => $admin->getKey(),
            'occurred_at' => Carbon::parse('2026-09-03 02:30:00', 'Asia/Tokyo')->setTimezone((string) config('app.timezone')),
        ]);

        $filters = ReportFilters::resolve(
            ['from' => '2026-09-01', 'to' => '2026-09-05', 'group_by' => ActivityReport::GROUP_DAY],
            $admin,
            app(RecordVisibilityResolver::class),
            Activity::permissionGroup(),
            app(SettingsRepository::class)->timezone(),
            ActivityReport::GROUP_BY,
            ActivityReport::GROUP_OWNER,
        );

        $byDay = app(ActivityReport::class)->rows($admin, $filters)
            ->mapWithKeys(fn (ReportRow $row): array => [(string) $row->meta['date'] => (int) $row->value('total')]);

        $this->assertSame(1, $byDay->get('2026-09-03'), 'the call belongs to 3 Sep in the organisation timezone');
        $this->assertSame(0, $byDay->get('2026-09-02'));
    }
}
