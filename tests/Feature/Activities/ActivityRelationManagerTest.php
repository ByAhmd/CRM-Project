<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountActivitiesRelationManager;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactActivitiesRelationManager;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealActivitiesRelationManager;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadActivitiesRelationManager;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The activity timeline on the four subject pages (decision A-10): logging
 * from the page links the owner record, the manager follows the subject's
 * visibility and the delete stays behind `activity.delete`.
 */
final class ActivityRelationManagerTest extends TestCase
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
    public function a_rep_logs_an_activity_from_the_lead_page_and_it_links_to_the_lead(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 11:00:00'));
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertNull($lead->last_activity_at);
        $this->actingAs($rep);
        $this->assertTrue(LeadActivitiesRelationManager::canViewForRecord($lead, ViewLead::class));
        $this->assertTrue(LeadActivitiesRelationManager::canViewForRecord($lead, EditLead::class));

        $manager = Livewire::actingAs($rep)
            ->test(LeadActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: [
                'activity_type_id' => $this->typeOfKind(ActivityKind::Call)->getKey(),
                'subject' => 'Discovery call',
                'occurred_at' => '2026-09-06 10:30:00',
                'direction' => ActivityDirection::Inbound->value,
                'duration_minutes' => 25,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('activities.notifications.logged'));

        $activity = Activity::query()->where('subject', 'Discovery call')->firstOrFail();

        $this->assertSame($lead->getKey(), (int) $activity->lead_id);
        $this->assertNull($activity->contact_id);
        $this->assertNull($activity->account_id);
        $this->assertNull($activity->deal_id);
        $this->assertSame(ActivityKind::Call, $activity->kind);
        $this->assertSame(ActivityDirection::Inbound, $activity->direction);
        $this->assertSame(25, (int) $activity->duration_minutes);
        $this->assertSame($rep->getKey(), (int) $activity->owner_id);
        $this->assertSame('2026-09-06 10:30:00', $lead->refresh()->last_activity_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ActivityCreated->value, 'subject_id' => $activity->getKey()]);

        $manager
            ->assertCanSeeTableRecords([$activity])
            ->assertSee('Discovery call');
    }

    #[Test]
    public function the_manager_validates_the_type_and_the_subject(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(LeadActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('create', data: ['activity_type_id' => null, 'subject' => ''])
            ->assertHasTableActionErrors(['activity_type_id' => 'required', 'subject' => 'required']);

        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function logging_from_a_deal_links_the_deal_its_account_and_its_primary_contact(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'contact_id' => $contact->getKey()]);

        Livewire::actingAs($rep)
            ->test(DealActivitiesRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->callTableAction('create', data: [
                'activity_type_id' => $this->typeOfKind(ActivityKind::Meeting)->getKey(),
                'subject' => 'Demo',
                'occurred_at' => '2026-09-06 14:00:00',
                'duration_minutes' => 60,
            ])
            ->assertHasNoTableActionErrors();

        $activity = Activity::query()->where('subject', 'Demo')->firstOrFail();

        $this->assertSame($deal->getKey(), (int) $activity->deal_id);
        $this->assertSame($account->getKey(), (int) $activity->account_id);
        $this->assertSame($contact->getKey(), (int) $activity->contact_id);
        $this->assertNull($activity->lead_id);
        $this->assertSame('2026-09-06 14:00:00', $deal->refresh()->last_activity_at?->format('Y-m-d H:i:s'));

        Livewire::actingAs($rep)
            ->test(AccountActivitiesRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$activity]);

        Livewire::actingAs($rep)
            ->test(ContactActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => ViewContact::class])
            ->assertCanSeeTableRecords([$activity])
            ->callTableAction('view', $activity)
            ->assertHasNoTableActionErrors();
    }

    #[Test]
    public function the_manager_is_hidden_from_a_user_who_cannot_read_the_lead_and_from_one_who_cannot_list_activities(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->actingAs($other);
        $this->assertFalse(LeadActivitiesRelationManager::canViewForRecord($lead, ViewLead::class));

        $noActivities = User::factory()->create();
        $noActivities->givePermissionTo('lead.view_any', 'lead.view_all');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($noActivities);
        $this->assertTrue($noActivities->can('view', $lead));
        $this->assertFalse($noActivities->can('viewAny', Activity::class));
        $this->assertFalse(LeadActivitiesRelationManager::canViewForRecord($lead, ViewLead::class));

        $this->actingAs($rep);
        $this->assertTrue(LeadActivitiesRelationManager::canViewForRecord($lead, ViewLead::class));
    }

    #[Test]
    public function a_read_only_user_sees_the_timeline_but_cannot_log_or_delete(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey()]);

        $this->actingAs($readOnly);
        $this->assertTrue(LeadActivitiesRelationManager::canViewForRecord($lead, ViewLead::class));

        Livewire::actingAs($readOnly)
            ->test(LeadActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$activity])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('delete', $activity)
            ->assertTableActionVisible('view', $activity);
    }

    #[Test]
    public function deleting_from_the_manager_is_gated_by_the_permission_and_audited(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(LeadActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$activity])
            ->assertTableActionHidden('delete', $activity);

        Livewire::actingAs($manager)
            ->test(LeadActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('delete', $activity)
            ->callTableAction('delete', $activity)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('activities.notifications.deleted'));

        $this->assertDatabaseMissing('activities', ['id' => $activity->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ActivityDeleted->value, 'subject_id' => $activity->getKey(), 'causer_id' => $manager->getKey()]);
    }
}
