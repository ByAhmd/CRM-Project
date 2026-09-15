<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadPriority;
use App\Enums\LeadStatusKind;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadStatus;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class LeadResourceTest extends TestCase
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
    public function a_rep_creates_a_lead_in_the_default_status_owned_by_themselves_and_it_is_audited(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(CreateLead::class)
            ->fillForm([
                'first_name' => 'فهد',
                'last_name' => 'القحطاني',
                'company_name' => 'شركة الأفق',
                'email' => 'Fahad@Example.com',
                'phone' => '0501112233',
                'priority' => LeadPriority::High->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('last_name', 'القحطاني')->firstOrFail();

        $this->assertSame('فهد القحطاني', $lead->full_name);
        $this->assertSame('fahad@example.com', $lead->email_normalized);
        $this->assertSame('+966501112233', $lead->phone_normalized);
        $this->assertSame($rep->getKey(), $lead->owner_id);
        $this->assertSame($rep->getKey(), $lead->created_by);
        $this->assertSame(LeadPriority::High, $lead->priority);
        $this->assertTrue($lead->status->isDefault());
        $this->assertSame(LeadStatusKind::New, $lead->status->kind);
        $this->assertNotNull($lead->scored_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LeadCreated->value, 'subject_id' => $lead->getKey()]);
    }

    #[Test]
    public function names_are_required_and_contact_fields_are_validated(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(CreateLead::class)
            ->fillForm(['first_name' => '', 'last_name' => '', 'email' => 'nope', 'website' => 'not a url'])
            ->call('create')
            ->assertHasFormErrors(['first_name' => 'required', 'last_name' => 'required', 'email' => 'email', 'website' => 'url']);
    }

    #[Test]
    public function the_converted_status_is_never_offered_on_create(): void
    {
        $converted = LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->firstOrFail();

        Livewire::actingAs($this->salesRep())
            ->test(CreateLead::class)
            ->fillForm(['first_name' => 'Test', 'last_name' => 'Lead', 'lead_status_id' => $converted->getKey()])
            ->call('create')
            ->assertHasFormErrors(['lead_status_id']);
    }

    #[Test]
    public function the_edit_form_cannot_change_the_status_directly(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();

        Livewire::actingAs($rep)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldHidden('lead_status_id')
            ->fillForm(['job_title' => 'CTO', 'lead_status_id' => $qualified->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();

        $this->assertSame('CTO', $lead->job_title);
        $this->assertSame(LeadStatusKind::New, $lead->status->kind);
    }

    #[Test]
    public function visibility_follows_ownership_and_teams(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $other = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($rep)->get(LeadResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($rep)->get(LeadResource::getUrl('edit', ['record' => $mine]))->assertOk();
        $this->actingAs($rep)->get(LeadResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($manager)->get(LeadResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($this->readOnly())->get(LeadResource::getUrl('view', ['record' => $theirs]))->assertOk();
        $this->actingAs($this->readOnly())->get(LeadResource::getUrl('create'))->assertForbidden();
    }

    #[Test]
    public function the_bulk_delete_soft_deletes_only_in_scope_leads_and_is_not_offered_to_a_rep(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $inTeam = Lead::factory()->create(['owner_id' => $member->getKey()]);
        $outside = Lead::factory()->create(['owner_id' => $outsider->getKey()]);

        // Livewire::actingAs switches the user for every component, so each actor's component runs to completion first.
        Livewire::actingAs($member)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertTableBulkActionHidden('delete');

        $this->assertNotSoftDeleted('leads', ['id' => $inTeam->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->callTableBulkAction('delete', [$inTeam, $outside])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('leads', ['id' => $inTeam->getKey()]);
        $this->assertNotSoftDeleted('leads', ['id' => $outside->getKey()]);
    }

    #[Test]
    public function the_list_tabs_split_leads_by_status_kind(): void
    {
        $admin = $this->admin();
        $unqualifiedStatus = LeadStatus::query()->where('kind', LeadStatusKind::Unqualified->value)->firstOrFail();
        $open = Lead::factory()->create(['owner_id' => $admin->getKey()]);
        $unqualified = Lead::factory()->create(['owner_id' => $admin->getKey(), 'lead_status_id' => $unqualifiedStatus->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$unqualified])
            ->set('activeTab', 'unqualified')
            ->assertCanSeeTableRecords([$unqualified])
            ->assertCanNotSeeTableRecords([$open]);
    }

    #[Test]
    public function the_view_page_renders_the_status_history(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertOk()
            ->assertSee($lead->full_name)
            ->assertSee(__('leads.sections.status_history'));
    }

    #[Test]
    public function the_duplicate_warning_names_existing_leads_and_contacts(): void
    {
        $rep = $this->salesRep();
        Lead::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Nora', 'last_name' => 'Existing', 'email' => 'nora@example.com']);
        Contact::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Nora', 'last_name' => 'Customer', 'mobile' => '0509998877']);

        Livewire::actingAs($rep)
            ->test(CreateLead::class)
            ->fillForm(['first_name' => 'N', 'last_name' => 'Dup', 'email' => 'NORA@example.com', 'phone' => '+966509998877'])
            ->assertSee(__('merge.warnings.possible_duplicates'))
            ->assertSee('Nora Existing')
            ->assertSee('Nora Customer');
    }

    #[Test]
    public function only_assign_holders_see_the_manual_score_override(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $manager = $this->salesManager($team);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldHidden('score_override');

        Livewire::actingAs($manager)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldVisible('score_override')
            ->fillForm(['score_override' => 88])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(88, $lead->refresh()->effective_score);
    }

    #[Test]
    public function global_search_stays_in_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Zeta Trading']);
        Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Zeta Trading']);

        $this->actingAs($rep);

        $this->assertContains('company_name', LeadResource::getGloballySearchableAttributes());
        $this->assertSame([$mine->getKey()], LeadResource::getGlobalSearchEloquentQuery()->pluck('id')->all());
    }

    #[Test]
    public function a_soft_deleted_lead_can_be_restored_by_an_admin(): void
    {
        $admin = $this->admin();
        $lead = Lead::factory()->create(['owner_id' => $admin->getKey()]);

        Livewire::actingAs($admin)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('leads', ['id' => $lead->getKey()]);

        // A soft-deleted lead is frozen (D-13): it is restored from its view page, not edited.
        Livewire::actingAs($admin)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionVisible('restore')
            ->callAction('restore');

        $this->assertNull($lead->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LeadRestored->value, 'subject_id' => $lead->getKey()]);
    }

    #[Test]
    public function the_owner_filter_only_lists_users_within_reach(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep();

        $this->actingAs($manager);

        $page = Livewire::actingAs($manager)->test(ListLeads::class)->instance();
        assert($page instanceof ListLeads);
        $filter = $page->getTable()->getFilter('owner_id');
        assert($filter instanceof SelectFilter);
        $options = $filter->getOptions();

        $this->assertArrayHasKey($teammate->getKey(), $options);
        $this->assertArrayHasKey($manager->getKey(), $options);
        $this->assertArrayNotHasKey($outsider->getKey(), $options);
    }
}
