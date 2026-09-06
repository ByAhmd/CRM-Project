<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\CrmRole;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadNotesRelationManager;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Notifications\NoteMentionNotification;
use App\Services\Notes\NoteService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Mentions in notes (plan section 3.6, decisions A-10, D-4): the users the
 * author names are told once the note is saved, the author never is, and
 * anyone outside the author's reach or unable to read the subject is
 * dropped without a trace. The notes managers pass the picker's selection
 * to the service.
 */
final class NoteMentionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();
    }

    #[Test]
    public function mentioned_team_members_who_can_read_the_subject_are_notified_and_the_author_is_not(): void
    {
        $team = $this->makeTeam();
        $manager = $this->makeUser(CrmRole::SalesManager, ['name' => 'Team Manager', 'locale' => 'en'], $team);
        $owner = $this->makeUser(CrmRole::SalesRep, ['name' => 'Lead Owner', 'locale' => 'ar'], $team);
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey(), 'first_name' => 'Huda', 'last_name' => 'Salem']);

        $note = $this->service()->create($lead, $manager, 'Please call Huda back before Thursday.', false, [$owner->getKey(), $manager->getKey()]);

        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 1);
        Notification::assertNotSentTo($manager, NoteMentionNotification::class);
        Notification::assertSentTo($owner, NoteMentionNotification::class, function (NoteMentionNotification $notification, array $channels) use ($owner, $lead, $note): bool {
            $this->assertSame(['database'], $channels);
            $this->assertSame('ar', $notification->locale);

            $database = $notification->toDatabase($owner);
            $body = (string) ($database['body'] ?? '');

            $this->assertSame(__('notifications.events.note_mention.title', [], 'ar'), $database['title'] ?? null);
            $this->assertStringContainsString('Team Manager', $body);
            $this->assertStringContainsString('Huda Salem', $body);
            $this->assertStringContainsString($note->excerpt(120), $body);
            $this->assertSame(LeadResource::getUrl('view', ['record' => $lead]), $notification->url());
            $this->assertSame(LeadResource::getUrl('view', ['record' => $lead]), $notification->toMail($owner)->actionUrl);

            return true;
        });

        // Mentions are not persisted: the note row is the same as any other.
        $this->assertDatabaseHas('notes', ['id' => $note->getKey(), 'lead_id' => $lead->getKey(), 'author_id' => $manager->getKey()]);
    }

    #[Test]
    public function users_outside_the_authors_reach_or_unable_to_read_the_subject_are_dropped_silently(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->salesRep($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        // A teammate at own level cannot read the owner's lead; the outsider is outside the team.
        $this->assertFalse($teammate->can('view', $lead));
        $this->assertFalse($outsider->can('view', $lead));

        $this->service()->create($lead, $manager, 'Loop in whoever can help.', false, [$owner->getKey(), $teammate->getKey(), $outsider->getKey(), 999999]);

        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 1);
        Notification::assertNotSentTo($teammate, NoteMentionNotification::class);
        Notification::assertNotSentTo($outsider, NoteMentionNotification::class);
    }

    #[Test]
    public function a_rep_at_own_level_can_mention_nobody_but_an_admin_can_mention_anyone_who_reads_the_record(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $other = $this->salesRep();
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->service()->create($deal, $rep, 'Rep note', false, [$support->getKey(), $admin->getKey()]);

        Notification::assertNothingSent();

        $this->assertTrue($support->can('view', $deal));
        $this->assertFalse($other->can('view', $deal));

        $this->service()->create($deal, $admin, 'Admin note', false, [$support->getKey(), $other->getKey(), $rep->getKey()]);

        Notification::assertSentToTimes($support, NoteMentionNotification::class, 1);
        Notification::assertSentToTimes($rep, NoteMentionNotification::class, 1);
        Notification::assertNotSentTo($other, NoteMentionNotification::class);
        Notification::assertNotSentTo($admin, NoteMentionNotification::class);

        Notification::assertSentTo($support, NoteMentionNotification::class, fn (NoteMentionNotification $notification): bool => $notification->url() === DealResource::getUrl('view', ['record' => $deal]));
    }

    #[Test]
    public function editing_a_note_notifies_the_newly_mentioned_users_even_when_the_body_is_unchanged(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $note = $this->service()->create($lead, $manager, 'Original');

        Notification::assertNothingSent();

        $this->service()->update($note, $manager, 'Original', [$owner->getKey()]);

        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 1);
        $this->assertNull($note->refresh()->edited_at);

        $this->service()->update($note, $manager, 'Revised', [$owner->getKey()]);

        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 2);
        $this->assertSame('Revised', $note->refresh()->body);
        $this->assertNotNull($note->edited_at);
    }

    #[Test]
    public function the_notes_manager_offers_the_reachable_users_and_passes_the_selection_to_the_service(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->salesRep($team);
        $outsider = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        $instance = Livewire::actingAs($manager)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->mountTableAction('create')
            ->instance();
        assert($instance instanceof LeadNotesRelationManager);

        $schema = $instance->getSchema((string) $instance->getMountedActionSchemaName());
        $this->assertInstanceOf(Schema::class, $schema);

        $select = $schema->getFlatFields(withHidden: true)['mentions'] ?? null;
        $this->assertInstanceOf(Select::class, $select);
        $this->assertTrue($select->isMultiple());
        $this->assertSame(__('notes.fields.mentions'), $select->getLabel());

        $options = $select->getOptions();
        $this->assertArrayHasKey($owner->getKey(), $options);
        $this->assertArrayNotHasKey($outsider->getKey(), $options);
        $this->assertArrayNotHasKey($manager->getKey(), $options);

        // A user outside the picker's options is refused by the form itself.
        Livewire::actingAs($manager)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('create', data: ['body' => 'Handover notes', 'mentions' => [(string) $owner->getKey(), (string) $outsider->getKey()]])
            ->assertHasTableActionErrors();

        $this->assertSame(0, Note::query()->count());
        Notification::assertNothingSent();

        Livewire::actingAs($manager)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('create', data: ['body' => 'Handover notes', 'mentions' => [(string) $owner->getKey()]])
            ->assertHasNoTableActionErrors();

        $note = Note::query()->where('lead_id', $lead->getKey())->firstOrFail();

        $this->assertSame('Handover notes', $note->body);
        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 1);
        Notification::assertNotSentTo($outsider, NoteMentionNotification::class);

        Livewire::actingAs($manager)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('edit', $note, data: ['body' => 'Handover notes, updated', 'mentions' => [$owner->getKey()]])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Handover notes, updated', $note->refresh()->body);
        Notification::assertSentToTimes($owner, NoteMentionNotification::class, 2);
    }

    #[Test]
    public function a_rep_sees_no_one_to_mention_in_the_picker(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $instance = Livewire::actingAs($rep)
            ->test(LeadNotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->mountTableAction('create')
            ->instance();
        assert($instance instanceof LeadNotesRelationManager);

        $schema = $instance->getSchema((string) $instance->getMountedActionSchemaName());
        $this->assertInstanceOf(Schema::class, $schema);

        $select = $schema->getFlatFields(withHidden: true)['mentions'] ?? null;
        $this->assertInstanceOf(Select::class, $select);
        $this->assertSame([], $select->getOptions());
    }

    private function service(): NoteService
    {
        return app(NoteService::class);
    }
}
