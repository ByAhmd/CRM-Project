<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ActivityLogEvent;
use App\Enums\BadgeColor;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Projects under Administration → Settings (decision D-30): a bilingual
 * lookup with a colour, an order and the two flags, open to settings.manage
 * only; the single-default rules reach the form as field errors, and the
 * default or a project in use is never offered a delete.
 */
final class ProjectResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function administrators_manage_projects_and_the_sales_roles_are_refused(): void
    {
        $default = $this->defaultProject();

        foreach ([$this->superAdmin(), $this->admin()] as $administrator) {
            $this->actingAs($administrator)->get(ProjectResource::getUrl('index'))->assertOk();
            $this->actingAs($administrator)->get(ProjectResource::getUrl('create'))->assertOk();
            $this->actingAs($administrator)->get(ProjectResource::getUrl('edit', ['record' => $default]))->assertOk();

            Livewire::actingAs($administrator)->test(ListProjects::class)->assertCanSeeTableRecords([$default]);
        }

        foreach ([$this->salesManager(), $this->salesRep()] as $refused) {
            // The Administration workspace itself is closed to them (D-23), and so is every page in it.
            $this->actingAs($refused)->get(ProjectResource::getUrl('index'))->assertForbidden();
            $this->actingAs($refused)->get(ProjectResource::getUrl('create'))->assertForbidden();
            $this->actingAs($refused)->get(ProjectResource::getUrl('edit', ['record' => $default]))->assertForbidden();

            Livewire::actingAs($refused)->test(ListProjects::class)->assertForbidden();
            Livewire::actingAs($refused)->test(CreateProject::class)->assertForbidden();
            Livewire::actingAs($refused)->test(EditProject::class, ['record' => $default->getRouteKey()])->assertForbidden();
        }
    }

    #[Test]
    public function a_project_is_created_with_both_names_a_colour_and_an_order_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateProject::class)
            ->fillForm([
                'name_ar' => 'إيقان',
                'name_en' => 'Iqan',
                'color' => BadgeColor::Success->value,
                'sort' => 20,
                'is_active' => true,
                'is_default' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name_en', 'Iqan')->firstOrFail();

        $this->assertSame('إيقان', $project->name_ar);
        $this->assertSame(BadgeColor::Success, $project->color);
        $this->assertSame(20, $project->sort);
        $this->assertTrue($project->is_active);
        $this->assertFalse($project->is_default);
        $this->assertSame(1, Project::query()->where('is_default', true)->count());
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'log_name' => ActivityLogEvent::LookupCreated->logName(),
            'subject_type' => Project::class,
            'subject_id' => $project->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeProject();

        Livewire::actingAs($admin)
            ->test(CreateProject::class)
            ->fillForm(['name_ar' => 'إيقان', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateProject::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Iqan'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);

        $this->assertSame(1, Project::query()->where('name_en', 'Iqan')->count());
    }

    #[Test]
    public function an_administrator_edits_a_project_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        $project = $this->makeProject();

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'name_ar' => 'إيقان',
                'name_en' => 'Iqan Group',
                'color' => BadgeColor::Warning->value,
                'sort' => 7,
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('Iqan Group', $project->name_en);
        $this->assertSame(BadgeColor::Warning, $project->color);
        $this->assertSame(7, $project->sort);
        $this->assertFalse($project->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => Project::class,
            'subject_id' => $project->getKey(),
        ]);
    }

    #[Test]
    public function making_another_project_the_default_from_the_form_unsets_the_old_one(): void
    {
        $admin = $this->admin();
        $default = $this->defaultProject();
        $iqan = $this->makeProject();

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $iqan->getRouteKey()])
            ->fillForm(['is_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($iqan->refresh()->is_default);
        $this->assertFalse($default->refresh()->is_default);
        $this->assertSame(1, Project::query()->where('is_default', true)->count());
    }

    #[Test]
    public function the_default_can_be_neither_unset_nor_deactivated_from_the_form(): void
    {
        $admin = $this->admin();
        $default = $this->defaultProject();

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $default->getRouteKey()])
            ->fillForm(['is_default' => false])
            ->call('save')
            ->assertHasFormErrors(['is_default']);

        $this->assertTrue($default->refresh()->is_default);

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $default->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($default->refresh()->is_active);
    }

    #[Test]
    public function an_inactive_project_cannot_be_created_as_the_default(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateProject::class)
            ->fillForm([
                'name_ar' => 'قديم',
                'name_en' => 'Old',
                'is_active' => false,
                'is_default' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['is_default']);

        $this->assertDatabaseMissing('projects', ['name_en' => 'Old']);
        $this->assertTrue($this->defaultProject()->is_default);
    }

    #[Test]
    public function delete_is_hidden_for_the_default_and_a_project_in_use_and_allowed_for_an_unused_one(): void
    {
        $admin = $this->admin();
        $default = $this->defaultProject();
        $iqan = $this->makeProject();
        $spare = $this->makeProject('Spare', 'احتياطي');
        $lead = Lead::factory()->create(['project_id' => $iqan->getKey(), 'owner_id' => $admin->getKey()]);
        $lead->delete();

        foreach ([$default, $iqan] as $kept) {
            $this->assertFalse($admin->can('delete', $kept), "{$kept->name_en} must not be deletable");

            Livewire::actingAs($admin)
                ->test(EditProject::class, ['record' => $kept->getRouteKey()])
                ->assertActionHidden('delete');
        }

        $this->assertTrue($admin->can('delete', $spare));

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $spare->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('projects', ['id' => $spare->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Project::class,
            'subject_id' => $spare->getKey(),
        ]);

        $other = $this->makeProject('Other', 'آخر');

        Livewire::actingAs($admin)
            ->test(ListProjects::class)
            ->callTableBulkAction('delete', [$default, $iqan, $other]);

        $this->assertDatabaseHas('projects', ['id' => $default->getKey()]);
        $this->assertDatabaseHas('projects', ['id' => $iqan->getKey()]);
        $this->assertDatabaseMissing('projects', ['id' => $other->getKey()]);
    }

    #[Test]
    public function the_table_searches_both_names_filters_by_state_and_reorders_by_dragging(): void
    {
        $admin = $this->admin();
        $default = $this->defaultProject();
        $iqan = $this->makeProject('Iqan', 'إيقان', ['sort' => 20]);
        $old = $this->makeProject('Old', 'قديم', ['sort' => 30, 'is_active' => false]);

        Livewire::actingAs($admin)
            ->test(ListProjects::class)
            ->assertCanSeeTableRecords([$default, $iqan, $old], inOrder: true)
            ->searchTable('إيقان')
            ->assertCanSeeTableRecords([$iqan])
            ->assertCanNotSeeTableRecords([$default, $old])
            ->searchTable('Old')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$default, $iqan])
            ->searchTable('')
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$default, $iqan])
            ->assertCanNotSeeTableRecords([$old])
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$default, $iqan]);

        Livewire::actingAs($admin)
            ->test(ListProjects::class)
            ->call('reorderTable', [$old->getKey(), $iqan->getKey(), $default->getKey()]);

        $this->assertSame(1, $old->refresh()->sort);
        $this->assertSame(2, $iqan->refresh()->sort);
        $this->assertSame(3, $default->refresh()->sort);
    }

    #[Test]
    public function a_sales_manager_cannot_reorder_projects(): void
    {
        $manager = $this->salesManager();
        $default = $this->defaultProject();
        $iqan = $this->makeProject('Iqan', 'إيقان', ['sort' => 20]);

        $this->assertFalse($manager->can('reorder', Project::class));

        Livewire::actingAs($manager)
            ->test(ListProjects::class)
            ->assertForbidden();

        $this->assertSame(10, $default->refresh()->sort);
        $this->assertSame(20, $iqan->refresh()->sort);
    }
}
