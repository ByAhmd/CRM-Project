<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Models\Task;
use App\Services\Contacts\RecordMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe item 4: RecordMerger soft-deletes the duplicate and promises that
 * "relocation steps registered by later modules (leads, deals, activities,
 * tasks, notes, attachments) so a merge never orphans their rows" — but no
 * module ever calls registerRelocation(), so every child except an account's
 * contacts stays on the trashed duplicate and vanishes from the survivor.
 */
final class MergeOrphansChildrenProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function merging_accounts_moves_deals_tasks_notes_and_activities_to_the_surviving_account(): void
    {
        $manager = $this->salesManager();
        $keep = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $duplicate = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $deal = Deal::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $task = Task::factory()->create(['account_id' => $duplicate->getKey(), 'assignee_id' => $manager->getKey()]);
        $note = Note::factory()->create(['account_id' => $duplicate->getKey(), 'author_id' => $manager->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => null, 'account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);

        app(RecordMerger::class)->mergeAccounts($keep, $duplicate, $manager);

        $this->assertSame($keep->getKey(), $deal->refresh()->account_id, 'The deal stayed on the trashed duplicate account.');
        $this->assertSame($keep->getKey(), $task->refresh()->account_id, 'The task stayed on the trashed duplicate account.');
        $this->assertSame($keep->getKey(), $note->refresh()->account_id, 'The note stayed on the trashed duplicate account.');
        $this->assertSame($keep->getKey(), $activity->refresh()->account_id, 'The activity stayed on the trashed duplicate account.');
    }

    #[Test]
    public function merging_contacts_moves_deals_and_deal_roles_tasks_and_notes_to_the_surviving_contact(): void
    {
        $manager = $this->salesManager();
        $account = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $keep = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);
        $duplicate = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);
        $deal = Deal::factory()->create(['account_id' => $account->getKey(), 'contact_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $deal->contacts()->attach($duplicate->getKey(), ['role' => 'decision_maker']);
        $task = Task::factory()->create(['contact_id' => $duplicate->getKey(), 'assignee_id' => $manager->getKey()]);
        $note = Note::factory()->create(['contact_id' => $duplicate->getKey(), 'author_id' => $manager->getKey()]);

        app(RecordMerger::class)->mergeContacts($keep, $duplicate, $manager);

        $this->assertSame($keep->getKey(), $deal->refresh()->contact_id, 'The deal still names the trashed duplicate as its primary contact.');
        $this->assertTrue($deal->contacts()->whereKey($keep->getKey())->exists(), 'The deal role stayed on the trashed duplicate.');
        $this->assertSame($keep->getKey(), $task->refresh()->contact_id, 'The task stayed on the trashed duplicate contact.');
        $this->assertSame($keep->getKey(), $note->refresh()->contact_id, 'The note stayed on the trashed duplicate contact.');
    }
}
