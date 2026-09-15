<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Enums\ActivityLogEvent;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Notifications\RecordAssignedNotification;
use App\Services\Access\RecordAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contact reassignment through the panel (D-4): the record and bulk `assign`
 * actions on the contact list and page are offered only to assign-holders, reach
 * only records and users inside the actor's scope, and every change is
 * audited as `contact.assigned` and notified to the new owner.
 *
 * Livewire::actingAs switches the authenticated user for every component, so
 * each actor's component is run to completion before the next one is built.
 */
final class ContactAssignmentTest extends TestCase
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
    public function a_rep_is_never_offered_the_assign_actions(): void
    {
        $rep = $this->salesRep($this->makeTeam());
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('assign', $contact), 'D-4: reps never reassign');

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->assertTableActionHidden('assign', $contact);

        Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertActionHidden('assign');

        // The bulk action authorises each selected record, so a rep's attempt reassigns nothing.
        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->callTableBulkAction('assign', [$contact], data: ['owner_id' => null]);

        $this->assertSame($rep->getKey(), $contact->refresh()->owner_id);
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::ContactAssigned->value)->count());
    }

    #[Test]
    public function a_manager_reassigns_a_team_contact_from_the_list_with_an_audit_entry_and_a_notification(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $from = $this->salesRep($team);
        $to = $this->salesRep($team);
        $contact = Contact::factory()->create(['owner_id' => $from->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->assertTableActionVisible('assign', $contact)
            ->callTableAction('assign', $contact, data: ['owner_id' => $to->getKey()])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('assignment.notifications.assigned', ['name' => $to->name]));

        $this->assertSame($to->getKey(), $contact->refresh()->owner_id);

        $log = ActivityLog::query()->where('description', ActivityLogEvent::ContactAssigned->value)->latest('id')->firstOrFail();
        $this->assertSame($contact->getKey(), (int) $log->subject_id);
        $this->assertSame($manager->getKey(), (int) $log->causer_id);
        $this->assertSame($from->getKey(), (int) $log->properties->get('previous_owner_id'));
        $this->assertSame($to->getKey(), (int) $log->properties->get('owner_id'));

        Notification::assertSentTo($to, RecordAssignedNotification::class, fn (RecordAssignedNotification $notification, array $channels): bool => $channels === ['database']);
        Notification::assertNotSentTo($manager, RecordAssignedNotification::class);
    }

    #[Test]
    public function a_manager_cannot_hand_a_contact_to_someone_outside_the_team(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        // The panel only lists assignable users…
        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableAction('assign', $contact, data: ['owner_id' => $outsider->getKey()])
            ->assertHasTableActionErrors(['owner_id']);

        $this->assertSame($rep->getKey(), $contact->refresh()->owner_id);
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::ContactAssigned->value)->count());

        // …and the service refuses the same target when it is called directly.
        $this->expectException(UnassignableUserException::class);
        app(RecordAssignmentService::class)->assign($contact, $outsider, $manager);
    }

    #[Test]
    public function a_manager_reassigns_from_the_contact_page(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($manager)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertActionVisible('assign')
            ->callAction('assign', data: ['owner_id' => $manager->getKey()])
            ->assertHasNoActionErrors()
            ->assertNotified(__('assignment.notifications.assigned', ['name' => $manager->name]));

        $this->assertSame($manager->getKey(), $contact->refresh()->owner_id);
    }

    #[Test]
    public function bulk_assignment_skips_the_contacts_outside_the_actor_scope(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $inTeam = Contact::factory()->create(['owner_id' => $member->getKey()]);
        $outside = Contact::factory()->create(['owner_id' => $outsider->getKey()]);

        $this->assertTrue($manager->can('assign', $inTeam));
        $this->assertFalse($manager->can('assign', $outside));

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableBulkAction('assign', [$inTeam, $outside], data: ['owner_id' => $manager->getKey()])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($manager->getKey(), $inTeam->refresh()->owner_id);
        $this->assertSame($outsider->getKey(), $outside->refresh()->owner_id);
        $this->assertSame(
            [$inTeam->getKey()],
            ActivityLog::query()->where('description', ActivityLogEvent::ContactAssigned->value)->pluck('subject_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        Notification::assertNothingSentTo($outsider);
    }
}
