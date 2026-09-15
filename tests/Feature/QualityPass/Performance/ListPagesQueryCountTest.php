<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: the lead and deal lists render in a constant number of queries
 * whatever the page size (plan section 8). The viewer is a sales manager,
 * the most common team-level user (D-4), looking at the records of a rep in
 * the same team — the path on which every record action's policy asks the
 * visibility resolver whether the owner is a team member.
 */
final class ListPagesQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** Queries a page may add for 25 extra rows before it counts as per-row. */
    private const int TOLERANCE = 3;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $team = $this->makeTeam();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
    }

    #[Test]
    public function list_leads_for_a_team_manager_issues_no_per_row_queries(): void
    {
        Lead::factory()->count(5)->create(['owner_id' => $this->rep->getKey()]);
        $this->renderList(ListLeads::class);

        $small = $this->queriesDuring(fn () => $this->renderList(ListLeads::class));

        Lead::factory()->count(25)->create(['owner_id' => $this->rep->getKey()]);

        $large = $this->queriesDuring(fn () => $this->renderList(ListLeads::class));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("ListLeads: %d queries for 5 rows, %d for 30 rows. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function list_deals_for_a_team_manager_issues_no_per_row_queries(): void
    {
        Deal::factory()->count(5)->create(['owner_id' => $this->rep->getKey()]);
        $this->renderList(ListDeals::class);

        $small = $this->queriesDuring(fn () => $this->renderList(ListDeals::class));

        Deal::factory()->count(25)->create(['owner_id' => $this->rep->getKey()]);

        $large = $this->queriesDuring(fn () => $this->renderList(ListDeals::class));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("ListDeals: %d queries for 5 rows, %d for 30 rows. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    /**
     * @param  class-string  $page
     */
    private function renderList(string $page): void
    {
        Livewire::actingAs($this->manager)
            ->test($page)
            ->set('activeTab', 'all')
            ->set('tableRecordsPerPage', 50)
            ->assertOk();
    }
}
