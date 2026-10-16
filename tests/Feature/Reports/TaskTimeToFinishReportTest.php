<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CrmRole;
use App\Filament\Pages\Reports\BaseReportPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Average time to finish on the task performance report (decision D-21):
 * per assignee, the mean time from when a task completed in the period
 * reached its assignee (`assigned_at`) to its completion; the totals line
 * weighs every completed task; days and hours on the page in the reader's
 * language, hours with one decimal in the export.
 *
 * Fixture (team Riyadh = manager + rep A; rep B has no team). The
 * administrator hands out four tasks at 09:00 on 1 Sep:
 * - rep A finishes T1 after 1 day, T2 after 2 days, T3 after 3 days and 6
 *   hours: 540 000 s over 3 tasks, a mean of 180 000 s = 2 days 2 hours;
 * - rep B finishes T4 after 10 hours 30 minutes: 37 800 s, 10.5 hours in
 *   the export and 11 hours on the page (rounded to the nearest hour).
 * The totals line is 577 800 s over 4 tasks = 144 450 s = 1 day 16 hours
 * (40.1 hours) — the mean of the two means would be 108 900 s.
 */
final class TaskTimeToFinishReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->repA = $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep Alpha'], $this->team);
        $this->repB = $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep Bravo']);
        $this->tasks = app(TaskService::class);
    }

    #[Test]
    public function the_average_is_per_assignee_and_the_totals_line_weighs_every_completed_task(): void
    {
        $this->handOutAndFinish();

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));
        $totals = app(TaskPerformanceReport::class)->totals($rows);

        $this->assertSame([$this->repA->name, $this->repB->name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(3, $this->row($rows, $this->repA)->value('completed'));
        $this->assertSame(540000, $this->row($rows, $this->repA)->value('finish_seconds'));
        $this->assertSame(180000, $this->row($rows, $this->repA)->value('avg_time_to_finish'));
        $this->assertSame(1, $this->row($rows, $this->repB)->value('completed'));
        $this->assertSame(37800, $this->row($rows, $this->repB)->value('avg_time_to_finish'));

        $this->assertSame(4, $totals->value('completed'));
        $this->assertSame(577800, $totals->value('finish_seconds'));
        $this->assertSame(144450, $totals->value('avg_time_to_finish'));
        $this->assertNotSame((180000 + 37800) / 2, $totals->value('avg_time_to_finish'), 'the totals line is not a mean of the per-assignee means');
    }

    #[Test]
    public function a_reassigned_task_counts_from_its_new_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $task = $this->tasks->create(['title' => 'Passed on', 'assignee_id' => $this->repA->getKey()], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));
        $this->tasks->update($task, ['assignee_id' => $this->repB->getKey()], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-07 13:00:00'));
        $this->tasks->complete($task, $this->repB);

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame([$this->repB->name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(1, $this->row($rows, $this->repB)->value('completed'));
        $this->assertSame(86400 + 4 * 3600, $this->row($rows, $this->repB)->value('avg_time_to_finish'), 'one day and four hours since rep B got it, not six days since it was created');
    }

    #[Test]
    public function a_task_completed_before_it_was_reassigned_counts_zero_and_never_negative(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $done = $this->tasks->create(['title' => 'Done, then passed on', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $other = $this->tasks->create(['title' => 'Rep B own work', 'assignee_id' => $this->repB->getKey()], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($done, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->complete($other, $this->repB);
        app(RecordAssignmentService::class)->assign($done, $this->repB, $this->admin);

        $this->assertTrue($done->refresh()->assigned_at?->greaterThan($done->completed_at), 'the fixture reassigns after completion');

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame(2, $this->row($rows, $this->repB)->value('completed'));
        $this->assertSame(2 * 86400, $this->row($rows, $this->repB)->value('finish_seconds'), 'the reassigned task adds zero, not minus one day');
        $this->assertSame(86400, $this->row($rows, $this->repB)->value('avg_time_to_finish'));
    }

    #[Test]
    public function only_tasks_completed_in_the_period_are_averaged(): void
    {
        // Completed in August: outside a September report.
        $this->travelTo(Carbon::parse('2026-08-10 09:00:00'));
        $august = $this->tasks->create(['title' => 'August work', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->tasks->complete($august, $this->repA);

        // In September: one completed after 6 hours, one cancelled and one
        // still open, both long since handed out — neither is a time to finish.
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $done = $this->tasks->create(['title' => 'Done', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $cancelled = $this->tasks->create(['title' => 'Called off', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $this->tasks->create(['title' => 'Still open', 'assignee_id' => $this->repA->getKey()], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-01 15:00:00'));
        $this->tasks->complete($done, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-20 09:00:00'));
        $this->tasks->cancel($cancelled, $this->admin);

        $september = $this->row(app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin)), $this->repA);

        $this->assertSame(1, $september->value('completed'));
        $this->assertSame(6 * 3600, $september->value('finish_seconds'));
        $this->assertSame(6 * 3600, $september->value('avg_time_to_finish'));

        $augustRow = $this->row(app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin, ['from' => '2026-08-01', 'to' => '2026-08-31'])), $this->repA);

        $this->assertSame(10 * 86400, $augustRow->value('avg_time_to_finish'), 'the August report averages the August completion alone');
    }

    #[Test]
    public function the_unassigned_line_counts_from_creation_and_joins_the_weighted_totals(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $never = $this->tasks->create(['title' => 'Nobody took it', 'assignee_id' => null], $this->admin);
        $dropped = $this->tasks->create(['title' => 'Dropped', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $kept = $this->tasks->create(['title' => 'Kept', 'assignee_id' => $this->repA->getKey()], $this->admin);

        // Unassigned a day later: no assignment moment is left to count from.
        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->update($dropped, ['assignee_id' => null], $this->admin);
        $this->assertNull($dropped->refresh()->assigned_at);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->complete($never, $this->admin);
        $this->tasks->complete($dropped, $this->admin);
        $this->tasks->complete($kept, $this->repA);

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));
        $unassigned = $rows->last();

        $this->assertInstanceOf(ReportRow::class, $unassigned);
        $this->assertSame(__('reports.labels.unassigned'), $unassigned->label);
        $this->assertNull($unassigned->meta['assignee_id']);
        $this->assertSame(2, $unassigned->value('completed'));
        $this->assertSame(2 * 2 * 86400, $unassigned->value('finish_seconds'), 'both unassigned tasks count from their creation, two days each');
        $this->assertSame(2 * 86400, $unassigned->value('avg_time_to_finish'));

        $totals = app(TaskPerformanceReport::class)->totals($rows);

        $this->assertSame(3, $totals->value('completed'));
        $this->assertSame(3 * 2 * 86400, $totals->value('finish_seconds'));
        $this->assertSame(2 * 86400, $totals->value('avg_time_to_finish'));

        // A rep sees neither the unassigned line nor its tasks (D-4).
        $rep = app(TaskPerformanceReport::class)->rows($this->repA, $this->taskReportFilters($this->repA));

        $this->assertSame([$this->repA->name], $rep->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(2 * 86400, app(TaskPerformanceReport::class)->totals($rep)->value('finish_seconds'));
    }

    #[Test]
    public function an_assignee_without_a_completed_task_shows_the_empty_marker(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $this->tasks->create(['title' => 'Still on it', 'assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-30 10:00:00'], $this->admin);

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));
        $totals = app(TaskPerformanceReport::class)->totals($rows);

        $this->assertSame(ReportRow::NOT_MEASURED, $this->row($rows, $this->repA)->value('avg_time_to_finish'));
        $this->assertSame(ReportRow::NOT_MEASURED, $totals->value('avg_time_to_finish'));
        $this->assertSame(__('common.placeholders.empty'), BaseReportPage::format(ReportRow::FORMAT_DURATION, ReportRow::NOT_MEASURED));
    }

    #[Test]
    public function each_viewer_averages_only_the_tasks_in_their_scope(): void
    {
        $this->handOutAndFinish();

        $repA = app(TaskPerformanceReport::class)->rows($this->repA, $this->taskReportFilters($this->repA));
        $repB = app(TaskPerformanceReport::class)->rows($this->repB, $this->taskReportFilters($this->repB));
        $manager = app(TaskPerformanceReport::class)->rows($this->manager, $this->taskReportFilters($this->manager));

        $this->assertSame([$this->repA->name], $repA->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(180000, app(TaskPerformanceReport::class)->totals($repA)->value('avg_time_to_finish'), 'rep A never sees rep B\'s tasks (D-4)');
        $this->assertSame([$this->repB->name], $repB->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(37800, app(TaskPerformanceReport::class)->totals($repB)->value('avg_time_to_finish'));
        $this->assertSame([$this->repA->name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(180000, app(TaskPerformanceReport::class)->totals($manager)->value('avg_time_to_finish'));
    }

    #[Test]
    public function a_duration_reads_in_whole_days_and_hours_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('أقل من ساعة', BaseReportPage::format(ReportRow::FORMAT_DURATION, 0));
        $this->assertSame('أقل من ساعة', BaseReportPage::format(ReportRow::FORMAT_DURATION, 3599));
        $this->assertSame('ساعة واحدة', BaseReportPage::format(ReportRow::FORMAT_DURATION, 3600));
        $this->assertSame('ساعتان', BaseReportPage::format(ReportRow::FORMAT_DURATION, 2 * 3600 + 1799));
        $this->assertSame('3 ساعات', BaseReportPage::format(ReportRow::FORMAT_DURATION, 2 * 3600 + 1800), 'from one hour on, the nearest hour — as the export\'s 2.5 reads');
        $this->assertSame('10 ساعات', BaseReportPage::format(ReportRow::FORMAT_DURATION, 36000));
        $this->assertSame('11 ساعةً', BaseReportPage::format(ReportRow::FORMAT_DURATION, 37800));
        $this->assertSame('23 ساعةً', BaseReportPage::format(ReportRow::FORMAT_DURATION, 23 * 3600));
        $this->assertSame('يوم واحد', BaseReportPage::format(ReportRow::FORMAT_DURATION, 23 * 3600 + 1800));
        $this->assertSame('يوم واحد', BaseReportPage::format(ReportRow::FORMAT_DURATION, 86400));
        $this->assertSame('يومان و5 ساعات', BaseReportPage::format(ReportRow::FORMAT_DURATION, 2 * 86400 + 5 * 3600));
        $this->assertSame('يوم واحد و16 ساعةً', BaseReportPage::format(ReportRow::FORMAT_DURATION, 144450));
        $this->assertSame('3 أيام وساعة واحدة', BaseReportPage::format(ReportRow::FORMAT_DURATION, 3 * 86400 + 3600));
        $this->assertSame('11 يوماً وساعتان', BaseReportPage::format(ReportRow::FORMAT_DURATION, 11 * 86400 + 2 * 3600));
        $this->assertSame('100 يوم', BaseReportPage::format(ReportRow::FORMAT_DURATION, 100 * 86400));
        $this->assertSame('—', BaseReportPage::format(ReportRow::FORMAT_DURATION, ReportRow::NOT_MEASURED));

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function a_duration_reads_in_whole_days_and_hours_in_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('less than an hour', BaseReportPage::format(ReportRow::FORMAT_DURATION, 1800));
        $this->assertSame('less than an hour', BaseReportPage::format(ReportRow::FORMAT_DURATION, 3599));
        $this->assertSame('1 hour', BaseReportPage::format(ReportRow::FORMAT_DURATION, 3600));
        $this->assertSame('1 hour', BaseReportPage::format(ReportRow::FORMAT_DURATION, 5399));
        $this->assertSame('2 hours', BaseReportPage::format(ReportRow::FORMAT_DURATION, 5400));
        $this->assertSame('10 hours', BaseReportPage::format(ReportRow::FORMAT_DURATION, 36000));
        $this->assertSame('11 hours', BaseReportPage::format(ReportRow::FORMAT_DURATION, 37800));
        $this->assertSame('1 day', BaseReportPage::format(ReportRow::FORMAT_DURATION, 86400 + 1799));
        $this->assertSame('1 day 1 hour', BaseReportPage::format(ReportRow::FORMAT_DURATION, 86400 + 1800));
        $this->assertSame('2 days 2 hours', BaseReportPage::format(ReportRow::FORMAT_DURATION, 180000));
        $this->assertSame('1 day 16 hours', BaseReportPage::format(ReportRow::FORMAT_DURATION, 144450));
        $this->assertSame('—', BaseReportPage::format(ReportRow::FORMAT_DURATION, ReportRow::NOT_MEASURED));

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function the_page_shows_the_column_in_the_reader_language_outside_the_ltr_isolate(): void
    {
        $this->handOutAndFinish();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        app()->setLocale('ar');

        $html = Livewire::actingAs($this->admin)
            ->test(TaskPerformanceReportPage::class)
            ->assertSee('متوسط مدة الإنجاز')
            ->assertSee('يومان وساعتان')
            ->assertSee('11 ساعةً')
            ->assertSee('يوم واحد و16 ساعةً')
            ->html();

        $this->assertStringNotContainsString('<span class="crm-ltr">يومان', $html, 'Arabic words in an LTR isolate');

        app()->setLocale('en');

        Livewire::actingAs($this->admin)
            ->test(TaskPerformanceReportPage::class)
            ->assertSee('Average time to finish')
            ->assertSee('2 days 2 hours')
            ->assertSee('11 hours')
            ->assertSee('1 day 16 hours');

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function the_export_writes_hours_with_one_decimal_under_a_heading_that_names_the_unit(): void
    {
        $this->handOutAndFinish();

        // An assignee with work but nothing finished: an empty cell, not a dash.
        $this->tasks->create(['title' => 'Not yet', 'assignee_id' => $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep Charlie'])->getKey(), 'due_at' => '2026-09-30 10:00:00'], $this->admin);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        app()->setLocale('en');

        $csv = Livewire::actingAs($this->admin)
            ->test(TaskPerformanceReportPage::class)
            ->callAction('exportCsv')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('report-tasks-2026-09-01-2026-09-30.csv');

        $content = base64_decode((string) data_get($csv->effects, 'download.content'), true);

        $this->assertIsString($content);

        // The CSV opens with a UTF-8 byte order mark so spreadsheets read the Arabic right.
        $this->assertStringStartsWith("\u{FEFF}", $content);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($content, 3)))));

        $this->assertSame('Assignee,Completed,"On time",Late,"Still open","Overdue now","Completion %","Average time to finish (hours)"', $lines[0]);
        $this->assertSame('"Rep Alpha",3,3,0,0,0,100,50', $lines[1]);
        $this->assertSame('"Rep Bravo",1,1,0,0,0,100,10.5', $lines[2]);
        $this->assertSame('"Rep Charlie",0,0,0,1,0,0,', $lines[3]);
        $this->assertSame('Total,4,4,0,1,0,80,40.1', $lines[4]);

        app()->setLocale((string) config('app.locale'));
    }

    /**
     * The fixture of the class docblock: four tasks handed out at 09:00 on
     * 1 Sep and finished after 1 day, 2 days, 3 days 6 hours (rep A) and
     * 10 hours 30 minutes (rep B).
     */
    private function handOutAndFinish(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $t1 = $this->tasks->create(['title' => 'T1', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $t2 = $this->tasks->create(['title' => 'T2', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $t3 = $this->tasks->create(['title' => 'T3', 'assignee_id' => $this->repA->getKey()], $this->admin);
        $t4 = $this->tasks->create(['title' => 'T4', 'assignee_id' => $this->repB->getKey()], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-01 19:30:00'));
        $this->tasks->complete($t4, $this->repB);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($t1, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->complete($t2, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-04 15:00:00'));
        $this->tasks->complete($t3, $this->repA);
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    private function row(Collection $rows, User $assignee): ReportRow
    {
        $row = $rows->first(fn (ReportRow $row): bool => ($row->meta['assignee_id'] ?? null) === $assignee->getKey());

        $this->assertInstanceOf(ReportRow::class, $row, "no report line for {$assignee->name}");

        return $row;
    }
}
