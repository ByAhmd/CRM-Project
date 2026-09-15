<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Enums\ActivityKind;
use App\Enums\StageKind;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Livewire\RecordTimeline;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealProduct;
use App\Models\DealStageLog;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: a deal's view page and its timeline (module row 12) cost the same
 * number of queries for a short history as for a long one. The viewer is
 * the rep who owns the deal; the history was written by their manager (a
 * call logged on the rep's deal, a task handed to the manager), which is the
 * path where each entry's link is authorised through the linked record.
 */
final class RecordPageQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    private User $manager;

    private User $rep;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-14 09:00:00'));

        $team = $this->makeTeam();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
        $this->deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
    }

    #[Test]
    public function the_deal_view_page_issues_no_per_line_or_per_stage_log_queries(): void
    {
        $this->addHistory(2);
        $this->renderViewDeal();

        $small = $this->queriesDuring(fn () => $this->renderViewDeal());

        $this->addHistory(12);

        $large = $this->queriesDuring(fn () => $this->renderViewDeal());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("ViewDeal: %d queries for 2 lines/logs, %d for 14. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function the_deal_timeline_issues_no_per_entry_queries(): void
    {
        $this->addTimelineEntries(2);
        $this->renderTimeline();

        $small = $this->queriesDuring(fn () => $this->renderTimeline());

        $this->addTimelineEntries(8);

        $large = $this->queriesDuring(fn () => $this->renderTimeline());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("RecordTimeline: %d queries for 4 entries, %d for 20 entries. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function the_deal_timeline_never_lazy_loads_a_relation(): void
    {
        $this->addTimelineEntries(3);

        Model::preventLazyLoading();

        try {
            $this->renderTimeline();
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    private function renderViewDeal(): void
    {
        Livewire::actingAs($this->rep)
            ->test(ViewDeal::class, ['record' => $this->deal->getKey()])
            ->assertOk();
    }

    private function renderTimeline(): void
    {
        Livewire::actingAs($this->rep)
            ->test(RecordTimeline::class, ['subjectType' => Deal::class, 'subjectId' => $this->deal->getKey()])
            ->assertOk();
    }

    /** Product lines and stage-change logs written by the manager. */
    private function addHistory(int $count): void
    {
        $stages = PipelineStage::query()
            ->where('pipeline_id', $this->deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->pluck('id')
            ->all();

        for ($i = 0; $i < $count; $i++) {
            DealProduct::factory()->create(['deal_id' => $this->deal->getKey(), 'sort' => $i]);

            DealStageLog::query()->create([
                'deal_id' => $this->deal->getKey(),
                'from_stage_id' => $stages[$i % count($stages)],
                'to_stage_id' => $stages[($i + 1) % count($stages)],
                'changed_by' => $this->manager->getKey(),
                'changed_at' => now()->subMinutes(100 - $i),
                'duration_seconds' => 60,
            ]);
        }
    }

    /** Calls the manager logged on the deal and tasks assigned to the manager on it. */
    private function addTimelineEntries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Activity::factory()->ofKind(ActivityKind::Call)->create([
                'lead_id' => null,
                'deal_id' => $this->deal->getKey(),
                'owner_id' => $this->manager->getKey(),
                'occurred_at' => now()->subMinutes(10 + $i),
            ]);

            Task::factory()->create([
                'deal_id' => $this->deal->getKey(),
                'assignee_id' => $this->manager->getKey(),
            ]);
        }
    }
}
