<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Services\Leads\LeadStatusWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The lead status workflow (decision D-7): every change is logged, a
 * qualification needs a note, Converted is reserved, converted leads freeze.
 */
final class LeadStatusWorkflowTest extends TestCase
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
    public function a_transition_writes_a_status_log_row_and_an_audit_event(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $contacted = $this->statusOfKind(LeadStatusKind::Working);

        app(LeadStatusWorkflow::class)->transition($lead, $contacted, $rep, 'Called twice');

        $this->assertSame($contacted->getKey(), $lead->refresh()->lead_status_id);

        $log = LeadStatusLog::query()->where('lead_id', $lead->getKey())->firstOrFail();
        $this->assertSame($this->statusOfKind(LeadStatusKind::New)->getKey(), (int) $log->from_status_id);
        $this->assertSame($contacted->getKey(), (int) $log->to_status_id);
        $this->assertSame($rep->getKey(), (int) $log->changed_by);
        $this->assertSame('Called twice', $log->notes);

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::LeadStatusChanged->value)->latest('id')->firstOrFail();
        $this->assertSame($lead->getKey(), (int) $audit->subject_id);
        $this->assertSame($contacted->display_name, $audit->properties->get('to_status'));
    }

    #[Test]
    public function qualifying_requires_a_note_and_stamps_the_qualifier(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $qualified = $this->statusOfKind(LeadStatusKind::Qualified);
        $workflow = app(LeadStatusWorkflow::class);

        try {
            $workflow->transition($lead, $qualified, $rep, '   ');
            $this->fail('A qualification without a note was accepted.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.qualification_note_required'), $exception->getMessage());
        }

        $this->assertNull($lead->refresh()->qualified_at);

        $workflow->transition($lead, $qualified, $rep, 'Budget confirmed, decision maker met.');

        $lead->refresh();
        $this->assertNotNull($lead->qualified_at);
        $this->assertSame($rep->getKey(), $lead->qualified_by);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LeadQualified->value, 'subject_id' => $lead->getKey()]);
    }

    #[Test]
    public function the_converted_status_and_inactive_statuses_are_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $workflow = app(LeadStatusWorkflow::class);

        $this->expectException(InvalidLeadTransitionException::class);
        $workflow->transition($lead, $this->statusOfKind(LeadStatusKind::Converted), $rep);
    }

    #[Test]
    public function an_inactive_status_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $paused = LeadStatus::factory()->create(['kind' => LeadStatusKind::Working, 'is_active' => false]);

        $this->expectException(InvalidLeadTransitionException::class);
        app(LeadStatusWorkflow::class)->transition($lead, $paused, $rep);
    }

    #[Test]
    public function a_converted_lead_is_frozen(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Lead::withoutWorkflowGuard(fn () => $lead->forceFill([
            'lead_status_id' => $this->statusOfKind(LeadStatusKind::Converted)->getKey(),
            'converted_at' => now(),
            'converted_by' => $rep->getKey(),
        ])->save());

        $this->assertFalse($rep->can('update', $lead));
        $this->assertFalse($rep->can('changeStatus', $lead));
        $this->assertFalse($rep->can('convert', $lead));

        $this->expectException(InvalidLeadTransitionException::class);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Working), $rep);
    }

    #[Test]
    public function an_unqualified_lead_can_be_reopened(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $workflow = app(LeadStatusWorkflow::class);

        $workflow->transition($lead, $this->statusOfKind(LeadStatusKind::Unqualified), $rep);
        $workflow->transition($lead, $this->statusOfKind(LeadStatusKind::Working), $rep);

        $this->assertSame(LeadStatusKind::Working, $lead->refresh()->status->kind);
        $this->assertSame(2, LeadStatusLog::query()->where('lead_id', $lead->getKey())->count());
    }

    #[Test]
    public function the_status_columns_cannot_be_written_outside_the_workflow(): void
    {
        $lead = Lead::factory()->create();

        $this->expectException(LogicException::class);
        $lead->forceFill(['lead_status_id' => $this->statusOfKind(LeadStatusKind::Qualified)->getKey()])->save();
    }

    #[Test]
    public function status_log_rows_are_append_only(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Working), $rep);
        $log = LeadStatusLog::query()->where('lead_id', $lead->getKey())->firstOrFail();

        try {
            $log->forceFill(['notes' => 'rewritten'])->save();
            $this->fail('A status log row was updated.');
        } catch (LogicException) {
            $this->assertNull($log->refresh()->notes);
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    #[Test]
    public function the_change_status_action_on_the_view_page_needs_a_note_for_qualification(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $qualified = $this->statusOfKind(LeadStatusKind::Qualified);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['lead_status_id' => $qualified->getKey(), 'note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['lead_status_id' => $qualified->getKey(), 'note' => 'Met the CFO.'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('leads.notifications.status_changed', ['status' => $qualified->display_name]));

        $this->assertSame(LeadStatusKind::Qualified, $lead->refresh()->status->kind);
    }

    #[Test]
    public function the_bulk_action_moves_only_the_leads_the_actor_may_change(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $admin = $this->admin();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey()]);
        $contacted = $this->statusOfKind(LeadStatusKind::Working);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->callTableBulkAction('changeStatus', [$mine, $theirs], data: ['lead_status_id' => $contacted->getKey()])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($contacted->getKey(), $mine->refresh()->lead_status_id);
        $this->assertSame($contacted->getKey(), $theirs->refresh()->lead_status_id);

        $readOnly = $this->readOnly();
        $this->assertFalse($readOnly->can('changeStatus', $mine));
    }

    #[Test]
    public function allowed_targets_exclude_converted_inactive_and_the_current_status(): void
    {
        $lead = Lead::factory()->create();
        LeadStatus::factory()->create(['kind' => LeadStatusKind::Working, 'is_active' => false]);

        $kinds = app(LeadStatusWorkflow::class)->allowedTargets($lead)->get()->map(fn (LeadStatus $status): LeadStatusKind => $status->kind)->all();

        $this->assertNotContains(LeadStatusKind::Converted, $kinds);
        $this->assertNotContains(LeadStatusKind::New, $kinds);
        $this->assertSame([LeadStatusKind::Working, LeadStatusKind::Qualified, LeadStatusKind::Unqualified], $kinds);
    }

    private function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
