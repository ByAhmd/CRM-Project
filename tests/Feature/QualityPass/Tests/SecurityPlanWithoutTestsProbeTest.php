<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Support\ImportExportActions;
use App\Models\Lead;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Test-suite audit probe: plan section 7 (security) and D-11 measures that no
 * test under tests/Feature exercises. ProductionWiringTest only asserts the
 * password-reset throttle, the session lifetime and that a profile route exists.
 */
final class SecurityPlanWithoutTestsProbeTest extends TestCase
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
    public function the_login_form_locks_out_after_five_failed_attempts(): void
    {
        $user = $this->salesRep();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $user->email, 'password' => 'Wrong-Password-'.$attempt])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertGuest();
    }

    #[Test]
    public function multi_factor_authentication_is_offered_to_everyone_and_required_of_no_one(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasMultiFactorAuthentication(), 'D-11: MFA providers are wired');
        $this->assertCount(2, $panel->getMultiFactorAuthenticationProviders());
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired(), 'D-11: MFA is optional');
    }

    #[Test]
    public function the_temporary_upload_prune_removes_only_files_older_than_a_day(): void
    {
        $disk = Storage::fake((string) config('crm.attachments.disk'));
        $disk->put('tmp/7/stale.pdf', 'stale');
        $disk->put('tmp/7/fresh.pdf', 'fresh');
        $disk->put('leads/1/kept.pdf', 'kept');
        touch($disk->path('tmp/7/stale.pdf'), now()->subDays(2)->getTimestamp());
        touch($disk->path('leads/1/kept.pdf'), now()->subDays(30)->getTimestamp());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($candidate): bool => $candidate instanceof CallbackEvent && $candidate->description === 'attachments:prune-temporary');
        $this->assertInstanceOf(CallbackEvent::class, $event);

        $event->run(app());

        $disk->assertMissing('tmp/7/stale.pdf');
        $disk->assertExists('tmp/7/fresh.pdf');
        $disk->assertExists('leads/1/kept.pdf');
    }

    #[Test]
    public function the_import_and_export_actions_are_rate_limited(): void
    {
        // Plan section 7: "rateLimit() on heavy actions (import, export)".
        $this->actingAs($this->salesManager());

        $actions = [
            'import' => ImportExportActions::import(LeadImporter::class, Lead::class),
            'export' => ImportExportActions::export(LeadExporter::class, Lead::class),
            'export bulk' => ImportExportActions::exportBulk(LeadExporter::class, Lead::class),
        ];

        foreach ($actions as $name => $action) {
            $this->assertNotNull($action->getRateLimit(), "the {$name} action carries no rateLimit()");
        }
    }
}
