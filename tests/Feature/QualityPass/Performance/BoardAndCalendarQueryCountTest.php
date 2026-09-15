<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Enums\ActivityKind;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\DealBoard;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: the kanban (D-12) costs a fixed number of queries per column, not
 * per card, and one calendar range costs the same whether it holds five
 * entries or thirty. The viewer is a team manager over a rep's records.
 */
final class BoardAndCalendarQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    private User $manager;

    private User $rep;

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
    }

    #[Test]
    public function the_deal_board_issues_no_per_card_queries(): void
    {
        Deal::factory()->count(5)->create(['owner_id' => $this->rep->getKey()]);
        $this->renderBoard();

        $small = $this->queriesDuring(fn () => $this->renderBoard());

        Deal::factory()->count(20)->create(['owner_id' => $this->rep->getKey()]);

        $large = $this->queriesDuring(fn () => $this->renderBoard());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("DealBoard: %d queries for 5 cards, %d for 25 cards. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function one_calendar_month_issues_no_per_entry_queries(): void
    {
        $this->seedCalendar(5);
        $this->calendarMonth();

        $small = $this->queriesDuring(fn () => $this->calendarMonth());

        $this->seedCalendar(25);

        $large = $this->queriesDuring(fn () => $this->calendarMonth());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("Calendar::events(): %d queries for 10 entries, %d for 60 entries. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    private function renderBoard(): void
    {
        Livewire::actingAs($this->manager)->test(DealBoard::class)->assertOk();
    }

    private function calendarMonth(): void
    {
        $page = Livewire::actingAs($this->manager)->test(Calendar::class)->instance();
        assert($page instanceof Calendar);

        $events = $page->events('2026-09-01', '2026-10-01');

        $this->assertNotEmpty($events);
    }

    /** Tasks on the rep's leads and meetings the rep logged, all inside September. */
    private function seedCalendar(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = Lead::factory()->create(['owner_id' => $this->rep->getKey()]);

            Task::factory()->create([
                'assignee_id' => $this->rep->getKey(),
                'lead_id' => $lead->getKey(),
                'due_at' => Carbon::parse('2026-09-15 10:00:00')->addDays($i % 10),
            ]);

            Activity::factory()->ofKind(ActivityKind::Meeting)->create([
                'owner_id' => $this->rep->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => Carbon::parse('2026-09-10 11:00:00')->addDays($i % 10),
            ]);
        }
    }
}
