<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Activities\InvalidActivityException;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The activity recorder (decision A-10): the kind comes from the type, the
 * defaults are the actor and now, fields that do not apply to the kind are
 * dropped, the linked lead and deal are touched, everything is audited and
 * a written activity can never change.
 */
final class ActivityRecorderTest extends TestCase
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
    public function an_activity_is_recorded_with_the_kind_copied_from_the_type_and_the_actor_and_now_as_defaults(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 10:00:00'));
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $activity = app(ActivityRecorder::class)->record(
            ActivitySubject::for($lead),
            $this->typeOfKind(ActivityKind::Call),
            $rep,
            '  Called about the proposal  ',
            body: 'They asked for a discount.',
            direction: ActivityDirection::Outbound,
            durationMinutes: 12,
            outcome: 'Follow up next week',
        );

        $activity->refresh();

        $this->assertSame(ActivityKind::Call, $activity->kind);
        $this->assertSame('Called about the proposal', $activity->subject);
        $this->assertSame('They asked for a discount.', $activity->body);
        $this->assertSame(ActivityDirection::Outbound, $activity->direction);
        $this->assertSame(12, (int) $activity->duration_minutes);
        $this->assertSame('Follow up next week', $activity->outcome);
        $this->assertTrue($activity->occurred_at->equalTo(now()));
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
        $this->assertSame($rep->getKey(), (int) $activity->created_by);
        $this->assertSame($lead->getKey(), (int) $activity->lead_id);
        $this->assertNull($activity->contact_id);
        $this->assertNull($activity->account_id);
        $this->assertNull($activity->deal_id);
        $this->assertTrue($activity->created_at->equalTo(now()));
        $this->assertNull($activity->payload);
        $this->assertTrue($lead->is($activity->subjectRecord()));
        $this->assertSame($lead->full_name, $activity->subjectLabel());
    }

    #[Test]
    public function an_explicit_owner_and_occurred_at_are_kept(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $when = Carbon::parse('2026-09-01 08:30:00');

        $activity = app(ActivityRecorder::class)->record(
            ActivitySubject::for($lead),
            $this->typeOfKind(ActivityKind::Meeting),
            $manager,
            'Kick-off',
            occurredAt: $when,
            owner: $rep,
        );

        $this->assertTrue($activity->refresh()->occurred_at->equalTo($when));
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
        $this->assertSame($manager->getKey(), (int) $activity->created_by);
    }

    #[Test]
    public function direction_and_duration_are_kept_only_for_the_kinds_that_have_them(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $recorder = app(ActivityRecorder::class);

        $meeting = $recorder->record(ActivitySubject::for($lead), $this->typeOfKind(ActivityKind::Meeting), $rep, 'Meeting', direction: ActivityDirection::Inbound, durationMinutes: 45);
        $email = $recorder->record(ActivitySubject::for($lead), $this->typeOfKind(ActivityKind::Email), $rep, 'Email', direction: ActivityDirection::Inbound, durationMinutes: 45);
        $note = $recorder->record(ActivitySubject::for($lead), $this->typeOfKind(ActivityKind::Note), $rep, 'Note', direction: ActivityDirection::Inbound, durationMinutes: 45);

        $this->assertNull($meeting->refresh()->direction);
        $this->assertSame(45, (int) $meeting->duration_minutes);

        $this->assertSame(ActivityDirection::Inbound, $email->refresh()->direction);
        $this->assertNull($email->duration_minutes);

        $this->assertNull($note->refresh()->direction);
        $this->assertNull($note->duration_minutes);
    }

    #[Test]
    public function a_subject_is_required(): void
    {
        $this->expectException(InvalidActivityException::class);
        $this->expectExceptionMessage(__('activities.validation.subject_required'));

        new ActivitySubject;
    }

    #[Test]
    public function a_contact_subject_carries_its_account_and_a_deal_subject_its_account_and_primary_contact(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'contact_id' => $contact->getKey()]);
        $recorder = app(ActivityRecorder::class);

        $onContact = $recorder->record(ActivitySubject::for($contact), $this->typeOfKind(ActivityKind::Call), $rep, 'Call');
        $onDeal = $recorder->record(ActivitySubject::for($deal), $this->typeOfKind(ActivityKind::Meeting), $rep, 'Meeting');
        $onAccount = $recorder->record(ActivitySubject::for($account), $this->typeOfKind(ActivityKind::Other), $rep, 'Visit');

        $this->assertSame([null, $contact->getKey(), $account->getKey(), null], $this->links($onContact));
        $this->assertSame([null, $contact->getKey(), $account->getKey(), $deal->getKey()], $this->links($onDeal));
        $this->assertSame([null, null, $account->getKey(), null], $this->links($onAccount));

        $this->assertTrue($deal->is($onDeal->subjectRecord()));
        $this->assertSame($deal->title, $onDeal->subjectLabel());
        $this->assertTrue($contact->is($onContact->subjectRecord()));
        $this->assertTrue($account->is($onAccount->subjectRecord()));

        $this->assertSame(2, Activity::query()->forSubject($contact)->count());
        $this->assertSame(3, Activity::query()->forSubject($account)->count());
        $this->assertSame(1, Activity::query()->forSubject($deal)->count());
        $this->assertSame(0, Activity::query()->forSubject($rep)->count());
    }

    #[Test]
    public function an_inactive_type_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $inactive = ActivityType::factory()->create(['kind' => ActivityKind::Call, 'is_active' => false]);

        try {
            app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $inactive, $rep, 'Call');
            $this->fail('An inactive type was accepted.');
        } catch (InvalidActivityException $exception) {
            $this->assertSame(__('activities.validation.inactive_type'), $exception->getMessage());
        }

        $this->assertSame(0, Activity::query()->count());
        $this->assertNull($lead->refresh()->last_activity_at);
    }

    #[Test]
    public function logging_moves_last_activity_at_forward_on_the_linked_lead_and_deal_but_never_backwards(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $recorder = app(ActivityRecorder::class);
        $later = Carbon::parse('2026-09-05 15:00:00');
        $earlier = Carbon::parse('2026-09-01 09:00:00');

        $subject = new ActivitySubject(lead: $lead, deal: $deal);

        $recorder->record($subject, $this->typeOfKind(ActivityKind::Call), $rep, 'Call', occurredAt: $later);

        $this->assertEquals($later, $lead->refresh()->last_activity_at);
        $this->assertEquals($later, $deal->refresh()->last_activity_at);

        $recorder->record($subject, $this->typeOfKind(ActivityKind::Email), $rep, 'Backdated email', occurredAt: $earlier);

        $this->assertEquals($later, $lead->refresh()->last_activity_at);
        $this->assertEquals($later, $deal->refresh()->last_activity_at);

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::LeadUpdated->value)->count());
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::DealUpdated->value)->count());
    }

    #[Test]
    public function logging_writes_an_activity_created_event_with_the_labels_of_the_linked_records(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Acme']);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $activity = app(ActivityRecorder::class)->record(
            new ActivitySubject(lead: $lead, account: $account),
            $this->typeOfKind(ActivityKind::Call),
            $rep,
            'Called Acme',
        );

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::ActivityCreated->value)->latest('id')->firstOrFail();

        $this->assertSame($activity->getKey(), (int) $audit->subject_id);
        $this->assertSame(Activity::class, $audit->subject_type);
        $this->assertSame($rep->getKey(), (int) $audit->causer_id);
        $this->assertSame('activity', $audit->log_name);
        $this->assertSame('Called Acme', $audit->getExtraProperty('subject_label'));
        $this->assertSame(ActivityKind::Call->value, $audit->getExtraProperty('kind'));
        $this->assertSame($lead->full_name, $audit->getExtraProperty('lead_name'));
        $this->assertSame('Acme', $audit->getExtraProperty('account_name'));
        $this->assertNull($audit->getExtraProperty('deal_title'));
    }

    #[Test]
    public function record_system_resolves_the_system_type_of_the_kind_and_stores_the_payload(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $activity = app(ActivityRecorder::class)->recordSystem(
            ActivitySubject::for($lead),
            ActivityKind::System,
            $rep,
            'Lead converted',
            ['account_id' => 7, 'deal_id' => 9],
        );

        $activity->refresh();

        $this->assertSame($this->typeOfKind(ActivityKind::System)->getKey(), (int) $activity->activity_type_id);
        $this->assertSame(ActivityKind::System, $activity->kind);
        $this->assertEqualsCanonicalizing(['account_id' => 7, 'deal_id' => 9], $activity->payload);
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ActivityCreated->value, 'subject_id' => $activity->getKey()]);
    }

    #[Test]
    public function record_system_fails_when_the_system_type_of_the_kind_is_missing(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        ActivityType::query()->where('kind', ActivityKind::Task->value)->where('is_system', true)->delete();

        $this->expectException(InvalidActivityException::class);
        $this->expectExceptionMessage(__('activities.validation.system_type_missing'));

        app(ActivityRecorder::class)->recordSystem(ActivitySubject::for($lead), ActivityKind::Task, $rep, 'Task completed');
    }

    #[Test]
    public function an_activity_cannot_be_updated_once_written(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $this->typeOfKind(ActivityKind::Call), $rep, 'Original');

        try {
            $activity->update(['subject' => 'Rewritten']);
            $this->fail('The activity was updated.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame('Original', $activity->refresh()->subject);
        $this->assertFalse($rep->can('update', $activity));
        $this->assertFalse($this->admin()->can('update', $activity));
    }

    #[Test]
    public function deleting_removes_the_row_and_writes_an_activity_deleted_event(): void
    {
        $manager = $this->salesManager();
        $lead = Lead::factory()->create(['owner_id' => $manager->getKey()]);
        $activity = app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $this->typeOfKind(ActivityKind::Call), $manager, 'To be removed');
        $id = $activity->getKey();

        app(ActivityRecorder::class)->delete($activity, $manager);

        $this->assertDatabaseMissing('activities', ['id' => $id]);

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::ActivityDeleted->value)->latest('id')->firstOrFail();
        $this->assertSame($id, (int) $audit->subject_id);
        $this->assertSame($manager->getKey(), (int) $audit->causer_id);
        $this->assertSame('To be removed', $audit->getExtraProperty('subject_label'));
        $this->assertSame($lead->full_name, $audit->getExtraProperty('lead_name'));
    }

    #[Test]
    public function record_system_still_writes_when_the_system_type_was_deactivated(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $type = $this->typeOfKind(ActivityKind::Task);
        $type->update(['is_active' => false]);

        $activity = app(ActivityRecorder::class)->recordSystem(ActivitySubject::for($lead), ActivityKind::Task, $rep, 'Task completed');

        $this->assertSame($type->getKey(), (int) $activity->activity_type_id);
        $this->assertSame(ActivityKind::Task, $activity->kind);
    }

    /**
     * @return array{?int, ?int, ?int, ?int}
     */
    private function links(Activity $activity): array
    {
        $activity->refresh();

        return [
            $activity->lead_id === null ? null : (int) $activity->lead_id,
            $activity->contact_id === null ? null : (int) $activity->contact_id,
            $activity->account_id === null ? null : (int) $activity->account_id,
            $activity->deal_id === null ? null : (int) $activity->deal_id,
        ];
    }
}
