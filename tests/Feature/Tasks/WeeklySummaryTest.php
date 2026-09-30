<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Enums\UserStatus;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Notifications\WeeklySummaryNotification;
use App\Services\Settings\SettingsRepository;
use App\Services\System\BackupException;
use App\Services\System\BackupFailure;
use App\Services\System\LoggedErrorCounter;
use App\Services\Tasks\WeeklySummary;
use App\Services\Tasks\WeeklySummaryAssignee;
use App\Services\Tasks\WeeklySummaryTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBackupSets;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The weekly summary (decision D-18): the read model on a fixed clock —
 * completed, overdue, stalled, handed out and the per-assignee table, the
 * window's edges, the organisation timezone, the caps — the recipients of
 * crm:weekly-summary (active task.assign holders only, system health only for
 * super admins, each in their own locale, links only where the policy
 * allows), the health figures (backup, failed jobs, counted log errors) and
 * the mail in both locales.
 */
final class WeeklySummaryTest extends TestCase
{
    use BuildsBackupSets;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->useBackupDirectory();
        $this->useLogDirectory([]);

        // Thursday, the scheduled moment.
        $this->now = CarbonImmutable::parse('2026-10-01 22:30:00', 'Asia/Riyadh');
        $this->travelTo($this->now);
    }

    #[Test]
    public function the_report_counts_the_last_seven_days_and_the_open_work_as_it_stands_now(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        // Completed: inside the window counts, the week before does not.
        $this->task($rep, TaskStatus::Completed, ['completed_at' => $this->now->subDays(2)]);
        $this->task($rep, TaskStatus::Completed, ['completed_at' => $this->now->subDays(8)]);
        $this->task($rep, TaskStatus::Cancelled, ['due_at' => $this->now->subDays(3)]);

        // Overdue now: open and past due, whenever it fell due.
        $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDays(20), 'title' => 'Oldest overdue']);
        $this->task($rep, TaskStatus::InProgress, ['due_at' => $this->now->subHours(2)]);
        $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->addDay()]);
        $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDay()])->delete();

        // Stalled: in progress, nothing posted for three days.
        $quiet = $this->task($rep, TaskStatus::InProgress, ['due_at' => null, 'title' => 'Quiet task']);
        $this->entry($quiet, $rep, $this->now->subDays(4), TaskUpdateKind::Progress);
        $busy = $this->task($rep, TaskStatus::InProgress, ['due_at' => null]);
        $this->entry($busy, $rep, $this->now->subDays(5), TaskUpdateKind::Progress);
        $this->entry($busy, $admin, $this->now->subDay(), TaskUpdateKind::Comment);
        $this->task($rep, TaskStatus::InProgress, ['due_at' => null, 'title' => 'Legacy quiet', 'updated_at' => $this->now->subDays(6)]);
        $this->task($rep, TaskStatus::InProgress, ['due_at' => null, 'updated_at' => $this->now->subDay()]);

        // Handed out: created in the window for someone else by the assigner.
        $this->task($rep, TaskStatus::Pending, ['assigned_by' => $admin->getKey(), 'created_at' => $this->now->subDay()]);
        $this->task($rep, TaskStatus::Pending, ['assigned_by' => $admin->getKey(), 'created_at' => $this->now->subDays(9)]);
        $this->task($rep, TaskStatus::Pending, ['assigned_by' => $rep->getKey(), 'created_at' => $this->now->subDay()]);

        $report = app(WeeklySummary::class)->report($this->now);

        $this->assertSame(1, $report->completed);
        $this->assertSame(2, $report->overdueTotal);
        $this->assertSame(['Oldest overdue', 2], [$report->overdue[0]->title, count($report->overdue)]);
        $this->assertSame(20, $report->overdue[0]->days);
        $this->assertSame('Sales Rep', $report->overdue[0]->assigneeName);
        $this->assertSame(0, $report->overdue[1]->days);
        $this->assertSame(2, $report->stalledTotal);
        $this->assertSame(['Legacy quiet', 'Quiet task'], array_map(static fn (WeeklySummaryTask $task): string => $task->title, $report->stalled));
        $this->assertSame([6, 4], array_map(static fn (WeeklySummaryTask $task): int => $task->days, $report->stalled));
        $this->assertSame(1, $report->handedOut);
        $this->assertSame(0, $report->overdueMore());
        $this->assertSame(0, $report->stalledMore());
    }

    #[Test]
    public function the_per_assignee_table_puts_the_most_overdue_first_and_keeps_unassigned_work(): void
    {
        $calm = $this->makeUser(CrmRole::SalesRep, ['name' => 'Calm Rep']);
        $late = $this->makeUser(CrmRole::SalesRep, ['name' => 'Late Rep']);

        $this->task($calm, TaskStatus::Pending, ['due_at' => $this->now->addDays(2)]);
        $this->task($calm, TaskStatus::Completed, ['completed_at' => $this->now->subDay()]);
        $this->task($calm, TaskStatus::Completed, ['completed_at' => $this->now->subDays(3)]);
        $this->task($late, TaskStatus::Pending, ['due_at' => $this->now->subDays(2)]);
        $this->task($late, TaskStatus::InProgress, ['due_at' => $this->now->subDay()]);
        $this->task(null, TaskStatus::Pending, ['due_at' => $this->now->addDay()]);

        $report = app(WeeklySummary::class)->report($this->now);

        $this->assertSame([
            ['Late Rep', 2, 2, 0],
            ['Calm Rep', 1, 0, 2],
            [null, 1, 0, 0],
        ], array_map(static fn (WeeklySummaryAssignee $row): array => [$row->name, $row->open, $row->overdue, $row->completed], $report->assignees));
        $this->assertSame(3, $report->assigneesTotal);
        $this->assertSame(2, $report->completed);
    }

    #[Test]
    public function the_window_is_exactly_seven_days_and_dates_are_shown_in_the_organisation_timezone(): void
    {
        $rep = $this->salesRep();
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC']);

        $this->task($rep, TaskStatus::Completed, ['completed_at' => $this->now->subDays(7)]);
        $this->task($rep, TaskStatus::Completed, ['completed_at' => $this->now->subDays(7)->subSecond()]);
        $this->task($rep, TaskStatus::Pending, ['due_at' => CarbonImmutable::parse('2026-09-30 01:00:00', 'Asia/Riyadh')]);

        $report = app(WeeklySummary::class)->report($this->now);

        $this->assertSame(1, $report->completed);
        $this->assertSame('UTC', $report->to->getTimezone()->getName());
        $this->assertSame('2026-09-24 19:30', $report->from->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 19:30', $report->to->format('Y-m-d H:i'));
        // 01:00 in Riyadh is still the previous evening in UTC.
        $this->assertSame('2026-09-29', $report->overdue[0]->since->format('Y-m-d'));
    }

    #[Test]
    public function the_lists_and_the_table_are_capped_and_say_how_many_more_there_are(): void
    {
        $rep = $this->salesRep();

        for ($i = 0; $i < WeeklySummary::LIST_LIMIT + 2; $i++) {
            $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDays($i + 1)]);
        }

        for ($i = 0; $i < WeeklySummary::ASSIGNEE_LIMIT; $i++) {
            $this->task($this->makeUser(CrmRole::SalesRep, ['name' => 'Rep '.$i]), TaskStatus::Pending, ['due_at' => $this->now->addDay()]);
        }

        $report = app(WeeklySummary::class)->report($this->now);

        $this->assertCount(WeeklySummary::LIST_LIMIT, $report->overdue);
        $this->assertSame(WeeklySummary::LIST_LIMIT + 2, $report->overdueTotal);
        $this->assertSame(2, $report->overdueMore());
        $this->assertSame(WeeklySummary::LIST_LIMIT + 2, $report->overdue[0]->days, 'the oldest due date comes first');
        $this->assertCount(WeeklySummary::ASSIGNEE_LIMIT, $report->assignees);
        $this->assertSame(WeeklySummary::ASSIGNEE_LIMIT + 1, $report->assigneesTotal);
        $this->assertSame(1, $report->assigneesMore());
        $this->assertSame('Sales Rep', $report->assignees[0]->name);
    }

    #[Test]
    public function the_command_reaches_active_task_assigners_only_with_health_for_super_admins_in_their_locale(): void
    {
        Notification::fake();

        $superAdmin = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Chief', 'locale' => 'ar']);
        $admin = $this->makeUser(CrmRole::Admin, ['name' => 'Office Admin', 'locale' => 'en']);
        $custom = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::TaskAssign]);
        $disabled = $this->makeUser(CrmRole::Admin, ['name' => 'Gone Admin', 'status' => UserStatus::Disabled]);
        $pending = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Invited Admin', 'status' => UserStatus::Pending]);
        $others = [$this->salesManager(), $this->salesRep(), $this->support(), $this->readOnly(), $this->employee()];

        $this->artisan('crm:weekly-summary')
            ->expectsOutputToContain('weekly summary sent to 3 recipients')
            ->assertSuccessful();

        Notification::assertSentTo($superAdmin, WeeklySummaryNotification::class, static fn (WeeklySummaryNotification $notification): bool => $notification->health !== null && $notification->locale === 'ar');
        Notification::assertSentTo($admin, WeeklySummaryNotification::class, static fn (WeeklySummaryNotification $notification): bool => $notification->health === null && $notification->locale === 'en');
        Notification::assertSentTo($custom, WeeklySummaryNotification::class, static fn (WeeklySummaryNotification $notification): bool => $notification->health === null);
        Notification::assertSentTimes(WeeklySummaryNotification::class, 3);

        foreach ([$disabled, $pending, ...$others] as $user) {
            Notification::assertNotSentTo($user, WeeklySummaryNotification::class);
        }
    }

    #[Test]
    public function a_listed_task_links_only_for_a_reader_whose_policy_opens_it(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $ownOnly = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::TaskAssign]);
        $rep = $this->salesRep();

        $theirs = $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDay()]);
        $mine = $this->task($ownOnly, TaskStatus::Pending, ['due_at' => $this->now->subDays(2)]);

        $this->assertSame(2, app(WeeklySummary::class)->send($this->now));

        Notification::assertSentTo($admin, WeeklySummaryNotification::class, static fn (WeeklySummaryNotification $notification): bool => $notification->readableTaskIds === [(int) $theirs->getKey(), (int) $mine->getKey()]
            || $notification->readableTaskIds === [(int) $mine->getKey(), (int) $theirs->getKey()]);
        Notification::assertSentTo($ownOnly, WeeklySummaryNotification::class, static fn (WeeklySummaryNotification $notification): bool => $notification->readableTaskIds === [(int) $mine->getKey()]
            && count($notification->report->overdue) === 2);
    }

    #[Test]
    public function the_summary_rings_the_bell_by_default_and_mails_by_default_once_a_mailer_exists(): void
    {
        $admin = $this->admin();
        $notification = new WeeklySummaryNotification(app(WeeklySummary::class)->report($this->now), null, []);

        $this->assertSame(['database'], $notification->via($admin));

        config(['mail.default' => 'smtp']);
        $this->assertSame(['database', 'mail'], $notification->via($admin));
        $this->assertTrue(NotificationEvent::WeeklySummary->mailByDefault());

        config(['mail.default' => 'log']);
        $admin->notify($notification->locale('en'));

        $data = $admin->notifications()->sole()->data;
        $this->assertSame('Your weekly task summary', $data['title']);
        $this->assertStringContainsString('Completed: 0', (string) $data['body']);
        $this->assertSame(TasksBoard::getUrl(), $data['actions'][0]['url']);
    }

    #[Test]
    public function the_health_part_reports_the_backup_failed_jobs_and_counted_log_errors(): void
    {
        $backups = (string) config('crm.backup.path');
        $this->makeBackupSet($backups, $this->now->subDays(7)->setTime(22, 0), str_repeat('d', 2048), str_repeat('f', 1024));
        $failure = new BackupFailure($this->now->subMinutes(30), BackupException::REASON_DATABASE, 'mysqldump exited with code 2');
        file_put_contents($backups.DIRECTORY_SEPARATOR.'last-failure.json', json_encode($failure->toArray()));

        foreach ([$this->now->subDays(2), $this->now->subDays(6), $this->now->subDays(8)] as $failedAt) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'RuntimeException',
                'failed_at' => $failedAt->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
            ]);
        }

        $this->useLogDirectory([
            'laravel.log' => implode("\n", [
                '[2026-09-30 10:00:00] production.ERROR: Something broke {"exception":"[object] (RuntimeException(code: 0): x"}',
                '[stacktrace]',
                '#0 /app/vendor/file.php(10): call()',
                '[2026-09-30 10:05:00] production.INFO: Just so you know',
                '[2026-09-20 10:00:00] production.CRITICAL: Before the window',
                '[2026-10-01 22:29:59] local.CRITICAL: Just in time',
                '',
            ]),
            'laravel-2026-09-26.log' => "[2026-09-26 08:00:00] production.EMERGENCY: Daily file\n[2026-09-26 08:00:01] production.WARNING: Not an error\n",
            'laravel-2026-09-10.log' => "[2026-09-10 08:00:00] production.ERROR: Old file\n",
        ]);

        $health = app(WeeklySummary::class)->health($this->now);

        $this->assertNotNull($health->latestBackup);
        $this->assertSame(3072, $health->latestBackup->totalBytes());
        $this->assertSame(BackupException::REASON_DATABASE, $health->backupFailure?->reason);
        $this->assertSame(2, $health->failedJobs);
        $this->assertSame(3, $health->loggedErrors);
        $this->assertTrue($health->errorsComplete);
    }

    #[Test]
    public function an_oversized_log_is_read_from_its_end_and_counted_as_a_lower_bound(): void
    {
        $filler = str_repeat('[2026-09-30 09:00:00] production.DEBUG: '.str_repeat('x', 200)."\n", (int) ceil((LoggedErrorCounter::MAX_BYTES + 65536) / 241));

        $this->useLogDirectory([
            'laravel.log' => "[2026-09-30 08:00:00] production.ERROR: Too early to be read\n".$filler."[2026-09-30 10:00:00] production.ERROR: Newest\n",
        ]);

        $counted = app(LoggedErrorCounter::class)->count($this->now->subDays(7), $this->now);

        $this->assertSame(['errors' => 1, 'complete' => false], $counted);
    }

    #[Test]
    public function the_mail_reads_right_to_left_in_arabic_with_escaped_titles_links_caps_and_health(): void
    {
        $superAdmin = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Chief', 'locale' => 'ar']);
        $rep = $this->salesRep();
        $linked = $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDays(3), 'title' => 'Call <b>back</b> **now**']);

        for ($i = 0; $i < WeeklySummary::LIST_LIMIT + 1; $i++) {
            $this->task($rep, TaskStatus::Pending, ['due_at' => $this->now->subDay()]);
        }

        $summary = app(WeeklySummary::class);
        $notification = new WeeklySummaryNotification($summary->report($this->now), $summary->health($this->now), [(int) $linked->getKey()]);

        App::setLocale('ar');
        $mail = $notification->toMail($superAdmin);
        $html = (string) $mail->render();

        $this->assertSame('الملخص الأسبوعي للمهام من 2026-09-24 إلى 2026-10-01', $mail->subject);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('dir="ltr" style', $html);
        $this->assertStringContainsString('مرحباً Chief،', $html);
        $this->assertStringContainsString('المتأخرة الآن', $html);
        $this->assertStringContainsString('Call &lt;b&gt;back&lt;/b&gt; **now**', $html);
        $this->assertStringContainsString('href="'.TaskResource::getUrl('view', ['record' => $linked]).'"', $html);
        $this->assertSame(1, substr_count($html, TaskResource::getUrl('view', ['record' => $linked])), 'only the readable task links');
        $this->assertStringContainsString('… ومهمتان أخريان على لوحة المهام.', $html);
        $this->assertStringContainsString('متأخرة 3 أيام', $html);
        $this->assertStringContainsString('حالة النظام', $html);
        $this->assertStringContainsString('لم تُؤخذ أي نسخة احتياطية بعد.', $html);
        $this->assertStringNotContainsString('Hello', $html);
        $this->assertStringNotContainsString('weekly_summary.', $html);
        $this->assertStringNotContainsString('<code>', $html, 'a block was read as indented code');
        $this->assertStringNotContainsString('&lt;td', $html, 'a block was escaped instead of kept as HTML');
    }

    #[Test]
    public function the_mail_reads_left_to_right_in_english_and_leaves_health_out_of_an_admin_copy(): void
    {
        $admin = $this->makeUser(CrmRole::Admin, ['name' => 'Office Admin', 'locale' => 'en']);
        $stuck = $this->task($this->salesRep(), TaskStatus::InProgress, ['due_at' => null, 'title' => 'Stuck task', 'updated_at' => $this->now->subDays(4)]);

        $notification = new WeeklySummaryNotification(app(WeeklySummary::class)->report($this->now), null, []);

        App::setLocale('en');
        $mail = $notification->toMail($admin);
        $html = (string) $mail->render();

        $this->assertSame('Weekly task summary, 2026-09-24 to 2026-10-01', $mail->subject);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringNotContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('Hello Office Admin,', $html);
        $this->assertStringContainsString('Stuck task', $html);
        $this->assertStringContainsString('quiet for 4 days', $html);
        $this->assertStringContainsString('No open task is overdue.', $html);
        $this->assertStringContainsString(TasksBoard::getUrl(), $html);
        $this->assertStringNotContainsString('System health', $html);
        $this->assertStringNotContainsString(TaskResource::getUrl('view', ['record' => $stuck]), $html, 'no task link without a readable id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(?User $assignee, TaskStatus $status, array $attributes = []): Task
    {
        return Task::factory()->create([
            'assignee_id' => $assignee?->getKey(),
            'created_by' => $assignee?->getKey() ?? User::factory(),
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function entry(Task $task, User $author, CarbonImmutable $at, TaskUpdateKind $kind): void
    {
        (new TaskUpdate)->forceFill([
            'task_id' => $task->getKey(),
            'user_id' => $author->getKey(),
            'kind' => $kind,
            'status' => null,
            'body' => 'Entry',
            'created_at' => $at,
        ])->save();
    }

    /**
     * Log files in a scratch directory the logging configuration points at.
     *
     * @param  array<string, string>  $files  name => contents
     */
    private function useLogDirectory(array $files): void
    {
        $directory = $this->scratchBackupDirectory('logs');

        config([
            'logging.channels.single.path' => $directory.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $directory.DIRECTORY_SEPARATOR.'laravel.log',
        ]);

        foreach ($files as $name => $contents) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.$name, $contents);
        }
    }
}
