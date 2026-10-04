<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Enums\UserStatus;
use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\TaskTemplates\Tables\TaskTemplatesTable;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\RoutineTaskService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRoutineTasks;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The routine task screen (decision D-22): holders of `task.assign` create
 * and edit weekly and monthly routines through the form, read each schedule
 * in their own language, and pause, resume and delete routines from the list
 * and the edit page — deleting one keeps the tasks it created. Every write
 * goes through RoutineTaskService. 2026-10-04 is a Sunday.
 */
final class RoutineTaskResourceTest extends TestCase
{
    use BuildsRoutineTasks;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->travelToRiyadh('2026-10-04 10:00');
    }

    #[Test]
    public function an_admin_creates_a_weekly_routine_through_the_form(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $employee = $this->employee();

        Livewire::actingAs($admin)
            ->test(CreateTaskTemplate::class)
            ->fillForm([
                'title' => 'Weekly stock count',
                'description' => 'Count the shelves and post the totals.',
                'priority' => TaskPriority::High->value,
                'is_active' => true,
                'frequency' => RoutineFrequency::Weekly->value,
                'weekday' => 3,
                'due_time' => '09:30',
                'due_in_days' => 1,
                'assignees' => [$rep->getKey(), $employee->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('task_templates.notifications.created'));

        $routine = TaskTemplate::query()->sole();
        $this->assertSame('Weekly stock count', $routine->title);
        $this->assertSame(RoutineFrequency::Weekly, $routine->frequency);
        $this->assertSame(3, $routine->weekday);
        $this->assertNull($routine->day_of_month);
        $this->assertSame('09:30:00', $routine->due_time);
        $this->assertSame(1, $routine->due_in_days);
        $this->assertSame(TaskPriority::High, $routine->priority);
        $this->assertSame($admin->getKey(), $routine->assigned_by);
        $this->assertSame('2026-10-07', $routine->next_run_on?->toDateString());
        $this->assertEqualsCanonicalizing(
            [$rep->getKey(), $employee->getKey()],
            $routine->assignees()->pluck('users.id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
    }

    #[Test]
    public function an_admin_creates_a_monthly_routine_through_the_form(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateTaskTemplate::class)
            ->fillForm([
                'title' => 'Monthly report',
                'frequency' => RoutineFrequency::Monthly->value,
                'day_of_month' => 15,
                'assignees' => [$this->salesRep()->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $routine = TaskTemplate::query()->sole();
        $this->assertSame(RoutineFrequency::Monthly, $routine->frequency);
        $this->assertSame(15, $routine->day_of_month);
        $this->assertNull($routine->weekday);
        $this->assertSame('17:00:00', $routine->due_time, 'the default due time');
        $this->assertSame(0, $routine->due_in_days, 'the default: due on the day itself');
        $this->assertSame('2026-10-15', $routine->next_run_on?->toDateString());
    }

    #[Test]
    public function the_weekday_and_the_day_of_the_month_follow_the_frequency(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateTaskTemplate::class)
            ->fillForm(['frequency' => RoutineFrequency::Weekly->value])
            ->assertFormFieldVisible('weekday')
            ->assertFormFieldHidden('day_of_month')
            ->fillForm(['frequency' => RoutineFrequency::Monthly->value])
            ->assertFormFieldHidden('weekday')
            ->assertFormFieldVisible('day_of_month');
    }

    #[Test]
    public function the_form_refuses_missing_and_out_of_range_fields(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateTaskTemplate::class)
            ->fillForm(['title' => '', 'frequency' => RoutineFrequency::Weekly->value, 'weekday' => null, 'assignees' => []])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required', 'weekday' => 'required', 'assignees' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateTaskTemplate::class)
            ->fillForm([
                'title' => 'Monthly report',
                'frequency' => RoutineFrequency::Monthly->value,
                'day_of_month' => null,
                'due_in_days' => 31,
                'assignees' => [$this->salesRep()->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['day_of_month' => 'required', 'due_in_days' => 'max']);

        $this->assertSame(0, TaskTemplate::query()->count());
    }

    #[Test]
    public function the_form_offers_only_users_the_actor_may_hand_tasks_to(): void
    {
        $team = $this->makeTeam();
        $dispatcher = $this->userWithPermissions($team, [Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskAssign]);
        $teammate = $this->salesRep($team);
        $stranger = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        Livewire::actingAs($dispatcher)
            ->test(CreateTaskTemplate::class)
            ->assertFormFieldExists('assignees', static function (Select $field) use ($dispatcher, $teammate, $stranger): bool {
                $offered = array_map('intval', array_keys($field->getOptions()));

                return in_array($teammate->getKey(), $offered, true)
                    && in_array($dispatcher->getKey(), $offered, true)
                    && ! in_array($stranger->getKey(), $offered, true);
            })
            ->fillForm(['title' => 'Team chores', 'frequency' => RoutineFrequency::Weekly->value, 'weekday' => 1, 'assignees' => [$teammate->getKey(), $stranger->getKey()]])
            ->call('create')
            ->assertHasFormErrors(['assignees.1']);

        $this->assertSame(0, TaskTemplate::query()->count());
    }

    #[Test]
    public function a_refusal_by_the_service_shows_under_its_field(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, [], $rep, $this->employee());
        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        // The disabled assignee is still listed, so the form validates; the
        // service refuses to hand them more work and says so on the field.
        Livewire::actingAs($admin)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->fillForm(['title' => 'Reworded'])
            ->call('save')
            ->assertHasFormErrors(['assignees']);

        $this->assertSame('Weekly stock count', $this->stored($routine)->title);
    }

    #[Test]
    public function editing_fills_the_form_and_makes_the_editor_the_assigner(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, ['weekday' => 0], $rep);

        Livewire::actingAs($other)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->assertFormSet([
                'title' => 'Weekly stock count',
                'weekday' => 0,
                'due_time' => '17:00',
                'assignees' => [$rep->getKey()],
            ])
            ->fillForm(['weekday' => 2])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('task_templates.notifications.saved'));

        $stored = $this->stored($routine);
        $this->assertSame(2, $stored->weekday);
        $this->assertSame($other->getKey(), $stored->assigned_by);
        $this->assertSame('2026-10-06', $stored->next_run_on?->toDateString());
    }

    #[Test]
    public function the_due_time_stays_as_typed_when_the_organisation_timezone_is_not_the_servers(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        // The panel shows date-times in the organisation timezone; the server
        // (app.timezone) stays on Riyadh, three hours ahead of UTC.
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $admin);

        Livewire::actingAs($admin)
            ->test(CreateTaskTemplate::class)
            ->fillForm([
                'title' => 'Sunday count',
                'frequency' => RoutineFrequency::Weekly->value,
                'weekday' => 0,
                'due_time' => '09:30',
                'assignees' => [$rep->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $routine = TaskTemplate::query()->sole();
        $this->assertSame('09:30:00', $routine->due_time, 'a time of day is never shifted by the timezone difference');
        $this->assertSame(0, $routine->weekday, 'Sunday is day 0');

        Livewire::actingAs($admin)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->assertFormSet(['due_time' => '09:30', 'weekday' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('09:30:00', $this->stored($routine)->due_time, 'an unchanged save keeps it');

        // 07:00 UTC on Sunday: the task is due at 09:30 organisation time.
        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());
        $this->assertTrue(Task::query()->sole()->due_at?->equalTo(CarbonImmutable::parse('2026-10-04 09:30', 'UTC')));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function locales(): array
    {
        return [
            'arabic' => ['ar', ['كل أحد · الاستحقاق 17:00', 'شهرياً في اليوم 5 · الاستحقاق بعد يومين الساعة 09:00', 'متوقفة مؤقتاً']],
            'english' => ['en', ['Every Sunday · due 17:00', 'Monthly on day 5 · due 2 days later at 09:00', 'Paused']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('locales')]
    public function the_list_reads_each_schedule_in_the_readers_language(string $locale, array $expected): void
    {
        $admin = $this->makeUser(CrmRole::Admin, ['name' => 'Admin User', 'locale' => $locale]);
        $rep = $this->salesRep();
        $this->routine($admin, ['title' => 'Stock count'], $rep);
        $this->routine($admin, ['title' => 'Monthly report', 'frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 5, 'due_time' => '09:00', 'due_in_days' => 2], $rep);
        app(RoutineTaskService::class)->pause($this->routine($admin, ['title' => 'Paused chores', 'weekday' => 4], $rep), $admin);

        $response = $this->actingAs($admin)->get(TaskTemplateResource::getUrl('index'))->assertOk();

        foreach ($expected as $text) {
            $response->assertSee($text);
        }

        $response->assertSee($rep->name)->assertSee('2026-10-04');
    }

    #[Test]
    public function the_day_offset_is_counted_in_both_languages(): void
    {
        $routine = TaskTemplate::factory()->make(['due_time' => '08:15:00']);

        $expected = [
            'en' => [1 => 'Every Sunday · due 1 day later at 08:15', 3 => 'Every Sunday · due 3 days later at 08:15', 11 => 'Every Sunday · due 11 days later at 08:15'],
            'ar' => [1 => 'كل أحد · الاستحقاق بعد يوم واحد الساعة 08:15', 3 => 'كل أحد · الاستحقاق بعد 3 أيام الساعة 08:15', 11 => 'كل أحد · الاستحقاق بعد 11 يوماً الساعة 08:15'],
        ];

        try {
            foreach ($expected as $locale => $summaries) {
                app()->setLocale($locale);

                foreach ($summaries as $days => $summary) {
                    $routine->due_in_days = $days;
                    $this->assertSame($summary, TaskTemplatesTable::scheduleSummary($routine));
                }
            }
        } finally {
            app()->setLocale((string) config('app.locale'));
        }
    }

    #[Test]
    public function the_list_filters_by_assignee_active_state_and_frequency(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $employee = $this->employee();
        $repWeekly = $this->routine($admin, ['title' => 'Rep weekly'], $rep);
        $employeeMonthly = $this->routine($admin, ['title' => 'Employee monthly', 'frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 5], $employee);
        $paused = $this->routine($admin, ['title' => 'Paused for both', 'is_active' => false], $rep, $employee);

        Livewire::actingAs($admin)
            ->test(ListTaskTemplates::class)
            ->assertCanSeeTableRecords([$repWeekly, $employeeMonthly, $paused])
            ->filterTable('assignee', $employee->getKey())
            ->assertCanSeeTableRecords([$employeeMonthly, $paused])
            ->assertCanNotSeeTableRecords([$repWeekly])
            ->resetTableFilters()
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$paused])
            ->assertCanNotSeeTableRecords([$repWeekly, $employeeMonthly])
            ->resetTableFilters()
            ->filterTable('frequency', RoutineFrequency::Monthly->value)
            ->assertCanSeeTableRecords([$employeeMonthly])
            ->assertCanNotSeeTableRecords([$repWeekly, $paused]);
    }

    #[Test]
    public function a_routine_is_paused_and_resumed_from_the_list(): void
    {
        $admin = $this->admin();
        $routine = $this->routine($admin, ['weekday' => 3], $this->salesRep());

        Livewire::actingAs($admin)
            ->test(ListTaskTemplates::class)
            ->assertTableActionVisible('pause', $routine)
            ->assertTableActionHidden('resume', $routine)
            ->callTableAction('pause', $routine)
            ->assertNotified(__('task_templates.notifications.paused'));

        $paused = $this->stored($routine);
        $this->assertFalse($paused->is_active);
        $this->assertNull($paused->next_run_on);

        $this->travelToRiyadh('2026-10-08 09:00');

        Livewire::actingAs($admin)
            ->test(ListTaskTemplates::class)
            ->assertTableActionHidden('pause', $paused)
            ->callTableAction('resume', $paused)
            ->assertNotified(__('task_templates.notifications.resumed'));

        $resumed = $this->stored($routine);
        $this->assertTrue($resumed->is_active);
        $this->assertSame('2026-10-14', $resumed->next_run_on?->toDateString());
    }

    #[Test]
    public function the_edit_page_pauses_the_routine_and_shows_the_switch_off(): void
    {
        $admin = $this->admin();
        $routine = $this->routine($admin, [], $this->salesRep());

        Livewire::actingAs($admin)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->assertActionVisible('pause')
            ->assertActionHidden('resume')
            ->callAction('pause')
            ->assertFormSet(['is_active' => false]);

        $this->assertFalse($this->stored($routine)->is_active);
    }

    #[Test]
    public function deleting_from_the_list_or_in_bulk_keeps_the_tasks_already_created(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $first = $this->routine($admin, ['title' => 'First'], $rep);
        $second = $this->routine($admin, ['title' => 'Second'], $rep);
        $third = $this->routine($admin, ['title' => 'Third'], $rep);
        $this->assertSame(3, app(RoutineTaskService::class)->generateDue());

        Livewire::actingAs($admin)
            ->test(ListTaskTemplates::class)
            ->callTableAction('delete', $first)
            ->assertNotified(__('task_templates.notifications.deleted'));

        Livewire::actingAs($admin)
            ->test(ListTaskTemplates::class)
            ->callTableBulkAction('delete', [$second, $third]);

        $this->assertSame(0, TaskTemplate::query()->count());
        $this->assertSame(3, Task::query()->whereNull('template_id')->where('assignee_id', $rep->getKey())->count());
    }

    #[Test]
    public function deleting_from_the_edit_page_keeps_the_tasks_and_returns_to_the_list(): void
    {
        $admin = $this->admin();
        $routine = $this->routine($admin, [], $this->salesRep());
        app(RoutineTaskService::class)->generateDue();
        $this->assertSame(1, $routine->tasks()->count(), 'precondition: the routine produced a task');

        Livewire::actingAs($admin)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->callAction('delete')
            ->assertRedirect(TaskTemplateResource::getUrl('index'));

        $this->assertNull(TaskTemplate::query()->find($routine->getKey()));
        $this->assertNull(Task::query()->sole()->template_id);
    }

    #[Test]
    public function the_resource_sits_in_the_activities_group_right_after_the_tasks_board(): void
    {
        $this->actingAs($this->admin());

        $this->assertSame(NavigationGroup::Activities, TaskTemplateResource::getNavigationGroup());
        $this->assertSame(26, TaskTemplateResource::getNavigationSort());
        $this->assertFalse(TaskTemplateResource::canGloballySearch());
    }
}
