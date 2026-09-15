<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Filament\Pages\DealBoard;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\DealStageLog;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Settings\PipelineService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The deal kanban (decision D-12): columns follow the pipeline, cards follow
 * the visibility scope, and dragging goes through the stage workflow.
 */
final class DealBoardTest extends TestCase
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
    public function the_board_renders_for_a_rep_and_lists_only_visible_deals(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Visible deal']);
        $theirs = Deal::factory()->create(['owner_id' => $other->getKey(), 'title' => 'Hidden deal']);

        $this->actingAs($rep)->get(DealBoard::getUrl())->assertOk();

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->assertSet('pipelineId', $mine->pipeline_id)
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title)
            ->assertSee(__('deal_board.columns.empty'))
            ->assertOk();
    }

    #[Test]
    public function the_columns_follow_the_pipeline_stages_open_first_then_won_then_lost(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);

        $page = Livewire::actingAs($admin)->test(DealBoard::class)->instance();
        assert($page instanceof DealBoard);

        $columns = $page->columns();

        $this->assertSame(
            PipelineStage::query()->where('pipeline_id', $deal->pipeline_id)->orderBy('sort')->pluck('name_en')->all(),
            $columns->map(fn (array $column): string => $column['stage']->name_en)->all(),
        );
        $this->assertSame([StageKind::Open, StageKind::Open, StageKind::Open, StageKind::Won, StageKind::Lost], $columns->map(fn (array $column): StageKind => $column['stage']->kind)->all());
        $this->assertSame([$deal->getKey()], $columns->first()['deals']->modelKeys());
        $this->assertSame(1, $columns->first()['count']);
        $this->assertSame(DealBoard::money((float) $deal->amount), $columns->first()['total']);
        $this->assertSame(DealBoard::CARDS_PER_PAGE, $columns->first()['limit']);
    }

    #[Test]
    public function dropping_a_card_on_an_open_stage_moves_the_deal_and_logs_it(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $proposal = $this->stageOfKind($deal, StageKind::Open, 1);

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $proposal->getKey())
            ->assertNotified(__('deal_board.notifications.moved', ['deal' => $deal->title, 'stage' => $proposal->display_name]));

        $deal->refresh();
        $this->assertSame($proposal->getKey(), $deal->stage_id);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame($proposal->getKey(), (int) DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail()->to_stage_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealStageChanged->value, 'subject_id' => $deal->getKey()]);
    }

    #[Test]
    public function dropping_a_card_on_the_won_column_opens_the_close_modal_instead_of_moving(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $won = $this->stageOfKind($deal, StageKind::Won);
        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();

        $page = Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $won->getKey())
            ->assertActionMounted('markWon');

        $deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertNotSame($won->getKey(), $deal->stage_id);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());

        $page->fillForm(['close_reason_id' => $reason->getKey(), 'note' => 'Signed on the board'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('deals.notifications.won'));

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame($won->getKey(), $deal->stage_id);
        $this->assertSame($reason->getKey(), $deal->close_reason_id);
        $this->assertSame('Signed on the board', DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail()->notes);
    }

    #[Test]
    public function dropping_a_card_on_the_lost_column_opens_the_lost_modal_and_completing_it_loses_the_deal(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $lost = $this->stageOfKind($deal, StageKind::Lost);
        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->where('is_active', true)->orderBy('sort')->firstOrFail();

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $lost->getKey())
            ->assertActionMounted('markLost')
            ->fillForm(['close_reason_id' => $reason->getKey(), 'lost_notes' => 'No budget'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('deals.notifications.lost'));

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame('No budget', $deal->lost_notes);

        // A closed deal shows in its closed column, still within the recent window.
        $page = Livewire::actingAs($rep)->test(DealBoard::class)->instance();
        assert($page instanceof DealBoard);
        $lostColumn = $page->columns()->first(fn (array $column): bool => $column['stage']->kind === StageKind::Lost);
        $this->assertNotNull($lostColumn);
        $this->assertSame([$deal->getKey()], $lostColumn['deals']->modelKeys());
    }

    #[Test]
    public function deals_closed_before_the_recent_window_leave_the_closed_columns(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        app(DealCloseService::class)->win($deal, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $admin);

        Deal::withoutWorkflowGuard(function () use ($deal): void {
            $deal->won_at = now()->subDays(DealBoard::CLOSED_WINDOW_DAYS + 1);
            $deal->save();
        });

        $page = Livewire::actingAs($admin)->test(DealBoard::class)->instance();
        assert($page instanceof DealBoard);
        $wonColumn = $page->columns()->first(fn (array $column): bool => $column['stage']->kind === StageKind::Won);

        $this->assertNotNull($wonColumn);
        $this->assertSame([], $wonColumn['deals']->modelKeys());
        $this->assertSame(0, $wonColumn['count']);
    }

    #[Test]
    public function a_read_only_user_sees_the_board_but_cannot_move_a_card(): void
    {
        $readOnly = $this->readOnly();
        $deal = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $proposal = $this->stageOfKind($deal, StageKind::Open, 1);
        $won = $this->stageOfKind($deal, StageKind::Won);

        Livewire::actingAs($readOnly)
            ->test(DealBoard::class)
            ->assertSee($deal->title)
            ->call('moveDeal', $deal->getKey(), $proposal->getKey())
            ->assertForbidden();

        Livewire::actingAs($readOnly)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $won->getKey())
            ->assertForbidden();

        $this->assertSame($deal->stage_id, $deal->refresh()->stage_id);
    }

    #[Test]
    public function a_closed_card_is_refused_with_a_notification_instead_of_a_policy_error(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $proposal = $this->stageOfKind($deal, StageKind::Open, 1);
        $lost = $this->stageOfKind($deal, StageKind::Lost);
        app(DealCloseService::class)->win($deal, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $rep);
        $wonStageId = $deal->refresh()->stage_id;

        $refused = Notification::make()
            ->title(__('deal_board.notifications.refused'))
            ->body(__('deals.validation.already_closed'))
            ->danger();

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $proposal->getKey())
            ->assertNotified($refused)
            ->call('moveDeal', $deal->getKey(), $lost->getKey())
            ->assertNotified($refused)
            ->assertActionNotMounted('markLost')
            ->assertOk();

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame($wonStageId, $deal->stage_id);
        $this->assertSame(1, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function the_column_limits_cannot_be_set_from_the_browser(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($admin)
            ->test(DealBoard::class)
            ->set('limits', [$deal->stage_id => 1000000]);
    }

    #[Test]
    public function a_deal_outside_the_actor_scope_cannot_be_moved(): void
    {
        $rep = $this->salesRep();
        $theirs = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $proposal = $this->stageOfKind($theirs, StageKind::Open, 1);

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $theirs->getKey(), $proposal->getKey())
            ->assertNotFound();

        $this->assertSame($theirs->stage_id, $theirs->refresh()->stage_id);
    }

    #[Test]
    public function a_stage_of_another_pipeline_is_refused(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $other = $this->anotherPipeline();
        $foreignStage = $other->stages()->where('kind', StageKind::Open->value)->orderBy('sort')->firstOrFail();

        Livewire::actingAs($rep)
            ->test(DealBoard::class)
            ->call('moveDeal', $deal->getKey(), $foreignStage->getKey())
            ->assertNotified(__('deal_board.notifications.refused'));

        $this->assertSame($deal->stage_id, $deal->refresh()->stage_id);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function load_more_raises_the_column_limit(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $stageId = $deal->stage_id;

        $page = Livewire::actingAs($admin)
            ->test(DealBoard::class)
            ->call('loadMore', $stageId)
            ->assertSet("limits.{$stageId}", DealBoard::CARDS_PER_PAGE * 2)
            ->call('loadMore', $stageId)
            ->assertSet("limits.{$stageId}", DealBoard::CARDS_PER_PAGE * 3)
            ->instance();
        assert($page instanceof DealBoard);

        $this->assertSame(DealBoard::CARDS_PER_PAGE * 3, $page->columns()->first()['limit']);
    }

    #[Test]
    public function load_more_stops_at_the_page_cap_and_ignores_stages_that_are_not_on_the_board(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $stageId = (int) $deal->stage_id;
        $otherStageId = (int) $this->anotherPipeline()->stages()->orderBy('sort')->value('id');

        $page = Livewire::actingAs($admin)->test(DealBoard::class);

        for ($i = 0; $i < DealBoard::MAX_PAGES + 5; $i++) {
            $page->call('loadMore', $stageId);
        }

        $page->call('loadMore', $otherStageId)
            ->call('loadMore', 999999)
            ->assertSet("limits.{$stageId}", DealBoard::MAX_PAGES * DealBoard::CARDS_PER_PAGE)
            ->assertSet('limits', [$stageId => DealBoard::MAX_PAGES * DealBoard::CARDS_PER_PAGE]);
    }

    #[Test]
    public function switching_the_pipeline_reloads_the_columns_and_refuses_an_inactive_one(): void
    {
        $admin = $this->admin();
        $default = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $other = $this->anotherPipeline();
        $otherStage = $other->stages()->where('is_default', true)->firstOrFail();
        $otherDeal = Deal::factory()->create(['owner_id' => $admin->getKey(), 'pipeline_id' => $other->getKey(), 'stage_id' => $otherStage->getKey()]);
        $inactive = app(PipelineService::class)->create(['name_ar' => 'قديم', 'name_en' => 'Legacy', 'is_active' => false]);

        $page = Livewire::actingAs($admin)
            ->test(DealBoard::class)
            ->assertSee($default->title)
            ->assertDontSee($otherDeal->title)
            ->call('loadMore', $default->stage_id)
            ->set('pipelineId', $other->getKey())
            ->assertSet('pipelineId', $other->getKey())
            ->assertSet('limits', [])
            ->assertSee($otherDeal->title)
            ->assertDontSee($default->title);

        $instance = $page->instance();
        assert($instance instanceof DealBoard);
        $this->assertSame($other->stages()->pluck('id')->sort()->values()->all(), $instance->columns()->map(fn (array $column): int => (int) $column['stage']->getKey())->sort()->values()->all());

        $page->set('pipelineId', $inactive->getKey())
            ->assertNotified(__('deal_board.validation.pipeline_inactive'))
            ->assertSet('pipelineId', $default->pipeline_id);
    }

    #[Test]
    public function a_user_without_the_deal_permission_cannot_access_the_board(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody);
        $this->assertFalse(DealBoard::canAccess());
        $this->get(DealBoard::getUrl())->assertForbidden();

        $this->actingAs($this->readOnly());
        $this->assertTrue(DealBoard::canAccess());
    }

    private function stageOfKind(Deal $deal, StageKind $kind, int $position = 0): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    /** A second active pipeline with the scaffolded stage set. */
    private function anotherPipeline(): Pipeline
    {
        return app(PipelineService::class)->create([
            'name_ar' => 'الشراكات',
            'name_en' => 'Partnerships',
            'is_active' => true,
            'is_default' => false,
        ]);
    }
}
