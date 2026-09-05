<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Exceptions\Settings\InvalidPipelineException;
use App\Filament\Resources\Pipelines\Pages\CreatePipeline;
use App\Filament\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Resources\Pipelines\Pages\ListPipelines;
use App\Filament\Resources\Pipelines\PipelineResource;
use App\Filament\Resources\Pipelines\RelationManagers\StagesRelationManager;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Settings\PipelineService;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class PipelineResourceTest extends TestCase
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
    public function admins_list_pipelines_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $pipeline = $this->makePipeline();

        $this->actingAs($admin)->get(PipelineResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(PipelineResource::getUrl('edit', ['record' => $pipeline]))->assertOk();
        $this->actingAs($manager)->get(PipelineResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(PipelineResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(PipelineResource::getUrl('edit', ['record' => $pipeline]))->assertForbidden();

        Livewire::actingAs($admin)->test(ListPipelines::class)->assertCanSeeTableRecords([$pipeline]);
    }

    #[Test]
    public function sales_managers_cannot_manage_or_reorder_stages(): void
    {
        $manager = $this->salesManager();
        $pipeline = $this->makePipeline();
        $stage = $pipeline->defaultStage;
        $this->assertNotNull($stage);

        /** @var list<int> $ids */
        $ids = $pipeline->stages()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->stagesManager($manager, $pipeline)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $stage)
            ->assertTableActionHidden('delete', $stage)
            ->call('reorderTable', $reversed);

        $this->assertSame($ids, $pipeline->stages()->pluck('id')->all(), 'the stored order is unchanged');
        $this->assertSame([1, 2, 3], $pipeline->stages()->pluck('sort')->all());
    }

    #[Test]
    public function a_pipeline_is_created_with_both_names_a_valid_stage_set_and_audited(): void
    {
        $admin = $this->admin();

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
            ->assertHasNoFormErrors();

        $pipeline = Pipeline::query()->where('name_en', 'Projects')->firstOrFail();

        $this->assertSame('المشاريع', $pipeline->name_ar);
        $this->assertTrue($pipeline->is_default, 'the first pipeline becomes the default');
        $this->assertTrue($pipeline->is_active);
        $this->assertSame(2, $pipeline->sort);

        $this->assertCount(3, $pipeline->stages);
        $this->assertSame(StageKind::Open, $pipeline->defaultStage?->kind);
        $this->assertSame(100, $pipeline->wonStage?->probability);
        $this->assertSame(0, $pipeline->lostStage?->probability);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => Pipeline::class,
            'subject_id' => $pipeline->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function an_admin_edits_a_pipeline_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->fillForm(['name_ar' => 'المبيعات المباشرة', 'name_en' => 'Direct Sales', 'sort' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $pipeline->refresh();

        $this->assertSame('Direct Sales', $pipeline->name_en);
        $this->assertSame('المبيعات المباشرة', $pipeline->name_ar);
        $this->assertSame(5, $pipeline->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => Pipeline::class,
            'subject_id' => $pipeline->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makePipeline('Sales', 'المبيعات');

        Livewire::actingAs($admin)
            ->test(CreatePipeline::class)
            ->fillForm(['name_ar' => 'المبيعات', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreatePipeline::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Sales'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $pipeline = $this->makePipeline('Sales', 'المبيعات');
        $stage = $pipeline->defaultStage;
        $this->assertNotNull($stage);

        app()->setLocale('ar');
        $this->assertSame('المبيعات', $pipeline->display_name);
        $this->assertSame('التأهيل', $stage->display_name);

        app()->setLocale('en');
        $this->assertSame('Sales', $pipeline->display_name);
        $this->assertSame('Qualification', $stage->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function exactly_one_pipeline_is_the_default(): void
    {
        $admin = $this->admin();
        $first = $this->makePipeline('Sales', 'المبيعات', default: true);
        $second = $this->makePipeline('Projects', 'المشاريع');

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $second->getRouteKey()])
            ->fillForm(['is_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($second->refresh()->is_default);
        $this->assertFalse($first->refresh()->is_default);
        $this->assertSame(1, Pipeline::query()->where('is_default', true)->count());
    }

    #[Test]
    public function the_default_pipeline_can_be_neither_unset_nor_deactivated(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline('Sales', 'المبيعات', default: true);

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertNotified(__('pipelines.validation.default_cannot_be_deactivated'));

        $this->assertTrue($pipeline->refresh()->is_active);

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->fillForm(['is_default' => false])
            ->call('save')
            ->assertNotified(__('pipelines.validation.default_cannot_be_unset'));

        $this->assertTrue($pipeline->refresh()->is_default);
    }

    #[Test]
    public function the_default_pipeline_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline('Sales', 'المبيعات', default: true);

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->assertActionHidden('delete');

        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessage(__('pipelines.validation.default_cannot_be_deleted'));

        app(PipelineService::class)->delete($pipeline);
    }

    #[Test]
    public function a_non_default_pipeline_is_soft_deleted_and_restorable(): void
    {
        $admin = $this->admin();
        $this->makePipeline('Sales', 'المبيعات', default: true);
        $pipeline = $this->makePipeline('Projects', 'المشاريع');

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('pipelines', ['id' => $pipeline->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Pipeline::class,
            'subject_id' => $pipeline->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($pipeline->refresh()->deleted_at);
    }

    #[Test]
    public function a_stage_is_created_from_the_relation_manager_and_audited(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('create', data: [
                'name_ar' => 'التفاوض',
                'name_en' => 'Negotiation',
                'kind' => StageKind::Open->value,
                'probability' => 60,
                'color' => BadgeColor::Warning->value,
                'is_default' => false,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotNotified(__('pipelines.stages.validation.won_exactly_one'));

        $stage = PipelineStage::query()->where('name_en', 'Negotiation')->firstOrFail();

        $this->assertSame($pipeline->getKey(), $stage->pipeline_id);
        $this->assertSame(StageKind::Open, $stage->kind);
        $this->assertSame(60, $stage->probability);
        $this->assertSame(BadgeColor::Warning, $stage->color);
        $this->assertSame(4, $stage->sort, 'a new stage is appended after the existing ones');
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => PipelineStage::class,
            'subject_id' => $stage->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function stage_names_are_unique_within_the_pipeline_only(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline('Sales', 'المبيعات');
        $this->makePipeline('Projects', 'المشاريع');

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('create', data: [
                'name_ar' => 'التأهيل',
                'name_en' => 'Qualification',
                'kind' => StageKind::Open->value,
                'probability' => 20,
                'color' => BadgeColor::Primary->value,
            ])
            ->assertHasTableActionErrors(['name_ar' => 'unique', 'name_en' => 'unique']);

        $this->assertSame(2, PipelineStage::query()->where('name_en', 'Qualification')->count());
    }

    #[Test]
    public function a_second_won_stage_is_refused(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('create', data: [
                'name_ar' => 'مكسوبة أخرى',
                'name_en' => 'Another Won',
                'kind' => StageKind::Won->value,
                'probability' => 100,
                'color' => BadgeColor::Success->value,
            ])
            ->assertNotified(__('pipelines.stages.validation.won_exactly_one'));

        $this->assertSame(1, $pipeline->stages()->where('kind', StageKind::Won->value)->count());
        $this->assertDatabaseMissing('pipeline_stages', ['name_en' => 'Another Won']);
    }

    #[Test]
    public function the_only_lost_stage_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();
        $lost = $pipeline->lostStage;
        $this->assertNotNull($lost);

        $this->stagesManager($admin, $pipeline)
            ->assertTableActionHidden('delete', $lost);

        try {
            app(PipelineService::class)->deleteStage($lost);
            $this->fail('deleting the only Lost stage must be refused');
        } catch (InvalidPipelineException $exception) {
            $this->assertSame(__('pipelines.stages.validation.last_of_kind', ['kind' => StageKind::Lost->getLabel()]), $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $lost->delete();
    }

    #[Test]
    public function an_open_stage_that_is_not_the_default_is_deleted_from_the_relation_manager(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();
        $stage = app(PipelineService::class)->createStage($pipeline, [
            'name_ar' => 'العرض',
            'name_en' => 'Proposal',
            'kind' => StageKind::Open,
            'probability' => 30,
            'color' => BadgeColor::Info,
        ]);

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('delete', $stage);

        $this->assertDatabaseMissing('pipeline_stages', ['id' => $stage->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => PipelineStage::class,
            'subject_id' => $stage->getKey(),
        ]);
    }

    #[Test]
    public function won_and_lost_probabilities_are_forced(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();
        $won = $pipeline->wonStage;
        $lost = $pipeline->lostStage;
        $this->assertNotNull($won);
        $this->assertNotNull($lost);

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('edit', $won, data: ['probability' => 40])
            ->assertHasNoTableActionErrors();

        $this->assertSame(100, $won->refresh()->probability);

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('edit', $lost, data: ['probability' => 55])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, $lost->refresh()->probability);
    }

    #[Test]
    public function the_default_stage_is_unique_and_must_be_open(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();
        $won = $pipeline->wonStage;
        $this->assertNotNull($won);

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('edit', $won, data: ['is_default' => true])
            ->assertNotified(__('pipelines.stages.validation.default_must_be_open'));

        $this->assertFalse($won->refresh()->is_default);
        $this->assertTrue($pipeline->defaultStage()->firstOrFail()->is_default);

        $proposal = app(PipelineService::class)->createStage($pipeline, [
            'name_ar' => 'العرض',
            'name_en' => 'Proposal',
            'kind' => StageKind::Open,
            'probability' => 30,
            'color' => BadgeColor::Info,
            'is_default' => true,
        ]);

        $this->assertTrue($proposal->is_default);
        $this->assertSame(1, $pipeline->stages()->where('is_default', true)->count());
        $this->assertSame($proposal->getKey(), $pipeline->defaultStage()->firstOrFail()->getKey());
    }

    #[Test]
    public function the_won_stage_kind_is_kept_by_the_stage_set_validation(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();
        $won = $pipeline->wonStage;
        $this->assertNotNull($won);

        $this->stagesManager($admin, $pipeline)
            ->callTableAction('edit', $won, data: ['kind' => StageKind::Open->value])
            ->assertNotified(__('pipelines.stages.validation.won_exactly_one'));

        $this->assertSame(StageKind::Won, $won->refresh()->kind);
    }

    #[Test]
    public function stages_are_reordered_from_the_relation_manager(): void
    {
        $admin = $this->admin();
        $pipeline = $this->makePipeline();

        /** @var list<int> $ids */
        $ids = $pipeline->stages()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->stagesManager($admin, $pipeline)
            ->call('reorderTable', $reversed);

        $this->assertSame($reversed, $pipeline->stages()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $pipeline->stages()->pluck('sort')->all());
    }

    #[Test]
    public function a_stage_never_changes_pipeline(): void
    {
        $pipeline = $this->makePipeline('Sales', 'المبيعات');
        $other = $this->makePipeline('Projects', 'المشاريع');
        $stage = $pipeline->defaultStage;
        $this->assertNotNull($stage);

        try {
            $stage->update(['pipeline_id' => $other->getKey()]);
            $this->fail('changing pipeline_id must be refused');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($pipeline->getKey(), $stage->refresh()->pipeline_id);
    }

    #[Test]
    public function the_seeder_creates_the_sales_pipeline_idempotently(): void
    {
        $this->seed(PipelineSeeder::class);
        $this->seed(PipelineSeeder::class);

        $this->assertSame(1, Pipeline::query()->count());

        $pipeline = Pipeline::query()->where('name_en', 'Sales')->firstOrFail();

        $this->assertSame('المبيعات', $pipeline->name_ar);
        $this->assertTrue($pipeline->is_default);
        $this->assertTrue($pipeline->is_active);
        $this->assertSame(5, $pipeline->stages()->count());
        $this->assertSame(
            ['Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost'],
            $pipeline->stages()->pluck('name_en')->all(),
        );
        $this->assertSame(
            ['التأهيل', 'العرض', 'التفاوض', 'مكسوبة', 'خاسرة'],
            $pipeline->stages()->pluck('name_ar')->all(),
        );
        $this->assertSame([10, 30, 60, 100, 0], $pipeline->stages()->pluck('probability')->all());
        $this->assertSame('Qualification', $pipeline->defaultStage?->name_en);
        $this->assertSame(StageKind::Won, $pipeline->wonStage?->kind);
        $this->assertSame(StageKind::Lost, $pipeline->lostStage?->kind);

        // The seeded set must satisfy every invariant PipelineService enforces, or this throws.
        app(PipelineService::class)->validateStageSet($pipeline);
    }

    #[Test]
    public function the_seeder_matches_the_pipeline_and_its_stages_on_either_name(): void
    {
        $renamed = $this->makePipeline('Sales Pipeline', 'المبيعات', default: true);
        app(PipelineService::class)->updateStage($renamed->defaultStage()->firstOrFail(), ['name_en' => 'Qualify']);

        $this->seed(PipelineSeeder::class);

        $this->assertSame(1, Pipeline::withTrashed()->count(), 'the pipeline is matched on its Arabic name');
        $this->assertSame('Sales Pipeline', $renamed->refresh()->name_en);
        $this->assertSame(5, $renamed->stages()->count(), 'the renamed default stage is matched on its Arabic name');
        $this->assertSame('Qualify', $renamed->defaultStage()->firstOrFail()->name_en);
        $this->assertSame(1, $renamed->stages()->where('is_default', true)->count());

        app(PipelineService::class)->validateStageSet($renamed);
    }

    #[Test]
    public function the_seeder_leaves_a_trashed_sales_pipeline_alone(): void
    {
        $this->makePipeline('Projects', 'المشاريع', default: true);
        $trashed = $this->makePipeline('Sales', 'المبيعات');
        app(PipelineService::class)->delete($trashed);

        $this->seed(PipelineSeeder::class);

        $this->assertSame(2, Pipeline::withTrashed()->count(), 'no second Sales pipeline is created');
        $this->assertSoftDeleted('pipelines', ['id' => $trashed->getKey()]);
        $this->assertSame(3, $trashed->stages()->count(), 'no stages are appended to the trashed pipeline');
    }

    #[Test]
    public function the_seeder_never_creates_a_second_default_pipeline(): void
    {
        $existing = $this->makePipeline('Projects', 'المشاريع', default: true);

        $this->seed(PipelineSeeder::class);

        $this->assertTrue($existing->refresh()->is_default);
        $this->assertFalse(Pipeline::query()->where('name_en', 'Sales')->firstOrFail()->is_default);
        $this->assertSame(1, Pipeline::query()->where('is_default', true)->count());
    }

    /**
     * A pipeline with the minimal valid stage set: one default Open stage, Won and Lost.
     */
    private function makePipeline(string $nameEn = 'Sales', string $nameAr = 'المبيعات', bool $default = false): Pipeline
    {
        $pipeline = Pipeline::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'is_default' => $default,
        ]);

        PipelineStage::factory()->asDefault()->create([
            'pipeline_id' => $pipeline->getKey(),
            'name_en' => 'Qualification',
            'name_ar' => 'التأهيل',
            'sort' => 1,
        ]);
        PipelineStage::factory()->won()->create([
            'pipeline_id' => $pipeline->getKey(),
            'name_en' => 'Won',
            'name_ar' => 'مكسوبة',
            'sort' => 2,
        ]);
        PipelineStage::factory()->lost()->create([
            'pipeline_id' => $pipeline->getKey(),
            'name_en' => 'Lost',
            'name_ar' => 'خاسرة',
            'sort' => 3,
        ]);

        return $pipeline->fresh() ?? $pipeline;
    }

    private function stagesManager(User $user, Pipeline $pipeline): Testable
    {
        return Livewire::actingAs($user)->test(StagesRelationManager::class, [
            'ownerRecord' => $pipeline,
            'pageClass' => EditPipeline::class,
        ]);
    }
}
