<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Filament\Pages\DealBoard;
use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: Livewire action arguments on the deal board.
 *
 * `$limits` is #[Locked] so "a browser must not be able to force every
 * visible deal into one render", but loadMore() — a public action taking a
 * client-supplied stage id — has no ceiling and no check that the stage
 * belongs to the board: repeated calls raise one column without bound, and
 * arbitrary ids grow the locked array that is serialised into every
 * snapshot. RecordTimeline caps the same pattern with MAX_PAGES.
 */
final class DealBoardRenderBoundsProbeTest extends TestCase
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
    public function load_more_is_capped_and_ignores_stages_outside_the_board(): void
    {
        $rep = $this->salesRep();
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();
        $stageId = (int) $pipeline->stages()->orderBy('sort')->value('id');

        $component = Livewire::actingAs($rep)->test(DealBoard::class);

        for ($i = 0; $i < 60; $i++) {
            $component->call('loadMore', $stageId);
        }

        foreach ([999001, 999002, 999003] as $foreignStage) {
            $component->call('loadMore', $foreignStage);
        }

        /** @var array<int, int> $limits */
        $limits = $component->get('limits');

        $this->assertLessThanOrEqual(DealBoard::CARDS_PER_PAGE * 20, $limits[$stageId] ?? 0, 'one column was raised to '.($limits[$stageId] ?? 0).' cards per render');
        $this->assertArrayNotHasKey(999001, $limits, 'a stage id that is not on the board was accepted into the locked limits');
    }
}
