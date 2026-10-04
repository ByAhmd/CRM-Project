<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\RoutineTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRoutineTasks;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-22: how a routine is saved and when it runs next. The next run
 * day is the first organisation day on or after today that matches the
 * schedule — weekly on a weekday, monthly on a day 1–28 — recomputed when the
 * schedule changes or the routine is resumed, cleared while it is paused, and
 * never a day already produced. "Today" is the organisation's, never the
 * server clock's. 2026-10-04 is a Sunday.
 */
final class RoutineTaskScheduleTest extends TestCase
{
    use BuildsRoutineTasks;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->travelToRiyadh('2026-10-04 10:00');
    }

    #[Test]
    public function a_weekly_routine_saved_on_its_own_weekday_runs_today(): void
    {
        $routine = $this->routine($this->admin(), ['weekday' => 0], $this->salesRep());

        $this->assertSame('2026-10-04', $routine->next_run_on?->toDateString());
        $this->assertTrue($routine->is_active);
    }

    #[Test]
    public function a_weekly_routine_runs_next_on_its_coming_weekday(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $this->assertSame('2026-10-07', $this->routine($admin, ['weekday' => 3], $rep)->next_run_on?->toDateString());
        $this->assertSame('2026-10-10', $this->routine($admin, ['weekday' => 6], $rep)->next_run_on?->toDateString());
        $this->assertSame('2026-10-05', $this->routine($admin, ['weekday' => 1], $rep)->next_run_on?->toDateString());
    }

    #[Test]
    public function a_monthly_routine_runs_on_its_day_this_month_or_next(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $monthly = static fn (int $day): array => ['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => $day];

        $this->assertSame('2026-10-04', $this->routine($admin, $monthly(4), $rep)->next_run_on?->toDateString(), 'its day is today');
        $this->assertSame('2026-10-28', $this->routine($admin, $monthly(28), $rep)->next_run_on?->toDateString());
        $this->assertSame('2026-11-03', $this->routine($admin, $monthly(3), $rep)->next_run_on?->toDateString(), 'its day has passed this month');
    }

    #[Test]
    public function day_28_exists_in_every_month_including_february(): void
    {
        $this->travelToRiyadh('2027-01-29 09:00');

        $routine = $this->routine($this->admin(), ['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 28], $this->salesRep());

        $this->assertSame('2027-02-28', $routine->next_run_on?->toDateString());

        $service = app(RoutineTaskService::class);
        $this->assertSame('2027-03-28', $service->firstRunOnOrAfter($routine, CarbonImmutable::parse('2027-03-01'))->toDateString());
        $this->assertSame('2027-02-28', $service->latestRunOnOrBefore($routine, CarbonImmutable::parse('2027-03-27'))->toDateString());
        $this->assertSame('2026-12-28', $service->latestRunOnOrBefore($routine, CarbonImmutable::parse('2027-01-27'))->toDateString(), 'across the year boundary');
    }

    #[Test]
    public function the_latest_matching_day_on_or_before_a_date_is_found_for_both_frequencies(): void
    {
        $service = app(RoutineTaskService::class);
        $weekly = $this->routine($this->admin(), ['weekday' => 0], $this->salesRep());
        $monthly = $this->routine($this->admin(), ['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 5], $this->salesRep());

        $this->assertSame('2026-10-04', $service->latestRunOnOrBefore($weekly, CarbonImmutable::parse('2026-10-07'))->toDateString());
        $this->assertSame('2026-10-04', $service->latestRunOnOrBefore($weekly, CarbonImmutable::parse('2026-10-04'))->toDateString(), 'the day itself counts');
        $this->assertSame('2026-09-27', $service->latestRunOnOrBefore($weekly, CarbonImmutable::parse('2026-10-03'))->toDateString());
        $this->assertSame('2026-09-05', $service->latestRunOnOrBefore($monthly, CarbonImmutable::parse('2026-10-04'))->toDateString());
        $this->assertSame('2026-10-05', $service->latestRunOnOrBefore($monthly, CarbonImmutable::parse('2026-10-05'))->toDateString());
    }

    #[Test]
    public function pausing_clears_the_next_run_and_resuming_recomputes_it_from_today(): void
    {
        $admin = $this->admin();
        $routine = $this->routine($admin, ['weekday' => 3], $this->salesRep());
        $service = app(RoutineTaskService::class);

        $this->assertSame('2026-10-07', $routine->next_run_on?->toDateString());

        $service->pause($routine, $admin);

        $stored = $this->stored($routine);
        $this->assertFalse($stored->is_active);
        $this->assertNull($stored->next_run_on);

        // Thursday: the Wednesday it was due on has passed while it was paused.
        $this->travelToRiyadh('2026-10-08 09:00');
        $service->resume($routine, $admin);

        $stored = $this->stored($routine);
        $this->assertTrue($stored->is_active);
        $this->assertSame('2026-10-14', $stored->next_run_on?->toDateString());
    }

    #[Test]
    public function changing_the_schedule_recomputes_the_next_run_and_other_edits_keep_it(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, ['weekday' => 3], $rep);
        $service = app(RoutineTaskService::class);

        $this->travelToRiyadh('2026-10-05 09:00'); // Monday

        $service->save(['title' => 'Count the warehouse', 'due_time' => '08:00', 'due_in_days' => 2], $admin, $routine);
        $stored = $this->stored($routine);
        $this->assertSame('2026-10-07', $stored->next_run_on?->toDateString(), 'the run days did not change');

        $service->save(['weekday' => 1], $admin, $routine);
        $stored = $this->stored($routine);
        $this->assertSame('2026-10-05', $stored->next_run_on?->toDateString(), 'Monday is today');

        $service->save(['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 20], $admin, $routine);
        $stored = $this->stored($routine);
        $this->assertSame('2026-10-20', $stored->next_run_on?->toDateString());
        $this->assertNull($stored->weekday, 'a monthly routine keeps no weekday');
    }

    #[Test]
    public function a_day_already_produced_is_never_chosen_again(): void
    {
        $admin = $this->admin();
        $routine = $this->routine($admin, ['weekday' => 0], $this->salesRep());
        $service = app(RoutineTaskService::class);

        $this->assertSame(1, $service->generateDue());
        $stored = $this->stored($routine);
        $this->assertSame('2026-10-04', $stored->last_run_on?->toDateString());

        // Paused and resumed the same Sunday: today's tasks are out already.
        $service->pause($routine, $admin);
        $service->resume($routine, $admin);
        $stored = $this->stored($routine);
        $this->assertSame('2026-10-11', $stored->next_run_on?->toDateString());

        // A schedule change that lands on today again does not hand today out twice.
        $service->save(['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 4], $admin, $routine);
        $stored = $this->stored($routine);
        $this->assertSame('2026-11-04', $stored->next_run_on?->toDateString());
        $this->assertSame(0, $service->generateDue());
    }

    #[Test]
    public function today_is_the_organisations_day_not_the_servers(): void
    {
        // 23:30 UTC on Saturday is already 02:30 on Sunday in Riyadh.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 23:30', 'UTC'));
        $admin = $this->admin();
        $rep = $this->salesRep();

        $this->assertSame('2026-10-10', $this->routine($admin, ['weekday' => 6], $rep)->next_run_on?->toDateString(), 'Riyadh: Saturday has passed');

        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $admin);

        $this->assertSame('2026-10-03', $this->routine($admin, ['weekday' => 6], $rep)->next_run_on?->toDateString(), 'UTC: it is still Saturday');
        $this->assertSame('2026-10-03', app(RoutineTaskService::class)->organisationToday()->toDateString());
    }

    #[Test]
    public function a_save_normalises_the_schedule_and_applies_the_defaults(): void
    {
        $admin = $this->admin();

        $routine = app(RoutineTaskService::class)->save([
            'title' => '  Weekly report  ',
            'description' => '   ',
            'frequency' => RoutineFrequency::Weekly->value,
            'weekday' => '2',
            'day_of_month' => 15,
            'assignees' => [(string) $this->salesRep()->getKey()],
        ], $admin);

        $stored = $this->stored($routine);
        $this->assertSame('Weekly report', $stored->title);
        $this->assertNull($stored->description);
        $this->assertSame(2, $stored->weekday);
        $this->assertNull($stored->day_of_month, 'a weekly routine keeps no day of the month');
        $this->assertSame('17:00:00', $stored->due_time);
        $this->assertSame(0, $stored->due_in_days);
        $this->assertSame(TaskPriority::Medium, $stored->priority);
        $this->assertTrue($stored->is_active);

        app(RoutineTaskService::class)->save(['due_time' => '9:05'], $admin, $routine);
        $this->assertSame('09:05:00', $this->stored($routine)->due_time);
    }

    #[Test]
    public function the_assigner_is_whoever_last_saved_the_routine_not_who_paused_it(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $routine = $this->routine($admin, [], $this->salesRep());
        $service = app(RoutineTaskService::class);

        $this->assertSame($admin->getKey(), $this->stored($routine)->assigned_by);

        $service->pause($routine, $other);
        $service->resume($routine, $other);
        $this->assertSame($admin->getKey(), $this->stored($routine)->assigned_by, 'pausing and resuming do not take the routine over');

        $service->save(['title' => 'Reworded'], $other, $routine);
        $this->assertSame($other->getKey(), $this->stored($routine)->assigned_by);
    }

    #[Test]
    public function a_save_by_someone_else_takes_the_routine_over_and_the_ledger_says_so(): void
    {
        $admin = $this->makeUser(CrmRole::Admin, ['name' => 'First Admin']);
        $other = $this->makeUser(CrmRole::Admin, ['name' => 'Second Admin']);
        $routine = $this->routine($admin, [], $this->salesRep());
        $service = app(RoutineTaskService::class);
        $ledger = static fn (): int => DB::table('activity_log')->where('subject_type', TaskTemplate::class)->where('subject_id', $routine->getKey())->count();
        $before = $ledger();

        // Nothing else changes, yet the tasks are handed out in the saver's name from now on.
        $service->save([], $other, $routine);

        $this->assertSame($before + 1, $ledger());
        $row = ActivityLog::query()->where('subject_type', TaskTemplate::class)->where('subject_id', $routine->getKey())->latest('id')->firstOrFail();
        $this->assertSame(ActivityLogEvent::LookupUpdated->value, $row->description);
        $this->assertSame($other->getKey(), (int) $row->causer_id);
        $this->assertSame('Second Admin', $row->properties['assigner'] ?? null);
        $this->assertSame('First Admin', $row->properties['previous_assigner'] ?? null);
        $this->assertArrayNotHasKey('added', $row->properties->all());

        foreach (['en' => ['Now handed out by', 'Previously handed out by'], 'ar' => ['تُسنَد المهام الآن باسم', 'كانت تُسنَد المهام باسم']] as $locale => $labels) {
            app()->setLocale($locale);
            $shown = array_column((new ActivityLogPresenter($row))->detailRows(), 'value', 'label');
            $this->assertSame('Second Admin', $shown[$labels[0]] ?? null, "{$locale}: the new assigner is labelled");
            $this->assertSame('First Admin', $shown[$labels[1]] ?? null, "{$locale}: the previous assigner is labelled");
        }

        app()->setLocale((string) config('app.locale'));

        // The assigner saving again unchanged writes nothing.
        $service->save([], $other, $routine);
        $this->assertSame($before + 1, $ledger());
    }

    #[Test]
    public function the_stamps_cannot_be_written_through_the_saved_data(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $routine = app(RoutineTaskService::class)->save([
            'title' => 'Weekly report',
            'frequency' => RoutineFrequency::Weekly->value,
            'weekday' => 3,
            'assignees' => [$this->salesRep()->getKey()],
            'assigned_by' => $other->getKey(),
            'next_run_on' => '2030-01-01',
            'last_run_on' => '2026-10-07',
        ], $admin);

        $stored = $this->stored($routine);
        $this->assertSame($admin->getKey(), $stored->assigned_by);
        $this->assertSame('2026-10-07', $stored->next_run_on?->toDateString(), 'computed from the schedule, not taken from the data');
        $this->assertNull($stored->last_run_on);
    }

    #[Test]
    public function a_save_refuses_an_incomplete_or_out_of_range_schedule(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $this->assertRefused(['frequency' => RoutineFrequency::Weekly->value, 'weekday' => null], 'weekday', $admin, $rep);
        $this->assertRefused(['frequency' => RoutineFrequency::Weekly->value, 'weekday' => 7], 'weekday', $admin, $rep);
        $this->assertRefused(['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => null], 'day_of_month', $admin, $rep);
        $this->assertRefused(['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 29], 'day_of_month', $admin, $rep);
        $this->assertRefused(['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 0], 'day_of_month', $admin, $rep);
        $this->assertRefused(['frequency' => 'daily'], 'frequency', $admin, $rep);
        $this->assertRefused(['due_time' => '25:00'], 'due_time', $admin, $rep);
        $this->assertRefused(['due_in_days' => 31], 'due_in_days', $admin, $rep);
        $this->assertRefused(['due_in_days' => -1], 'due_in_days', $admin, $rep);
        $this->assertRefused(['title' => '   '], 'title', $admin, $rep);
        $this->assertRefused(['title' => str_repeat('a', RoutineTaskService::TITLE_MAX + 1)], 'title', $admin, $rep);
        $this->assertRefused(['description' => str_repeat('a', RoutineTaskService::DESCRIPTION_MAX + 1)], 'description', $admin, $rep);
        $this->assertRefused(['priority' => 'whenever'], 'priority', $admin, $rep);
        $this->assertRefused(['assignees' => []], 'assignees', $admin);

        $this->assertSame(0, TaskTemplate::query()->count());
    }

    #[Test]
    public function every_assignee_must_be_within_the_savers_assignment_reach(): void
    {
        $team = $this->makeTeam();
        $dispatcher = $this->userWithPermissions($team, [Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskAssign]);
        $teammate = $this->salesRep($team);
        $stranger = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $employee = $this->employee($team);

        // D-15: an employee can be handed tasks; a teammate is within reach.
        $routine = $this->routine($dispatcher, [], $teammate, $employee);
        $this->assertCount(2, $routine->assignees()->get());

        $this->assertRefused([], 'assignees', $dispatcher, $teammate, $stranger);

        // Someone who may not open tasks at all is never a routine's assignee.
        $outsider = $this->userWithPermissions($team, []);
        $this->assertRefused([], 'assignees', $this->admin(), $outsider);

        // An assignee who has since been disabled must be taken off before the next save.
        $teammate->forceFill(['status' => UserStatus::Disabled])->save();
        $message = $this->assertRefusedEdit($routine, ['title' => 'Reworded'], 'assignees', $dispatcher);
        $this->assertStringContainsString($teammate->name, $message, 'the refusal names whom to take off');
        app(RoutineTaskService::class)->save(['title' => 'Reworded', 'assignees' => [$employee->getKey()]], $dispatcher, $routine);
        $this->assertSame([$employee->getKey()], $routine->assignees()->pluck('users.id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    #[Test]
    public function every_save_pause_and_assignee_change_is_audited_as_configuration(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $colleague = $this->salesRep();
        $routine = $this->routine($admin, [], $rep);

        $created = DB::table('activity_log')
            ->where('subject_type', TaskTemplate::class)->where('subject_id', $routine->getKey())
            ->where('description', ActivityLogEvent::LookupCreated->value)->first();
        $this->assertNotNull($created);
        $this->assertSame($admin->getKey(), (int) $created->causer_id);

        $service = app(RoutineTaskService::class);
        $service->save(['assignees' => [$colleague->getKey()]], $admin, $routine);

        $assignees = DB::table('activity_log')
            ->where('subject_type', TaskTemplate::class)->where('subject_id', $routine->getKey())
            ->where('description', ActivityLogEvent::LookupUpdated->value)
            ->orderByDesc('id')->value('properties');
        $properties = json_decode((string) $assignees, true);
        $this->assertSame([$colleague->name], $properties['added'] ?? null);
        $this->assertSame([$rep->name], $properties['removed'] ?? null);

        $service->pause($routine, $admin);

        $paused = DB::table('activity_log')
            ->where('subject_type', TaskTemplate::class)->where('subject_id', $routine->getKey())
            ->where('description', ActivityLogEvent::LookupUpdated->value)
            ->orderByDesc('id')->value('properties');
        $properties = json_decode((string) $paused, true);
        $this->assertSame(['is_active' => false], $properties['attributes'] ?? null);
        $this->assertSame(['is_active' => true], $properties['old'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRefused(array $attributes, string $field, User $actor, User ...$assignees): void
    {
        try {
            $this->routine($actor, $attributes, ...$assignees);
            $this->fail("the save was not refused on {$field}");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), "refused, but not on {$field}: ".json_encode($exception->errors()));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRefusedEdit(TaskTemplate $routine, array $attributes, string $field, User $actor): string
    {
        try {
            app(RoutineTaskService::class)->save($attributes, $actor, $routine);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return implode(' ', $exception->errors()[$field]);
        }

        $this->fail("the save was not refused on {$field}");
    }
}
