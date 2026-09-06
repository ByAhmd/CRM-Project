<?php

declare(strict_types=1);

namespace Tests\Feature\Notes;

use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Services\Notes\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * NoteService (decision A-10): the subject keys are derived from the record
 * the note is written on, the body is trimmed and required, edits stamp
 * `edited_at` and leave the body diff in the ledger, pin toggles are audited
 * on top, and deletion is soft and reversible.
 */
final class NoteServiceTest extends TestCase
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
    public function a_note_on_a_lead_carries_the_lead_and_the_author(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $note = $this->service()->create($lead, $rep, 'Called twice, no answer.');

        $this->assertDatabaseHas('notes', [
            'id' => $note->getKey(),
            'lead_id' => $lead->getKey(),
            'contact_id' => null,
            'account_id' => null,
            'deal_id' => null,
            'author_id' => $rep->getKey(),
            'is_pinned' => 0,
            'edited_at' => null,
        ]);
        $this->assertTrue($note->subjectRecord()?->is($lead));
        $this->assertSame($lead->full_name, $note->subjectLabel());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteCreated->value, 'subject_id' => $note->getKey(), 'subject_type' => Note::class]);
    }

    #[Test]
    public function a_note_on_a_contact_also_carries_the_contact_account(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        $note = $this->service()->create($contact, $rep, 'Prefers WhatsApp.', pinned: true);

        $this->assertDatabaseHas('notes', [
            'id' => $note->getKey(),
            'contact_id' => $contact->getKey(),
            'account_id' => $account->getKey(),
            'lead_id' => null,
            'deal_id' => null,
            'is_pinned' => 1,
        ]);
        $this->assertTrue($note->subjectRecord()?->is($contact));
        $this->assertTrue($account->notes()->whereKey($note->getKey())->exists());
    }

    #[Test]
    public function a_note_on_a_deal_also_carries_the_deal_account(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $note = $this->service()->create($deal, $rep, 'Budget approved for Q4.');

        $this->assertDatabaseHas('notes', [
            'id' => $note->getKey(),
            'deal_id' => $deal->getKey(),
            'account_id' => $deal->account_id,
            'lead_id' => null,
            'contact_id' => null,
        ]);
        $this->assertTrue($note->subjectRecord()?->is($deal));
        $this->assertSame($deal->title, $note->subjectLabel());
    }

    #[Test]
    public function a_note_on_an_account_carries_only_the_account(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        $note = $this->service()->create($account, $rep, 'Head office moved to Jeddah.');

        $this->assertDatabaseHas('notes', ['id' => $note->getKey(), 'account_id' => $account->getKey(), 'lead_id' => null, 'contact_id' => null, 'deal_id' => null]);
        $this->assertTrue($note->subjectRecord()?->is($account));
    }

    #[Test]
    public function the_body_is_trimmed_with_line_endings_normalised_and_inner_newlines_kept(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $note = $this->service()->create($lead, $rep, "  First line\r\nSecond line\r\n\n");

        $this->assertSame("First line\nSecond line", $note->body);
        $this->assertSame('First line Second line', $note->excerpt());
        $this->assertSame('First li...', $note->excerpt(8));
    }

    #[Test]
    public function an_empty_body_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->expectException(ValidationException::class);

        $this->service()->create($lead, $rep, "   \n\t ");
    }

    #[Test]
    public function only_the_four_subject_models_accept_notes(): void
    {
        $rep = $this->salesRep();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->create(User::factory()->create(), $rep, 'Not a subject.');
    }

    #[Test]
    public function editing_stamps_edited_at_and_keeps_the_body_diff_in_the_ledger(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Original text');

        $editedAt = Carbon::parse('2026-09-06 14:30:00', 'Asia/Riyadh');
        $this->travelTo($editedAt);

        $updated = $this->service()->update($note, $rep, "  Revised text\r\n");

        $this->assertSame('Revised text', $updated->body);
        $this->assertTrue($updated->edited_at?->equalTo($editedAt));

        $audit = ActivityLog::query()
            ->where('description', ActivityLogEvent::NoteUpdated->value)
            ->where('subject_id', $note->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($rep->getKey(), (int) $audit->causer_id);
        $this->assertSame('Original text', $audit->properties->get('old')['body'] ?? null);
        $this->assertSame('Revised text', $audit->properties->get('attributes')['body'] ?? null);
    }

    #[Test]
    public function editing_with_the_same_body_changes_nothing(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Same text');

        $this->service()->update($note, $rep, "Same text\n");

        $this->assertNull($note->fresh()?->edited_at);
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::NoteUpdated->value)->count());
    }

    #[Test]
    public function editing_to_an_empty_body_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Keep me');

        $this->expectException(ValidationException::class);

        $this->service()->update($note, $rep, '');
    }

    #[Test]
    public function pinning_and_unpinning_are_audited_with_the_excerpt(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($deal, $rep, 'Decision maker is the CFO, meet her first.');

        $this->service()->pin($note, $rep);

        $this->assertTrue($note->fresh()?->is_pinned);

        $pinned = ActivityLog::query()->where('description', ActivityLogEvent::NotePinned->value)->latest('id')->firstOrFail();
        $this->assertSame($note->getKey(), (int) $pinned->subject_id);
        $this->assertSame(Note::class, $pinned->subject_type);
        $this->assertSame($rep->getKey(), (int) $pinned->causer_id);
        $this->assertSame($note->excerpt(), $pinned->properties->get('subject_label'));

        $this->service()->unpin($note, $rep);

        $this->assertFalse($note->refresh()->is_pinned);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteUnpinned->value, 'subject_id' => $note->getKey()]);
    }

    #[Test]
    public function pinning_a_pinned_note_writes_no_second_audit_row(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Already pinned', pinned: true);

        $this->service()->pin($note, $rep);

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::NotePinned->value)->count());
    }

    #[Test]
    public function deleting_is_soft_and_restoring_brings_the_note_back(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Temporary');

        $this->service()->delete($note, $rep);

        $this->assertSoftDeleted('notes', ['id' => $note->getKey()]);
        $this->assertSame(0, $lead->notes()->count());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteDeleted->value, 'subject_id' => $note->getKey()]);

        $this->service()->restore($note, $rep);

        $this->assertNull($note->fresh()?->deleted_at);
        $this->assertSame(1, $lead->notes()->count());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::NoteRestored->value, 'subject_id' => $note->getKey()]);
    }

    #[Test]
    public function the_subject_relation_lists_pinned_notes_first_then_newest(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $oldest = $this->service()->create($lead, $rep, 'Oldest');

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $pinned = $this->service()->create($lead, $rep, 'Pinned', pinned: true);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $newest = $this->service()->create($lead, $rep, 'Newest');

        $this->assertSame(
            [$pinned->getKey(), $newest->getKey(), $oldest->getKey()],
            $lead->notes()->pluck('id')->map(fn (int|string $id): int => (int) $id)->all(),
        );
    }

    #[Test]
    public function an_orphaned_note_can_no_longer_be_edited_or_pinned(): void
    {
        $rep = $this->salesRep();
        $note = Note::factory()->create(['author_id' => $rep->getKey()]);

        $this->assertNull($note->subjectRecord());
        $this->assertSame('', $note->subjectLabel());

        $this->expectException(InvalidArgumentException::class);

        $this->service()->update($note, $rep, 'Nobody owns me');
    }

    #[Test]
    public function a_body_over_the_limit_is_refused_by_the_service(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->service()->create($lead, $rep, str_repeat('x', Note::MAX_BODY_LENGTH));

        $this->assertSame(1, Note::query()->count());

        try {
            $this->service()->create($lead, $rep, str_repeat('y', Note::MAX_BODY_LENGTH + 1));
            $this->fail('An oversized body was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }

        $this->assertSame(1, Note::query()->count());
    }

    #[Test]
    public function a_trashed_note_is_neither_edited_nor_pinned_until_restored(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Gone for now');

        $this->service()->delete($note, $rep);

        try {
            $this->service()->update($note, $rep, 'Edited while trashed');
            $this->fail('A trashed note was edited.');
        } catch (InvalidArgumentException) {
        }

        try {
            $this->service()->pin($note, $rep);
            $this->fail('A trashed note was pinned.');
        } catch (InvalidArgumentException) {
        }

        $fresh = Note::withTrashed()->findOrFail($note->getKey());
        $this->assertSame('Gone for now', $fresh->body);
        $this->assertNull($fresh->edited_at);
        $this->assertFalse($fresh->is_pinned);

        $this->service()->restore($note, $rep);
        $this->service()->update($note, $rep, 'Edited after restore');

        $this->assertSame('Edited after restore', $note->fresh()?->body);
    }

    #[Test]
    public function a_note_on_a_soft_deleted_lead_still_resolves_to_the_lead_for_its_owner(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = $this->service()->create($lead, $rep, 'Survives the lead being trashed');

        $lead->delete();

        $fresh = $note->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->subjectRecord()?->is($lead));
        $this->assertSame($lead->full_name, $fresh->subjectLabel());
        $this->assertTrue($rep->can('view', $fresh));
        $this->assertTrue($rep->can('update', $fresh));
        $this->assertTrue($rep->can('pin', $fresh));
        $this->assertFalse($this->salesRep()->can('view', $fresh));
    }

    private function service(): NoteService
    {
        return app(NoteService::class);
    }
}
