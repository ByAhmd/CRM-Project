<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\LeadStatusKind;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Probe for DATABASE_DESIGN.md section 6 (Qualification, D-7): a lead reaches
 * a status of kind `qualified` only with a non-empty note on a
 * lead_status_logs row, and conversion requires that qualification. The
 * create form offers every non-converted status as the initial status, so a
 * lead can be born Qualified — no note, no log row, no qualified_at — and be
 * converted straight away.
 */
final class QualificationOnCreateProbeTest extends TestCase
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
    public function a_lead_cannot_be_created_directly_in_a_qualified_status_and_converted_without_a_qualification_note(): void
    {
        $admin = $this->admin();
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateLead::class)
            ->fillForm([
                'first_name' => 'Born',
                'last_name' => 'Qualified',
                'company_name' => 'Shortcut Co',
                'lead_status_id' => $qualified->getKey(),
            ])
            ->call('create');

        $lead = Lead::query()->where('last_name', 'Qualified')->first();

        if ($lead === null) {
            $this->addToAssertionCount(1); // refused at the form: the rule holds

            return;
        }

        $noteLogged = $lead->statusLogs()->where('to_status_id', $qualified->getKey())->whereNotNull('notes')->where('notes', '!=', '')->exists();

        if ($lead->status?->kind === LeadStatusKind::Qualified) {
            $this->assertTrue($noteLogged, 'A lead was created in a Qualified status without the qualification note D-7 requires.');
        }

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest, $admin);
            $converted = true;
        } catch (Throwable) {
            $converted = false;
        }

        $this->assertFalse($converted && ! $noteLogged, 'A lead that was never qualified through the workflow was converted.');
    }
}
