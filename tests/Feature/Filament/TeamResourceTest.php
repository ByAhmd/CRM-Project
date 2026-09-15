<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class TeamResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function admins_manage_teams_and_reps_are_refused(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $team = $this->makeTeam();

        $this->actingAs($admin)->get(TeamResource::getUrl('index'))->assertOk();
        $this->actingAs($rep)->get(TeamResource::getUrl('index'))->assertForbidden();

        Livewire::actingAs($admin)->test(ListTeams::class)->assertCanSeeTableRecords([$team]);
    }

    #[Test]
    public function a_team_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateTeam::class)
            ->assertFormFieldHidden('manager_user_id')
            ->fillForm([
                'name_ar' => 'فريق الشرقية',
                'name_en' => 'Eastern Team',
                'is_active' => true,
                'sort' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $team = Team::query()->where('name_en', 'Eastern Team')->firstOrFail();

        $this->assertNull($team->manager_user_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TeamCreated->value, 'subject_id' => $team->getKey()]);
    }

    #[Test]
    public function the_manager_is_chosen_among_the_active_members_of_the_team(): void
    {
        $admin = $this->admin();
        $team = $this->makeTeam();
        $member = $this->salesManager($team);
        $outsider = $this->salesManager();
        $disabledMember = $this->makeUser(CrmRole::SalesManager, ['status' => UserStatus::Disabled], $team);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->fillForm(['manager_user_id' => $outsider->getKey()])
            ->call('save')
            ->assertHasFormErrors(['manager_user_id']);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->fillForm(['manager_user_id' => $disabledMember->getKey()])
            ->call('save')
            ->assertHasFormErrors(['manager_user_id']);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->fillForm(['manager_user_id' => $member->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($member->getKey(), $team->refresh()->manager_user_id);
    }

    #[Test]
    public function a_manager_stored_before_the_membership_rule_stays_valid_on_an_unrelated_edit(): void
    {
        $admin = $this->admin();
        $outsider = $this->salesManager();
        $team = $this->makeTeam(manager: $outsider);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->fillForm(['sort' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $team->refresh();
        $this->assertSame(7, (int) $team->sort);
        $this->assertSame($outsider->getKey(), $team->manager_user_id);
    }

    #[Test]
    public function a_deleted_namesake_is_a_translated_validation_error_on_create_and_on_edit(): void
    {
        $admin = $this->admin();
        $this->makeTeam('Eastern Team', 'فريق الشرقية')->delete();
        $live = $this->makeTeam('Western Team', 'فريق الغربية');

        Livewire::actingAs($admin)
            ->test(CreateTeam::class)
            ->fillForm(['name_ar' => 'فريق الشرقية', 'name_en' => 'Eastern Team', 'is_active' => true, 'sort' => 1])
            ->call('create')
            ->assertHasFormErrors([
                'name_ar' => [__('teams.validation.name_ar_unique_trashed')],
                'name_en' => [__('teams.validation.name_en_unique_trashed')],
            ]);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $live->getRouteKey()])
            ->fillForm(['name_en' => 'Eastern Team'])
            ->call('save')
            ->assertHasFormErrors(['name_en' => [__('teams.validation.name_en_unique_trashed')]]);

        $this->assertSame(1, Team::withTrashed()->where('name_en', 'Eastern Team')->count());
        $this->assertSame('Western Team', $live->refresh()->name_en);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeTeam('Riyadh Team', 'فريق الرياض');

        Livewire::actingAs($admin)
            ->test(CreateTeam::class)
            ->fillForm(['name_ar' => 'فريق الرياض', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $team = $this->makeTeam('Riyadh Team', 'فريق الرياض');

        app()->setLocale('ar');
        $this->assertSame('فريق الرياض', $team->display_name);

        app()->setLocale('en');
        $this->assertSame('Riyadh Team', $team->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function a_team_is_soft_deleted_and_restorable(): void
    {
        $admin = $this->admin();
        $team = $this->makeTeam();

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('teams', ['id' => $team->getKey()]);

        Livewire::actingAs($admin)
            ->test(EditTeam::class, ['record' => $team->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($team->refresh()->deleted_at);
    }
}
