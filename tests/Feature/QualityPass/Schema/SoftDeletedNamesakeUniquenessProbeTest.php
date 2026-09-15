<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\CrmRole;
use App\Filament\Resources\Pipelines\Pages\CreatePipeline;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Pipeline;
use App\Models\Team;
use App\Models\User;
use App\Services\Settings\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Probe: soft-deletable tables whose unique index is global (teams, pipelines,
 * users) validate uniqueness with ->withoutTrashed(), so a soft-deleted
 * namesake passes validation and the INSERT hits the unique index — a 500
 * instead of the translated "restore instead" message competitors, products
 * and email templates already give.
 */
final class SoftDeletedNamesakeUniquenessProbeTest extends TestCase
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
    public function creating_a_team_named_like_a_soft_deleted_team_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $this->makeTeam('Eastern Team', 'فريق الشرقية')->delete();

        try {
            Livewire::actingAs($admin)
                ->test(CreateTeam::class)
                ->fillForm([
                    'name_ar' => 'فريق الشرقية',
                    'name_en' => 'Eastern Team',
                    'manager_user_id' => $manager->getKey(),
                    'is_active' => true,
                    'sort' => 1,
                ])
                ->call('create')
                ->assertHasFormErrors(['name_ar', 'name_en']);
        } catch (Throwable $exception) {
            $this->fail('Creating a team named like a trashed team crashed: '.$exception::class.': '.$exception->getMessage());
        }

        $this->assertSame(1, Team::withTrashed()->where('name_en', 'Eastern Team')->count());
    }

    #[Test]
    public function creating_a_pipeline_named_like_a_soft_deleted_pipeline_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->admin();
        $pipeline = app(PipelineService::class)->create([
            'name_ar' => 'المشاريع',
            'name_en' => 'Projects',
            'is_default' => false,
            'is_active' => true,
        ]);
        app(PipelineService::class)->delete($pipeline);

        try {
            Livewire::actingAs($admin)
                ->test(CreatePipeline::class)
                ->fillForm([
                    'name_ar' => 'المشاريع',
                    'name_en' => 'Projects',
                    'is_default' => false,
                    'is_active' => true,
                    'sort' => 2,
                ])
                ->call('create')
                ->assertHasFormErrors(['name_ar', 'name_en']);
        } catch (Throwable $exception) {
            $this->fail('Creating a pipeline named like a trashed pipeline crashed: '.$exception::class.': '.$exception->getMessage());
        }

        $this->assertSame(1, Pipeline::withTrashed()->where('name_en', 'Projects')->count());
    }

    #[Test]
    public function inviting_the_email_of_a_soft_deleted_user_is_a_validation_error_not_a_crash(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $this->makeUser(CrmRole::SalesRep, ['email' => 'former@example.com'])->delete();

        try {
            Livewire::actingAs($admin)
                ->test(CreateUser::class)
                ->fillForm([
                    'name' => 'موظف سابق',
                    'email' => 'former@example.com',
                    'locale' => 'ar',
                    'roles' => [CrmRole::SalesRep->value],
                ])
                ->call('create')
                ->assertHasFormErrors(['email']);
        } catch (Throwable $exception) {
            $this->fail('Inviting the email of a trashed user crashed: '.$exception::class.': '.$exception->getMessage());
        }

        $this->assertSame(1, User::withTrashed()->where('email', 'former@example.com')->count());
    }
}
