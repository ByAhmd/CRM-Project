<?php

declare(strict_types=1);

namespace Tests\Feature\Notes;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountNotesRelationManager;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactNotesRelationManager;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealNotesRelationManager;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadNotesRelationManager;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Services\Notes\NoteService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The notes managers on the lead, contact, account and deal pages (decisions
 * D-4, A-10): reading follows the subject, writing needs the `note.*` keys
 * and authorship or `update` on the subject — and every refusal is a policy
 * answer, not a hidden button.
 */
final class NotesRelationManagerTest extends TestCase
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
    public function a_rep_adds_a_note_to_their_own_lead_from_the_view_page(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->actingAs($rep);
        $this->assertTrue(LeadNotesRelationManager::canViewForRecord($lead, ViewLead::class));

        Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['body' => "First line\nSecond line", 'is_pinned' => true])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('notes', [
            'lead_id' => $lead->getKey(),
            'author_id' => $rep->getKey(),
            'body' => "First line\nSecond line",
            'is_pinned' => 1,
        ]);
    }

    #[Test]
    public function an_empty_note_is_refused_by_the_form(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callTableAction('create', data: ['body' => ''])
            ->assertHasTableActionErrors(['body']);

        $this->assertSame(0, Note::query()->count());
    }

    #[Test]
    public function a_rep_edits_their_own_note_and_the_edit_is_stamped(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Draft');

        Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$note])
            ->assertTableActionVisible('edit', $note)
            ->callTableAction('edit', $note, data: ['body' => 'Final'])
            ->assertHasNoTableActionErrors();

        $fresh = $note->refresh();

        $this->assertSame('Final', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteUpdated->value, 'subject_id' => $note->getKey()]);
    }

    #[Test]
    public function support_edits_its_own_note_but_not_a_rep_note_on_a_lead_it_cannot_update(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $repNote = app(NoteService::class)->create($lead, $rep, 'Written by the rep');
        $ownNote = app(NoteService::class)->create($lead, $support, 'Written by support');

        // Support holds note.update and reads every lead, but may not update the lead itself.
        $this->assertTrue($support->can('view', $lead));
        $this->assertFalse($support->can('update', $lead));
        $this->assertTrue($support->can('update', $ownNote));
        $this->assertFalse($support->can('update', $repNote));
        $this->assertFalse($support->can('pin', $repNote));

        $manager = Livewire::actingAs($support)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$repNote, $ownNote])
            ->assertTableActionVisible('edit', $ownNote)
            ->assertTableActionHidden('edit', $repNote)
            ->assertTableActionHidden('togglePin', $repNote);

        $manager
            ->callTableAction('edit', $ownNote, data: ['body' => 'Support revised'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Support revised', $ownNote->fresh()?->body);
        $this->assertSame('Written by the rep', $repNote->fresh()?->body);
    }

    #[Test]
    public function a_manager_edits_a_team_rep_note_because_they_may_update_the_lead(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Rep wrote this');

        $this->assertTrue($manager->can('update', $note));

        Livewire::actingAs($manager)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('edit', $note)
            ->callTableAction('edit', $note, data: ['body' => 'Manager tidied this'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Manager tidied this', $note->fresh()?->body);
    }

    #[Test]
    public function a_rep_pins_and_unpins_a_note(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($deal, $rep, 'Pin me');

        $manager = Livewire::actingAs($rep)
            ->test(DealNotesRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('togglePin', $note)
            ->callTableAction('togglePin', $note)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($note->fresh()?->is_pinned);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NotePinned->value, 'subject_id' => $note->getKey()]);

        $manager
            ->callTableAction('togglePin', $note)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($note->refresh()->is_pinned);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteUnpinned->value, 'subject_id' => $note->getKey()]);
    }

    #[Test]
    public function a_rep_deletes_their_own_note_and_restores_it_from_the_trashed_filter(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Delete me');

        $manager = Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('delete', $note)
            ->callTableAction('delete', $note)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('notes', ['id' => $note->getKey()]);

        $manager
            ->assertCanNotSeeTableRecords([$note])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$note])
            ->assertTableActionHidden('togglePin', $note)
            ->assertTableActionHidden('edit', $note)
            ->callTableAction('restore', $note)
            ->assertHasNoTableActionErrors();

        $this->assertNull($note->fresh()?->deleted_at);
    }

    #[Test]
    public function support_adds_notes_but_holds_no_delete_key(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $manager = Livewire::actingAs($support)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['body' => 'Support note'])
            ->assertHasNoTableActionErrors();

        $note = Note::query()->where('author_id', $support->getKey())->firstOrFail();

        $this->assertFalse($support->can('delete', $note));

        $manager->assertTableActionHidden('delete', $note);
    }

    #[Test]
    public function the_manager_is_hidden_for_a_user_who_cannot_view_the_subject(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Private to the owner');

        $this->assertFalse($other->can('view', $lead));
        $this->assertFalse($other->can('view', $note));
        $this->assertFalse($other->can('create', [Note::class, $lead]));
        $this->assertTrue($other->can('create', [Note::class, Lead::factory()->create(['owner_id' => $other->getKey()])]));

        $this->actingAs($other);

        $this->assertFalse(LeadNotesRelationManager::canViewForRecord($lead, ViewLead::class));

        $this->actingAs($rep);

        $this->assertTrue(LeadNotesRelationManager::canViewForRecord($lead, ViewLead::class));
        $this->assertTrue($rep->can('view', $note));
    }

    #[Test]
    public function a_read_only_user_reads_the_notes_but_sees_none_of_the_write_actions(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Visible to everyone who reads the lead');

        $this->actingAs($readOnly);

        $this->assertTrue(LeadNotesRelationManager::canViewForRecord($lead, ViewLead::class));
        $this->assertTrue($readOnly->can('view', $note));
        $this->assertFalse($readOnly->can('create', [Note::class, $lead]));
        $this->assertFalse($readOnly->can('update', $note));
        $this->assertFalse($readOnly->can('delete', $note));

        Livewire::actingAs($readOnly)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$note])
            ->assertTableActionVisible('view', $note)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $note)
            ->assertTableActionHidden('togglePin', $note)
            ->assertTableActionHidden('delete', $note)
            ->assertOk();
    }

    #[Test]
    public function a_contact_note_appears_on_the_contact_and_on_its_account(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        Livewire::actingAs($rep)
            ->test(ContactNotesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => ViewContact::class])
            ->callTableAction('create', data: ['body' => 'Met at the trade fair'])
            ->assertHasNoTableActionErrors();

        $note = Note::query()->where('contact_id', $contact->getKey())->firstOrFail();

        $this->assertSame($account->getKey(), (int) $note->account_id);

        Livewire::actingAs($rep)
            ->test(AccountNotesRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$note])
            ->assertSee('Met at the trade fair');
    }

    #[Test]
    public function the_view_modal_shows_the_full_body(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $body = str_repeat('A long note. ', 20)."\nTHE END";
        $note = app(NoteService::class)->create($lead, $rep, $body);

        $manager = Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertSee($note->excerpt(120))
            ->assertDontSee('THE END')
            ->mountTableAction('view', $note)
            ->instance();
        assert($manager instanceof LeadNotesRelationManager);

        $schema = $manager->getSchema((string) $manager->getMountedActionSchemaName());
        $this->assertInstanceOf(Schema::class, $schema);

        $entry = $schema->getFlatComponents(withActions: false, withHidden: true)['body'] ?? null;
        $this->assertInstanceOf(TextEntry::class, $entry);
        $this->assertSame($body, $entry->getState());
        $this->assertFalse($entry->isMarkdown());
        $this->assertSame('whitespace-pre-line', $entry->getExtraAttributes()['class'] ?? null);
    }

    #[Test]
    public function the_account_manager_hides_notes_on_contacts_and_deals_the_owner_cannot_read(): void
    {
        $owner = $this->salesRep($this->makeTeam('Riyadh', 'الرياض'));
        $other = $this->salesRep($this->makeTeam('Jeddah', 'جدة'));
        $account = Account::factory()->create(['owner_id' => $owner->getKey()]);
        $foreignDeal = Deal::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);
        $foreignContact = Contact::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);
        $ownDeal = Deal::factory()->create(['owner_id' => $owner->getKey(), 'account_id' => $account->getKey()]);
        $ownContact = Contact::factory()->create(['owner_id' => $owner->getKey(), 'account_id' => $account->getKey()]);

        $foreignDealNote = app(NoteService::class)->create($foreignDeal, $other, 'SECRET deal pricing floor is 40k');
        $foreignContactNote = app(NoteService::class)->create($foreignContact, $other, 'SECRET contact is leaving the company');
        $accountNote = app(NoteService::class)->create($account, $other, 'Written on the account itself');
        $ownDealNote = app(NoteService::class)->create($ownDeal, $owner, 'My own deal note');
        $ownContactNote = app(NoteService::class)->create($ownContact, $owner, 'My own contact note');

        $this->assertTrue($owner->can('view', $account));
        $this->assertFalse($owner->can('view', $foreignDeal));
        $this->assertFalse($owner->can('view', $foreignContact));
        $this->assertFalse($owner->can('view', $foreignDealNote));
        $this->assertFalse($owner->can('view', $foreignContactNote));

        Livewire::actingAs($owner)
            ->test(AccountNotesRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$accountNote, $ownDealNote, $ownContactNote])
            ->assertCanNotSeeTableRecords([$foreignDealNote, $foreignContactNote])
            ->assertSee('Written on the account itself')
            ->assertDontSee('SECRET deal pricing floor')
            ->assertDontSee('SECRET contact is leaving')
            ->searchTable('SECRET')
            ->assertCanNotSeeTableRecords([$foreignDealNote, $foreignContactNote])
            ->assertDontSee('SECRET deal pricing floor')
            ->assertDontSee('SECRET contact is leaving')
            ->searchTable('');

        // The search persists in the session; cleared above so the admin lists everything.
        Livewire::actingAs($this->admin())
            ->test(AccountNotesRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$accountNote, $ownDealNote, $ownContactNote, $foreignDealNote, $foreignContactNote]);
    }

    #[Test]
    public function the_table_lists_pinned_first_by_default_and_honours_a_column_sort(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $oldest = app(NoteService::class)->create($lead, $rep, 'Oldest');

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $pinned = app(NoteService::class)->create($lead, $rep, 'Pinned', pinned: true);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $newest = app(NoteService::class)->create($lead, $rep, 'Newest');

        Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$pinned, $newest, $oldest], inOrder: true)
            ->sortTable('created_at', 'asc')
            ->assertCanSeeTableRecords([$oldest, $pinned, $newest], inOrder: true)
            ->sortTable('created_at', 'desc')
            ->assertCanSeeTableRecords([$newest, $pinned, $oldest], inOrder: true);
    }
}
