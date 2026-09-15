<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The activity resource (decisions D-4, A-10): logging from the form,
 * validation, the two reading paths (own scope OR a readable subject), the
 * absence of any edit, the gated delete and the list tabs.
 */
final class ActivityResourceTest extends TestCase
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
    public function a_rep_logs_a_call_against_a_lead_from_the_resource_form_and_it_appears_in_the_list(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $callType = $this->typeOfKind(ActivityKind::Call);

        Livewire::actingAs($rep)
            ->test(CreateActivity::class)
            ->fillForm([
                'activity_type_id' => $callType->getKey(),
                'subject' => 'مكالمة بخصوص العرض',
                'body' => 'Asked for the price list.',
                'occurred_at' => '2026-09-06 10:15:00',
                'direction' => ActivityDirection::Outbound->value,
                'duration_minutes' => 20,
                'outcome' => 'Interested',
                'lead_id' => $lead->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('activities.notifications.logged'));

        $activity = Activity::query()->where('subject', 'مكالمة بخصوص العرض')->firstOrFail();

        $this->assertSame(ActivityKind::Call, $activity->kind);
        $this->assertSame($callType->getKey(), (int) $activity->activity_type_id);
        $this->assertSame($lead->getKey(), (int) $activity->lead_id);
        $this->assertSame(ActivityDirection::Outbound, $activity->direction);
        $this->assertSame(20, (int) $activity->duration_minutes);
        $this->assertSame('Interested', $activity->outcome);
        $this->assertSame('2026-09-06 10:15:00', $activity->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
        $this->assertSame($rep->getKey(), (int) $activity->created_by);
        $this->assertNotNull($lead->refresh()->last_activity_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ActivityCreated->value, 'subject_id' => $activity->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$activity])
            ->assertSee($lead->full_name);

        $this->actingAs($rep)->get(ActivityResource::getUrl('view', ['record' => $activity]))->assertOk();
    }

    #[Test]
    public function the_form_requires_a_type_a_subject_and_at_least_one_related_record(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(CreateActivity::class)
            ->fillForm(['activity_type_id' => null, 'subject' => '', 'occurred_at' => '2026-09-06 10:00:00'])
            ->call('create')
            ->assertHasFormErrors([
                'activity_type_id' => 'required',
                'subject' => 'required',
                'related_guard',
            ]);

        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function the_form_offers_only_active_non_system_types(): void
    {
        $inactive = ActivityType::factory()->create(['is_active' => false]);
        $system = $this->typeOfKind(ActivityKind::System);
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateActivity::class)
            ->fillForm(['activity_type_id' => $inactive->getKey(), 'subject' => 'x', 'lead_id' => $lead->getKey()])
            ->call('create')
            ->assertHasFormErrors(['activity_type_id']);

        Livewire::actingAs($rep)
            ->test(CreateActivity::class)
            ->fillForm(['activity_type_id' => $system->getKey(), 'subject' => 'x', 'lead_id' => $lead->getKey()])
            ->call('create')
            ->assertHasFormErrors(['activity_type_id']);

        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function a_record_outside_the_actor_reach_cannot_be_linked(): void
    {
        $rep = $this->salesRep();
        $theirs = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateActivity::class)
            ->fillForm([
                'activity_type_id' => $this->typeOfKind(ActivityKind::Note)->getKey(),
                'subject' => 'Sneaky',
                'lead_id' => $theirs->getKey(),
            ])
            ->call('create')
            ->assertHasFormErrors(['lead_id']);

        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function an_activity_on_a_lead_the_actor_owns_is_readable_even_when_someone_else_owns_the_activity(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => $lead->getKey(), 'owner_id' => $support->getKey(), 'created_by' => $support->getKey()]);

        $this->assertTrue($rep->can('view', $activity));

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$activity]);

        $this->actingAs($rep)->get(ActivityResource::getUrl('view', ['record' => $activity]))->assertOk();
    }

    #[Test]
    public function an_activity_on_records_outside_the_actor_reach_is_hidden_and_its_page_is_not_found(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $theirLead = Lead::factory()->create(['owner_id' => $other->getKey()]);
        $theirAccount = Account::factory()->create(['owner_id' => $other->getKey()]);
        $activity = Activity::factory()->create([
            'lead_id' => $theirLead->getKey(),
            'account_id' => $theirAccount->getKey(),
            'owner_id' => $other->getKey(),
            'created_by' => $other->getKey(),
        ]);
        $mine = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $rep->getKey()])->getKey(), 'owner_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('view', $activity));
        $this->assertTrue($rep->can('view', $mine));

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$activity]);

        $this->actingAs($rep)->get(ActivityResource::getUrl('view', ['record' => $activity]))->assertNotFound();
        $this->actingAs($other)->get(ActivityResource::getUrl('view', ['record' => $activity]))->assertOk();
    }

    #[Test]
    public function team_and_all_levels_widen_the_activity_scope(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $repActivity = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $rep->getKey()])->getKey(), 'owner_id' => $rep->getKey()]);
        $outsiderActivity = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $outsider->getKey()])->getKey(), 'owner_id' => $outsider->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$repActivity])
            ->assertCanNotSeeTableRecords([$outsiderActivity]);

        Livewire::actingAs($this->readOnly())
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$repActivity, $outsiderActivity]);

        Livewire::actingAs($this->admin())
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$repActivity, $outsiderActivity]);
    }

    #[Test]
    public function a_read_only_user_cannot_log_an_activity_but_support_can(): void
    {
        $readOnly = $this->readOnly();
        $support = $this->support();

        $this->assertFalse($readOnly->can('create', Activity::class));
        $this->assertTrue($support->can('create', Activity::class));

        $this->actingAs($readOnly)->get(ActivityResource::getUrl('create'))->assertForbidden();
        $this->actingAs($support)->get(ActivityResource::getUrl('create'))->assertOk();

        Livewire::actingAs($readOnly)
            ->test(ListActivities::class)
            ->assertActionHidden('create');
    }

    #[Test]
    public function there_is_no_edit_page_and_nobody_may_update(): void
    {
        $admin = $this->admin();
        $activity = Activity::factory()->create(['owner_id' => $admin->getKey()]);

        $this->assertFalse(ActivityResource::hasPage('edit'));
        $this->assertFalse($admin->can('update', $activity));
        $this->assertFalse(ActivityResource::canEdit($activity));

        Livewire::actingAs($admin)
            ->test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertActionVisible('delete')
            ->assertSee(__('activities.validation.immutable'))
            ->assertOk();
    }

    #[Test]
    public function deleting_needs_the_activity_delete_permission_and_is_audited(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('delete', $activity));
        $this->assertFalse($support->can('delete', $activity));
        $this->assertTrue($manager->can('delete', $activity));

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertTableActionHidden('delete', $activity);

        Livewire::actingAs($support)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$activity])
            ->assertTableActionHidden('delete', $activity);

        Livewire::actingAs($support)
            ->test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($manager)
            ->test(ListActivities::class)
            ->callTableAction('delete', $activity)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('activities.notifications.deleted'));

        $this->assertDatabaseMissing('activities', ['id' => $activity->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ActivityDeleted->value, 'subject_id' => $activity->getKey(), 'causer_id' => $manager->getKey()]);
    }

    #[Test]
    public function bulk_delete_skips_the_records_the_actor_may_not_delete(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $inTeam = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $rep->getKey()])->getKey(), 'owner_id' => $rep->getKey()]);
        $outside = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $outsider->getKey()])->getKey(), 'owner_id' => $outsider->getKey()]);

        Livewire::actingAs($this->admin())
            ->test(ListActivities::class)
            ->callTableBulkAction('delete', [$inTeam, $outside])
            ->assertHasNoTableBulkActionErrors();

        $this->assertDatabaseMissing('activities', ['id' => $inTeam->getKey()]);
        $this->assertDatabaseMissing('activities', ['id' => $outside->getKey()]);

        $again = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $rep->getKey()])->getKey(), 'owner_id' => $rep->getKey()]);
        $outsideAgain = Activity::factory()->create(['lead_id' => Lead::factory()->create(['owner_id' => $outsider->getKey()])->getKey(), 'owner_id' => $outsider->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListActivities::class)
            ->callTableBulkAction('delete', [$again, $outsideAgain]);

        $this->assertDatabaseMissing('activities', ['id' => $again->getKey()]);
        $this->assertDatabaseHas('activities', ['id' => $outsideAgain->getKey()]);
    }

    #[Test]
    public function the_list_tabs_split_activities_by_kind_and_ownership(): void
    {
        $admin = $this->admin();
        $other = $this->salesRep();
        $call = Activity::factory()->ofKind(ActivityKind::Call)->create(['owner_id' => $admin->getKey()]);
        $meeting = Activity::factory()->ofKind(ActivityKind::Meeting)->create(['owner_id' => $admin->getKey()]);
        $email = Activity::factory()->ofKind(ActivityKind::Email)->create(['owner_id' => $other->getKey()]);
        $note = Activity::factory()->ofKind(ActivityKind::Note)->create(['owner_id' => $other->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$call, $meeting, $email, $note])
            ->set('activeTab', 'mine')
            ->assertCanSeeTableRecords([$call, $meeting])
            ->assertCanNotSeeTableRecords([$email, $note])
            ->set('activeTab', 'calls')
            ->assertCanSeeTableRecords([$call])
            ->assertCanNotSeeTableRecords([$meeting, $email, $note])
            ->set('activeTab', 'meetings')
            ->assertCanSeeTableRecords([$meeting])
            ->assertCanNotSeeTableRecords([$call, $email, $note])
            ->set('activeTab', 'emails')
            ->assertCanSeeTableRecords([$email])
            ->assertCanNotSeeTableRecords([$call, $meeting, $note]);
    }

    #[Test]
    public function the_subject_column_links_to_the_most_specific_record_the_reader_may_open(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'contact_id' => $contact->getKey()]);
        $activity = Activity::factory()->create([
            'lead_id' => null,
            'contact_id' => $contact->getKey(),
            'account_id' => $account->getKey(),
            'deal_id' => $deal->getKey(),
            'owner_id' => $rep->getKey(),
        ]);

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$activity])
            ->assertSee($deal->title);

        $this->actingAs($rep);
        $this->assertNotNull(ActivityResource::urlForSubject($deal));
        $this->assertNotNull(ActivityResource::urlForSubject($contact));

        $stranger = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $this->assertNull(ActivityResource::urlForSubject($stranger));
        $this->assertNull(ActivityResource::urlForSubject(User::factory()->create()));
    }

    #[Test]
    public function a_user_without_activity_view_any_is_refused_even_with_full_lead_access(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey()]);
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('lead.view_any', 'lead.view_all');

        $this->assertFalse($outsider->can('viewAny', Activity::class));
        $this->assertFalse($outsider->can('view', $activity));
        $this->actingAs($outsider)->get(ActivityResource::getUrl('index'))->assertForbidden();
        $this->assertContains($this->actingAs($outsider)->get(ActivityResource::getUrl('view', ['record' => $activity]))->status(), [403, 404]);
    }

    #[Test]
    public function a_crafted_owner_direction_and_kind_never_reach_the_recorder(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $meeting = $this->typeOfKind(ActivityKind::Meeting);

        $this->actingAs($rep);
        $activity = ActivityForm::log(ActivityForm::subjectFrom(['lead_id' => $lead->getKey()]), [
            'activity_type_id' => $meeting->getKey(),
            'subject' => 'Kick-off',
            'kind' => ActivityKind::Call->value,
            'direction' => ActivityDirection::Inbound->value,
            'owner_id' => $other->getKey(),
        ], $rep);

        $this->assertSame(ActivityKind::Meeting, $activity->kind);
        $this->assertNull($activity->direction);
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
    }

    #[Test]
    public function the_resource_form_widens_a_deal_to_its_account_and_primary_contact_like_a_record_page(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'contact_id' => $contact->getKey()]);

        $this->actingAs($rep);
        $subject = ActivityForm::subjectFrom(['deal_id' => $deal->getKey()]);

        $this->assertSame([
            'lead_id' => null,
            'contact_id' => $contact->getKey(),
            'account_id' => $account->getKey(),
            'deal_id' => $deal->getKey(),
        ], $subject->foreignKeys());
    }
}
