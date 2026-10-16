<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe (D-28): a Sales task tab renders in a constant number of queries for
 * the readers who reach its rows without holding them — the team manager and
 * the administrator — when the tasks were handed out and some are shared.
 * Each row's actions ask the policy whether the task was handed out to the
 * reader (Task::isHandedOutTo(), which reads the co-assignees); the tab must
 * answer that from the rows it loaded, never with a query per row.
 * RelationManagersQueryCountTest renders as the deal's rep, whom the policy
 * refuses before that question is asked.
 */
final class SharedTaskTabQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    private User $admin;

    private User $manager;

    private User $rep;

    private User $colleague;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
        $this->colleague = $this->salesRep($team);
        $this->deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return [
            'team manager' => ['manager'],
            'administrator' => ['admin'],
        ];
    }

    #[Test]
    #[DataProvider('readers')]
    public function the_deal_task_tab_issues_no_per_row_queries_for_a_reader_who_holds_no_row(string $reader): void
    {
        $viewer = $reader === 'admin' ? $this->admin : $this->manager;

        $this->addRows(2);
        $this->render($viewer);

        $small = $this->queriesDuring(fn () => $this->render($viewer));

        $this->addRows(10);

        $large = $this->queriesDuring(fn () => $this->render($viewer));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("%s: %d queries for 2 rows, %d for 12 rows. Most repeated:\n%s", $reader, count($small), count($large), $this->topRepeated($large)),
        );
    }

    private function render(User $viewer): void
    {
        Livewire::actingAs($viewer)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $this->deal, 'pageClass' => ViewDeal::class])
            ->set('tableRecordsPerPage', 25)
            ->assertOk();
    }

    /** Tasks on the deal the administrator handed to the rep, every other one shared with a colleague. */
    private function addRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $task = Task::factory()->create([
                'deal_id' => $this->deal->getKey(),
                'assignee_id' => $this->rep->getKey(),
                'assigned_by' => $this->admin->getKey(),
                'created_by' => $this->admin->getKey(),
            ]);

            if ($i % 2 === 0) {
                $this->shareTask($task, $this->colleague);
            }
        }
    }
}
