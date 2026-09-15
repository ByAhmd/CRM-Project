<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Enums\LeadStatusKind;
use App\Enums\TaskKind;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Security probes: workflow and assignment rules that only hold on update.
 *
 * GuardsWorkflowFields fires on `updating` only, and `lead_status_id` is
 * fillable, so the create form's status Select (which hides only Converted)
 * puts a lead straight into Qualified — no qualification note, no
 * qualified_at / qualified_by, no ledger event — and it is then convertible
 * (D-7). TaskService::create() takes the assignee from the submitted data
 * without the `task.assign` permission or the assignable-users reach check
 * that RecordAssignmentService applies on update (D-4): the service layer,
 * which CLAUDE.md makes the owner of every business rule, trusts the form.
 */
final class WorkflowBypassOnCreateProbeTest extends TestCase
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
    public function a_lead_cannot_be_created_directly_in_a_qualified_status_without_the_qualification_note(): void
    {
        $rep = $this->salesRep();
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();

        Livewire::actingAs($rep)
            ->test(CreateLead::class)
            ->fillForm([
                'first_name' => 'Skip',
                'last_name' => 'Qualification',
                'lead_status_id' => $qualified->getKey(),
            ])
            ->call('create');

        $lead = Lead::query()->where('last_name', 'Qualification')->first();

        $this->assertFalse(
            $lead instanceof Lead && (int) $lead->lead_status_id === (int) $qualified->getKey() && $lead->qualified_at === null,
            'a lead was created in Qualified without the D-7 note, qualified_at or qualified_by',
        );
    }

    #[Test]
    public function the_task_service_refuses_an_assignee_outside_the_actors_reach_on_create(): void
    {
        $rep = $this->salesRep();
        $stranger = $this->salesRep();

        $this->assertFalse($rep->can('task.assign'), 'precondition: a rep may not assign tasks');

        try {
            $task = app(TaskService::class)->create([
                'title' => 'Dumped on a colleague',
                'kind' => TaskKind::Task->value,
                'owner_id' => $stranger->getKey(),
            ], $rep);
        } catch (Throwable) {
            $task = null;
        }

        $this->assertFalse(
            $task instanceof Task && (int) $task->assignee_id === (int) $stranger->getKey(),
            'TaskService::create assigned the task to a user the actor may not assign to',
        );
    }
}
