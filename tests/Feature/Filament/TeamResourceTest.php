<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ActivityLogEvent;
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
        $manager = $this->salesManager();

        Livewire::actingAs($admin)
            ->test(CreateTeam::class)
            ->fillForm([
                'name_ar' => 'فريق الشرقية',
                'name_en' => 'Eastern Team',
                'manager_user_id' => $manager->getKey(),
                'is_active' => true,
                'sort' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $team = Team::query()->where('name_en', 'Eastern Team')->firstOrFail();

        $this->assertSame($manager->getKey(), $team->manager_user_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TeamCreated->value, 'subject_id' => $team->getKey()]);
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
