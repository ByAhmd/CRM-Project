<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\DueShortcut;
use App\Enums\DueTone;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Support\TaskVisuals;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\DueShortcuts;
use App\Services\Tasks\RelativeDueDates;
use App\Services\Tasks\TaskService;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The foundation of the D-27 screens: one organisation clock behind every
 * "today", "tomorrow" and "this week" (OrganisationCalendar), the due-date
 * chips (DueShortcuts), the relative due dates (RelativeDueDates) and the
 * shared visual pieces (TaskVisuals, the <x-task.*> components) — in both
 * languages, escape-safe, named for screen readers, on theme tokens.
 */
final class TaskVisualLanguageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        // Wednesday 2026-10-07, 10:00 in Riyadh (the application and, by default, the organisation clock).
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        $this->admin = $this->admin();
    }

    #[Test]
    public function the_organisation_day_follows_the_settings_timezone_not_the_server(): void
    {
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $this->admin);
        // 21:30 in Riyadh is already 03:30 on Thursday in Tokyo.
        $this->travelTo(Carbon::parse('2026-10-07 21:30:00'));
        $calendar = app(OrganisationCalendar::class);

        $this->assertSame('2026-10-08 00:00 Asia/Tokyo', $calendar->today()->format('Y-m-d H:i e'));
        $this->assertSame('2026-10-09', $calendar->tomorrow()->format('Y-m-d'));
        $this->assertSame(0, $calendar->daysFromToday(Carbon::parse('2026-10-07 20:00:00')), '20:00 Riyadh is Thursday 02:00 in Tokyo: today');
        $this->assertSame(-1, $calendar->daysFromToday(Carbon::parse('2026-10-07 17:00:00')), '17:00 Riyadh is Wednesday 23:00 in Tokyo: yesterday');
        $this->assertTrue($calendar->isToday(Carbon::parse('2026-10-08 17:59:00')));

        [$start, $end] = $calendar->dayRange('2026-10-08');
        $this->assertSame('2026-10-07 18:00:00 Asia/Riyadh', $start->format('Y-m-d H:i:s e'), 'the day starts at Tokyo midnight, stored on the application clock');
        $this->assertSame('2026-10-08 18:00:00 Asia/Riyadh', $end->format('Y-m-d H:i:s e'));

        $this->assertSame('2026-10-09 17:00 Asia/Tokyo', $calendar->at('2026-10-09', '17:00')->format('Y-m-d H:i e'));
        $this->assertSame('2026-10-09 11:00 Asia/Riyadh', $calendar->stored($calendar->at('2026-10-09', '17:00'))->format('Y-m-d H:i e'));
    }

    #[Test]
    public function the_week_starts_on_the_organisation_week_start(): void
    {
        $calendar = app(OrganisationCalendar::class);

        $this->assertSame('2026-10-04', $calendar->startOfWeek()->format('Y-m-d'), 'the default week starts on Sunday');
        $this->assertSame('2026-10-11', $calendar->startOfNextWeek()->format('Y-m-d'));

        app(SettingsRepository::class)->update([SettingsRepository::WEEK_STARTS_ON => 6], $this->admin);

        $this->assertSame('2026-10-03', $calendar->startOfWeek()->format('Y-m-d'), 'a Saturday week start');
        $this->assertSame('2026-10-10', $calendar->startOfNextWeek()->format('Y-m-d'));
    }

    #[Test]
    public function the_due_chips_land_on_the_organisation_calendar_keeping_the_tasks_time(): void
    {
        $chips = app(DueShortcuts::class);
        $at = static fn (?Carbon $moment): ?string => $moment?->format('Y-m-d H:i');

        // A new task (no time of its own) is due at 17:00.
        $this->assertSame('2026-10-07 17:00', $at($chips->resolve(DueShortcut::Today)));
        $this->assertSame('2026-10-08 17:00', $at($chips->resolve(DueShortcut::Tomorrow)));
        $this->assertSame('2026-10-11 17:00', $at($chips->resolve(DueShortcut::NextWeek)));
        $this->assertSame('2026-10-20 17:00', $at($chips->resolve(DueShortcut::Pick, null, '2026-10-20')));
        $this->assertNull($chips->resolve(DueShortcut::Pick, null, '2026-02-31'), 'a date that does not exist picks nothing');
        $this->assertNull($chips->resolve(DueShortcut::Pick, null, null));
        $this->assertNull($chips->resolve(DueShortcut::None));

        // A task due at 08:30 keeps 08:30; "today" never lands in the past.
        $current = Carbon::parse('2026-10-12 08:30:00');
        $this->assertSame('2026-10-08 08:30', $at($chips->resolve(DueShortcut::Tomorrow, $current)));
        $this->assertSame('2026-10-07 17:00', $at($chips->resolve(DueShortcut::Today, $current)), '08:30 has passed today: 17:00');
        $this->assertSame('2026-10-07 20:00', $at($chips->resolve(DueShortcut::Today, Carbon::parse('2026-10-01 20:00:00'))), '20:00 is still ahead today');

        $this->travelTo(Carbon::parse('2026-10-07 18:00:00'));
        $this->assertSame('2026-10-07 23:59', $at($chips->resolve(DueShortcut::Today)), 'after 17:00, the end of the day');

        // On another organisation clock the stored moment is that clock's 17:00.
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $this->admin);
        $this->assertSame('2026-10-09 11:00 Asia/Riyadh', $chips->resolve(DueShortcut::Tomorrow)?->format('Y-m-d H:i e'), 'Tokyo is on Thursday already; tomorrow 17:00 Tokyo');
    }

    #[Test]
    public function relative_due_dates_read_in_words_on_the_organisation_calendar_in_both_languages(): void
    {
        $dates = app(RelativeDueDates::class);
        $cases = [
            // [due (Riyadh), open?, en, ar, tone]
            ['2026-10-07 09:00:00', true, 'Today 09:00', 'اليوم 09:00', DueTone::Today],
            ['2026-10-07 17:00:00', true, 'Today 17:00', 'اليوم 17:00', DueTone::Today],
            ['2026-10-08 12:00:00', true, 'Tomorrow', 'غداً', DueTone::Upcoming],
            ['2026-10-10 09:00:00', true, 'Saturday', 'السبت', DueTone::Upcoming],
            ['2026-10-13 09:00:00', true, 'Tuesday', 'الثلاثاء', DueTone::Upcoming],
            ['2026-10-14 09:00:00', true, '2026-10-14', '2026-10-14', DueTone::Upcoming],
            ['2026-10-06 23:00:00', true, '1 day overdue', 'متأخرة يوماً واحداً', DueTone::Overdue],
            ['2026-10-05 09:00:00', true, '2 days overdue', 'متأخرة يومين', DueTone::Overdue],
            ['2026-10-04 09:00:00', true, '3 days overdue', 'متأخرة 3 أيام', DueTone::Overdue],
            ['2026-09-26 09:00:00', true, '11 days overdue', 'متأخرة 11 يوماً', DueTone::Overdue],
            ['2026-06-29 09:00:00', true, '100 days overdue', 'متأخرة 100 يوم', DueTone::Overdue],
            ['2026-10-06 09:00:00', false, 'Yesterday', 'أمس', DueTone::Closed],
            ['2026-10-07 09:00:00', false, 'Today 09:00', 'اليوم 09:00', DueTone::Closed],
            ['2026-09-01 09:00:00', false, '2026-09-01', '2026-09-01', DueTone::Closed],
        ];

        foreach ($cases as [$due, $open, $english, $arabic, $tone]) {
            app()->setLocale('en');
            $read = $dates->forMoment(Carbon::parse($due), $open);
            $this->assertSame($english, $read->text, "{$due} in English");
            $this->assertSame($tone, $read->tone, "{$due}: tone");

            app()->setLocale('ar');
            $this->assertSame($arabic, $dates->forMoment(Carbon::parse($due), $open)->text, "{$due} in Arabic");
        }

        $this->assertSame('2026-10-04 09:00', $dates->forMoment(Carbon::parse('2026-10-04 09:00:00'))->absolute());
    }

    #[Test]
    public function a_closed_or_deleted_task_reads_its_date_without_a_warning(): void
    {
        $dates = app(RelativeDueDates::class);
        $task = app(TaskService::class)->create(['title' => 'Late one', 'assignee_id' => $this->admin->getKey(), 'due_at' => '2026-10-01 09:00:00'], $this->admin);

        $this->assertSame(DueTone::Overdue, $dates->for($task)?->tone);

        $task->delete();
        $this->assertSame(DueTone::Closed, $dates->for($task->refresh())?->tone, 'a deleted task is frozen, never overdue');

        $task->restore();
        app(TaskService::class)->complete($task->refresh(), $this->admin);
        $this->assertSame(DueTone::Closed, $dates->for($task->refresh())?->tone);

        $undated = app(TaskService::class)->create(['title' => 'Someday', 'assignee_id' => $this->admin->getKey()], $this->admin);
        $this->assertNull($dates->for($undated));
        $this->assertNull(TaskVisuals::relativeDue($undated));
    }

    #[Test]
    public function the_due_markup_carries_the_moment_the_tone_and_escaped_words(): void
    {
        app()->setLocale('ar');
        $task = app(TaskService::class)->create(['title' => 'Late one', 'assignee_id' => $this->admin->getKey(), 'due_at' => '2026-10-04 09:00:00'], $this->admin);

        $html = (string) TaskVisuals::relativeDue($task);

        $this->assertStringContainsString('<time class="crm-due crm-due--overdue"', $html);
        $this->assertStringContainsString('datetime="2026-10-04T09:00:00+03:00"', $html);
        $this->assertStringContainsString('title="2026-10-04 09:00"', $html);
        $this->assertStringContainsString('>متأخرة 3 أيام</time>', $html);
    }

    #[Test]
    public function the_status_pill_wears_the_status_colour_and_icon_in_every_place(): void
    {
        app()->setLocale('en');

        foreach (TaskStatus::cases() as $status) {
            $html = (string) TaskVisuals::statusPill($status);

            $this->assertStringContainsString('fi-badge', $html);
            // Gray is Filament's own badge colour, drawn without a colour class.
            $status->getColor() === 'gray'
                ? $this->assertStringNotContainsString('fi-color-', $html, "{$status->value}: the TaskStatus colour (gray)")
                : $this->assertStringContainsString('fi-color-'.$status->getColor(), $html, "{$status->value}: the TaskStatus colour");
            $this->assertStringContainsString(e($status->getLabel()), $html);
            $this->assertStringContainsString('<svg', $html, "{$status->value}: an icon, so the state never rests on colour alone");
        }

        $column = TaskVisuals::statusColumn(TextColumn::make('status'));
        $this->assertTrue($column->isBadge());
        $this->assertSame(Heroicon::OutlinedPlayCircle, $column->getIcon(TaskStatus::InProgress));
    }

    #[Test]
    public function the_priority_flag_is_named_for_screen_readers_in_both_languages(): void
    {
        app()->setLocale('en');
        $urgent = (string) TaskVisuals::priorityFlag(TaskPriority::Urgent);

        $this->assertStringContainsString('class="crm-flag crm-flag--urgent"', $urgent);
        $this->assertStringContainsString('role="img" aria-label="Priority: Urgent"', $urgent);
        $this->assertStringContainsString('<svg', $urgent);

        app()->setLocale('ar');
        $this->assertStringContainsString('aria-label="الأولوية: عالية"', (string) TaskVisuals::priorityFlag(TaskPriority::High));
        $this->assertStringContainsString('crm-flag--high', (string) TaskVisuals::priorityFlag(TaskPriority::High));

        $labelled = (string) TaskVisuals::priorityFlag(TaskPriority::Low, withLabel: true);
        $this->assertStringContainsString('<span class="crm-flag-label">منخفضة</span>', $labelled);
        $this->assertStringNotContainsString('role="img"', $labelled, 'beside its word the flag is decoration');
    }

    #[Test]
    public function the_avatar_is_local_escaped_and_named(): void
    {
        app()->setLocale('en');
        $avatar = (string) TaskVisuals::avatar('<b>Sara</b> Ali');

        $this->assertStringNotContainsString('<b>', $avatar, 'a name never becomes markup');
        $this->assertStringContainsString('aria-label="&lt;b&gt;Sara&lt;/b&gt; Ali"', $avatar);
        $this->assertStringContainsString('class="crm-avatar"', $avatar);
        $this->assertStringContainsString('--crm-avatar-h:', $avatar);
        $this->assertStringNotContainsString('http', $avatar, 'no outside avatar service');

        $nobody = (string) TaskVisuals::avatar(null);
        $this->assertStringContainsString('crm-avatar--empty', $nobody);
        $this->assertStringContainsString('aria-label="'.__('assignment.placeholders.unassigned').'"', $nobody);
    }

    #[Test]
    public function the_checklist_meter_reads_done_over_total_and_never_overflows(): void
    {
        app()->setLocale('en');
        $meter = (string) TaskVisuals::checklistMeter(2, 5);

        $this->assertStringContainsString('style="inline-size: 40%"', $meter);
        $this->assertStringContainsString('<span class="crm-meter-text" dir="ltr" aria-hidden="true">2/5</span>', $meter);
        $this->assertStringContainsString('aria-label="2 of 5 checklist items done"', $meter);
        $this->assertStringNotContainsString('crm-meter--done', $meter);

        app()->setLocale('ar');
        $this->assertStringContainsString('aria-label="أُنجز 2 من 5 بنود في قائمة التحقق"', (string) TaskVisuals::checklistMeter(2, 5));
        $this->assertStringContainsString('aria-label="أُنجز 1 من بندين في قائمة التحقق"', (string) TaskVisuals::checklistMeter(1, 2));

        $over = (string) TaskVisuals::checklistMeter(7, 5);
        $this->assertStringContainsString('inline-size: 100%', $over);
        $this->assertStringContainsString('crm-meter--done', $over);
        $this->assertStringContainsString('5/5', $over);

        $this->assertStringContainsString('inline-size: 0%', (string) TaskVisuals::checklistMeter(-3, 4));
        $this->assertNull(TaskVisuals::checklistMeter(0, 0));

        $task = app(TaskService::class)->create([
            'title' => 'With steps',
            'assignee_id' => $this->admin->getKey(),
            'checklist' => [['body' => 'One'], ['body' => 'Two'], ['body' => 'Three']],
        ], $this->admin);
        $this->assertStringContainsString('0/3', (string) TaskVisuals::checklistMeterFor($task), 'read from the database when the query carried no counts');
    }

    #[Test]
    public function the_blade_components_render_the_same_pieces(): void
    {
        app()->setLocale('en');
        $task = app(TaskService::class)->create(['title' => 'Due tomorrow', 'assignee_id' => $this->admin->getKey(), 'due_at' => '2026-10-08 09:00:00', 'priority' => TaskPriority::Urgent->value], $this->admin);
        $undated = app(TaskService::class)->create(['title' => 'Undated', 'assignee_id' => $this->admin->getKey()], $this->admin);

        $this->blade('<x-task.status-pill :status="$status" />', ['status' => TaskStatus::InProgress])->assertSee('In progress');
        $this->blade('<x-task.priority-flag :priority="$priority" />', ['priority' => TaskPriority::Urgent])->assertSee('Priority: Urgent');
        $this->blade('<x-task.priority-flag :priority="$priority" with-label />', ['priority' => TaskPriority::High])->assertSee('High');
        $this->blade('<x-task.avatar :name="$name" />', ['name' => 'Huda Saleh'])->assertSee('HS');
        $this->blade('<x-task.avatar />')->assertSee('crm-avatar--empty', false);
        $this->blade('<x-task.due :task="$task" />', ['task' => $task])->assertSee('Tomorrow')->assertSee('crm-due--upcoming', false);
        $this->blade('<x-task.due :task="$task" />', ['task' => $undated])->assertSee('No date');
        $this->assertSame('', trim((string) $this->blade('<x-task.checklist-meter :task="$task" />', ['task' => $undated])));
    }

    #[Test]
    public function the_column_factories_render_the_same_pieces(): void
    {
        app()->setLocale('en');
        $task = app(TaskService::class)->create(['title' => 'Due tomorrow', 'assignee_id' => $this->admin->getKey(), 'due_at' => '2026-10-08 09:00:00', 'priority' => TaskPriority::High->value], $this->admin);

        $due = TaskVisuals::dueColumn(TextColumn::make('due_at'))->record($task);
        $this->assertStringContainsString('>Tomorrow</time>', (string) $due->formatState($task->due_at));
        $this->assertSame('2026-10-08 09:00', $due->getTooltip());

        $priority = TaskVisuals::priorityColumn(TextColumn::make('priority'))->record($task);
        $this->assertStringContainsString('crm-flag--high', (string) $priority->formatState($task->priority));
    }

    #[Test]
    public function the_theme_defines_every_task_token_for_both_themes(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['--crm-due-overdue', '--crm-due-today', '--crm-flag-urgent', '--crm-flag-high', '--crm-meter-done'] as $token) {
            $this->assertSame(2, preg_match_all('/^\s*'.preg_quote($token, '/').':/m', $css), "{$token} is set for the light and the dark theme");
        }

        foreach (['.crm-flag', '.crm-flag--urgent', '.crm-flag--high', '.crm-meter-fill', '.crm-meter--done .crm-meter-fill', '.crm-due--overdue', '.crm-due--today', '.crm-due--closed', '.crm-avatar--empty'] as $selector) {
            $this->assertStringContainsString($selector.' {', $css, "{$selector} is styled");
        }
    }
}
