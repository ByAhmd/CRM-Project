<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Enums\ActivityKind;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealActivitiesRelationManager;
use App\Filament\Resources\Deals\RelationManagers\DealAttachmentsRelationManager;
use App\Filament\Resources\Deals\RelationManagers\DealNotesRelationManager;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Deal;
use App\Models\Note;
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
 * Probe: the relation managers on a record page (notes, attachments, tasks,
 * activities) render in a constant number of queries. The viewer owns the
 * deal; the rows were written by the manager, so every row action is
 * authorised through the deal rather than through authorship.
 */
final class RelationManagersQueryCountTest extends TestCase
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

        $team = $this->makeTeam();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
        $this->deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function relationManagers(): array
    {
        return [
            'notes' => [DealNotesRelationManager::class],
            'attachments' => [DealAttachmentsRelationManager::class],
            'tasks' => [DealTasksRelationManager::class],
            'activities' => [DealActivitiesRelationManager::class],
        ];
    }

    /**
     * @param  class-string  $manager
     */
    #[Test]
    #[DataProvider('relationManagers')]
    public function a_deal_relation_manager_issues_no_per_row_queries(string $manager): void
    {
        $this->addRows(2);
        $this->render($manager);

        $small = $this->queriesDuring(fn () => $this->render($manager));

        $this->addRows(10);

        $large = $this->queriesDuring(fn () => $this->render($manager));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("%s: %d queries for 2 rows, %d for 12 rows. Most repeated:\n%s", $manager, count($small), count($large), $this->topRepeated($large)),
        );
    }

    /**
     * @param  class-string  $manager
     */
    private function render(string $manager): void
    {
        Livewire::actingAs($this->rep)
            ->test($manager, ['ownerRecord' => $this->deal, 'pageClass' => ViewDeal::class])
            ->set('tableRecordsPerPage', 25)
            ->assertOk();
    }

    private function addRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Note::factory()->create(['deal_id' => $this->deal->getKey(), 'author_id' => $this->manager->getKey()]);

            Attachment::factory()->create([
                'attachable_type' => $this->deal->getMorphClass(),
                'attachable_id' => $this->deal->getKey(),
                'uploaded_by' => $this->manager->getKey(),
            ]);

            Task::factory()->create(['deal_id' => $this->deal->getKey(), 'assignee_id' => $this->manager->getKey()]);

            Activity::factory()->ofKind(ActivityKind::Call)->create([
                'lead_id' => null,
                'deal_id' => $this->deal->getKey(),
                'owner_id' => $this->manager->getKey(),
            ]);
        }
    }
}
