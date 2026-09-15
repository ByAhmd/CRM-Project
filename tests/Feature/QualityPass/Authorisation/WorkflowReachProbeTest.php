<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Enums\UserStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Support\QueryBuilderFilters;
use App\Models\Account;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\Note;
use App\Notifications\DealClosedNotification;
use App\Notifications\RecordAssignedNotification;
use App\Services\Access\RecordAssignmentService;
use App\Services\Deals\DealCloseService;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit probes: notifications, reassignment, trashed subjects
 * and pickers that reach past the actor's scope (D-4, D-13, plan 3.6).
 */
final class WorkflowReachProbeTest extends TestCase
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
    public function a_deal_closed_notification_is_not_sent_to_a_team_manager_who_cannot_open_the_deal(): void
    {
        Notification::fake();

        // The team names a manager whose own users.team_id was never set to
        // the team: the resolver gives them no reach over the team's deals.
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $rep = $this->salesRep($team);
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Ministry tender 2027']);

        $this->assertFalse($manager->can('view', $deal), 'precondition: the named manager cannot read the deal');

        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->firstOrFail();
        app(DealCloseService::class)->win($deal, $reason, $admin, 'Signed');

        Notification::assertNotSentTo($manager, DealClosedNotification::class);
        $this->actingAs($manager)->get(DealResource::getUrl('view', ['record' => $deal]))->assertNotFound();
    }

    #[Test]
    public function an_owner_change_made_through_the_edit_form_is_audited_and_notified_as_an_assignment(): void
    {
        Notification::fake();
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $first = $this->salesRep($team);
        $second = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $first->getKey()]);

        Livewire::actingAs($manager)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['owner_id' => $second->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($second->getKey(), $lead->fresh()?->owner_id, 'precondition: the form reassigned the lead');
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LeadAssigned->value,
            'subject_id' => $lead->getKey(),
        ]);
        Notification::assertSentTo($second, RecordAssignedNotification::class);
    }

    #[Test]
    public function a_trashed_lead_cannot_be_edited_through_its_edit_page(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'job_title' => 'Before']);
        $lead->delete();

        $status = $this->actingAs($rep)->get(LeadResource::getUrl('edit', ['record' => $lead]))->status();

        $this->assertContains($status, [403, 404], 'a soft-deleted record is frozen until restored (D-13), as attachments already are');
    }

    #[Test]
    public function a_note_cannot_be_added_to_a_trashed_subject(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $lead->delete();

        $this->assertFalse($rep->can('create', [Note::class, $lead->fresh()]), 'AttachmentPolicy refuses a trashed subject; NotePolicy does not');
    }

    #[Test]
    public function the_query_builder_account_picker_offers_only_accounts_the_actor_may_read(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Own Trading']);
        Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Foreign Holdings']);

        $page = Livewire::actingAs($rep)->test(ListContacts::class)->instance();
        $this->assertInstanceOf(ListContacts::class, $page);
        $table = $page->getTable();
        $filter = $table->getFilter(QueryBuilderFilters::NAME);
        $this->assertInstanceOf(QueryBuilder::class, $filter);

        $constraint = $filter->getConstraint('account');
        $this->assertNotNull($constraint);
        $operator = $constraint->getOperator('isRelatedTo');
        $this->assertInstanceOf(IsRelatedToOperator::class, $operator);
        $operator->constraint($constraint);

        $names = $operator->getRelationshipQuery()?->pluck('name')->all() ?? [];

        $this->assertContains('Own Trading', $names);
        $this->assertNotContains('Foreign Holdings', $names, 'the contact and deal query builders list every account name in the organisation');
    }

    #[Test]
    public function a_sales_rep_cannot_set_the_account_lifecycle_type_by_hand(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'type' => AccountType::Prospect]);

        Livewire::actingAs($rep)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['type' => AccountType::Customer->value])
            ->call('save');

        $this->assertSame(AccountType::Prospect, $account->fresh()?->type, 'D-6: a prospect becomes a customer on its first won deal; only admins set the type manually');
    }

    #[Test]
    public function a_record_cannot_be_assigned_to_a_disabled_user(): void
    {
        $admin = $this->admin();
        $disabled = $this->salesRep();
        $disabled->forceFill(['status' => UserStatus::Disabled])->save();
        $lead = Lead::factory()->create(['owner_id' => $admin->getKey()]);

        $this->expectException(UnassignableUserException::class);

        app(RecordAssignmentService::class)->assign($lead, $disabled->fresh(), $admin);
    }
}
