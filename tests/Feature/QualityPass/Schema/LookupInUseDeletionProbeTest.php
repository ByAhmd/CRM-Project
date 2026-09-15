<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\ActivityKind;
use App\Enums\BadgeColor;
use App\Enums\CloseReasonKind;
use App\Enums\LeadStatusKind;
use App\Enums\StageKind;
use App\Filament\Resources\ActivityTypes\Pages\EditActivityType;
use App\Filament\Resources\ActivityTypes\Pages\ListActivityTypes;
use App\Filament\Resources\DealCloseReasons\Pages\EditDealCloseReason;
use App\Filament\Resources\DealCloseReasons\Pages\ListDealCloseReasons;
use App\Filament\Resources\LeadSources\Pages\EditLeadSource;
use App\Filament\Resources\LeadStatuses\Pages\EditLeadStatus;
use App\Filament\Resources\LeadStatuses\Pages\ListLeadStatuses;
use App\Filament\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Resources\Pipelines\Pages\ListPipelines;
use App\Filament\Resources\Pipelines\RelationManagers\StagesRelationManager;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Settings\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Probe for DATABASE_DESIGN.md section 6, last rule: "Lookups referenced by
 * rows cannot be deleted (RESTRICT) — they are deactivated instead."
 *
 * Only IndustryPolicy guards the in-use case. Every other hard-deleted lookup
 * reaches the RESTRICT foreign key and the QueryException escapes the
 * Filament delete action (a 500 in the browser); the soft-deleted pipeline
 * bypasses RESTRICT entirely and orphans its deals.
 *
 * A refusal is graceful when the row survives and nothing escapes: either the
 * policy hides the single-record action before it is offered (the pattern
 * IndustryPolicy set, asserted with assertActionHidden — Filament's
 * callAction() itself fails on a hidden action, so calling it is not a fair
 * probe of that path), or the bulk action skips the record.
 */
final class LookupInUseDeletionProbeTest extends TestCase
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
    public function a_lead_source_used_by_a_lead_is_refused_gracefully_on_the_edit_page(): void
    {
        $admin = $this->admin();
        $source = LeadSource::factory()->create(['name_en' => 'Exhibition', 'name_ar' => 'معرض']);
        Lead::factory()->create(['lead_source_id' => $source->getKey(), 'owner_id' => $admin->getKey()]);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(EditLeadSource::class, ['record' => $source->getRouteKey()])
            ->assertActionHidden('delete'));

        $this->assertDatabaseHas('lead_sources', ['id' => $source->getKey()]);
    }

    #[Test]
    public function a_lead_status_holding_leads_is_refused_gracefully_on_the_edit_page_and_in_bulk(): void
    {
        $admin = $this->admin();
        $working = LeadStatus::query()->where('kind', LeadStatusKind::Working->value)->firstOrFail();
        $lead = Lead::factory()->create(['owner_id' => $admin->getKey()]);
        app(LeadStatusWorkflow::class)->transition($lead, $working, $admin);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $working->getRouteKey()])
            ->assertActionHidden('delete'));

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(ListLeadStatuses::class)
            ->callTableBulkAction('delete', [$working]));

        $this->assertDatabaseHas('lead_statuses', ['id' => $working->getKey()]);
    }

    #[Test]
    public function a_stage_holding_a_deal_is_refused_gracefully_in_the_stages_relation_manager(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $proposal = PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->where('is_default', false)
            ->orderBy('sort')
            ->firstOrFail();
        app(DealStageWorkflow::class)->transition($deal, $proposal, $admin);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(StagesRelationManager::class, ['ownerRecord' => $deal->pipeline, 'pageClass' => EditPipeline::class])
            ->assertTableActionHidden('delete', $proposal));

        $this->assertDatabaseHas('pipeline_stages', ['id' => $proposal->getKey()]);
    }

    #[Test]
    public function a_stage_referenced_only_by_stage_history_is_refused_gracefully(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $open = PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->where('is_default', false)
            ->orderBy('sort')
            ->get();
        app(DealStageWorkflow::class)->transition($deal, $open[0], $admin);
        app(DealStageWorkflow::class)->transition($deal, $open[1], $admin);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(StagesRelationManager::class, ['ownerRecord' => $deal->pipeline, 'pageClass' => EditPipeline::class])
            ->assertTableActionHidden('delete', $open[0]));

        $this->assertDatabaseHas('pipeline_stages', ['id' => $open[0]->getKey()]);
    }

    #[Test]
    public function a_custom_activity_type_used_by_an_activity_is_refused_gracefully_on_the_edit_page_and_in_bulk(): void
    {
        $admin = $this->admin();
        $type = ActivityType::factory()->create([
            'name_en' => 'Site visit',
            'name_ar' => 'زيارة موقع',
            'kind' => ActivityKind::Meeting,
            'color' => BadgeColor::Info,
            'is_system' => false,
        ]);
        Activity::factory()->create(['activity_type_id' => $type->getKey(), 'kind' => ActivityKind::Meeting, 'owner_id' => $admin->getKey()]);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $type->getRouteKey()])
            ->assertActionHidden('delete'));

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(ListActivityTypes::class)
            ->callTableBulkAction('delete', [$type]));

        $this->assertDatabaseHas('activity_types', ['id' => $type->getKey()]);
    }

    #[Test]
    public function a_close_reason_used_by_a_closed_deal_is_refused_gracefully_on_the_edit_page_and_in_bulk(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->orderBy('sort')->firstOrFail();
        app(DealCloseService::class)->win($deal, $reason, $admin);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])
            ->assertActionHidden('delete'));

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(ListDealCloseReasons::class)
            ->callTableBulkAction('delete', [$reason]));

        $this->assertDatabaseHas('deal_close_reasons', ['id' => $reason->getKey()]);
    }

    #[Test]
    public function a_pipeline_holding_deals_is_not_soft_deleted_from_under_them(): void
    {
        $admin = $this->admin();
        $pipeline = app(PipelineService::class)->create([
            'name_ar' => 'المشاريع',
            'name_en' => 'Projects',
            'is_default' => false,
            'is_active' => true,
        ]);
        $stage = $pipeline->stages()->where('is_default', true)->firstOrFail();
        Deal::factory()->create(['owner_id' => $admin->getKey(), 'pipeline_id' => $pipeline->getKey(), 'stage_id' => $stage->getKey()]);

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(EditPipeline::class, ['record' => $pipeline->getRouteKey()])
            ->assertActionHidden('delete'));

        $this->assertGraceful(fn () => Livewire::actingAs($admin)
            ->test(ListPipelines::class)
            ->callTableBulkAction('delete', [$pipeline]));

        $this->assertNotSoftDeleted('pipelines', ['id' => $pipeline->getKey()]);
        $this->assertNotNull(Pipeline::query()->find($pipeline->getKey()));
    }

    private function assertGraceful(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            $this->fail('The delete action crashed instead of refusing: '.$exception::class.': '.$exception->getMessage());
        }
    }
}
