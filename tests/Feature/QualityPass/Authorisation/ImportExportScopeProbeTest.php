<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Enums\UserStatus;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Support\ImportExportActions;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Imports\Models\FailedImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit probes: export files and import failure rows carry
 * record data, so reaching them must not widen the reader's scope (D-4, D-13),
 * and a switched-off account must not keep streaming them.
 */
final class ImportExportScopeProbeTest extends TestCase
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
    public function a_team_manager_cannot_download_an_admin_export_that_holds_leads_outside_the_team(): void
    {
        Storage::fake('local');
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $admin = $this->admin();
        $outsider = $this->salesRep();
        $foreign = Lead::factory()->create(['owner_id' => $outsider->getKey(), 'last_name' => 'OutsideTeam']);

        $this->assertFalse($manager->can('view', $foreign), 'precondition: the manager cannot read the foreign lead');

        $export = $this->makeExport($admin);
        Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', "id,last_name\n");
        Storage::disk('local')->put($export->getFileDirectory().'/0000000000000001.csv', $foreign->getKey().",OutsideTeam\n");

        $this->assertFalse(
            $manager->can('view', $export),
            'exports.view lets a team-scoped manager open every run, including an all-records export (D-13: managers export what they may view)',
        );

        // The run is outside the manager's history query, so its row and its
        // download actions are never rendered; Filament's own download route
        // answers through the same policy.
        Livewire::actingAs($manager)
            ->test(ListExports::class)
            ->assertCanNotSeeTableRecords([$export]);

        $this->actingAs($manager)
            ->get(route('filament.exports.download', ['export' => $export->getKey(), 'format' => 'csv'], false))
            ->assertForbidden();
    }

    #[Test]
    public function a_team_manager_cannot_read_the_failed_rows_of_another_users_import(): void
    {
        $manager = $this->salesManager($this->makeTeam());
        $admin = $this->admin();
        $import = new Import;
        $import->user()->associate($admin);
        $import->forceFill([
            'file_name' => 'leads.csv',
            'file_path' => 'leads.csv',
            'importer' => LeadImporter::class,
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 1,
            'completed_at' => now(),
        ])->save();
        FailedImportRow::query()->create([
            'import_id' => $import->getKey(),
            'data' => ['first_name' => 'Secret', 'last_name' => 'Prospect', 'email' => 'secret.prospect@example.com'],
            'validation_error' => 'owner is out of reach',
        ]);

        $this->assertFalse($manager->can('view', $import), 'imports.view exposes raw rows the importer uploaded, whatever their owner');
    }

    #[Test]
    public function a_disabled_user_cannot_keep_downloading_their_export_through_filaments_route(): void
    {
        Storage::fake('local');
        $rep = $this->salesRep();
        $export = $this->makeExport($rep);
        Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', "id,last_name\n");
        Storage::disk('local')->put($export->getFileDirectory().'/0000000000000001.csv', "1,Fahad\n");

        $url = route('filament.exports.download', ['export' => $export->getKey(), 'format' => 'csv'], false);

        $this->actingAs($rep)->get($url)->assertOk();

        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        $this->actingAs($rep->fresh() ?? $rep)
            ->get($url)
            ->assertForbidden();
    }

    private function makeExport(User $user): Export
    {
        $export = new Export;
        $export->user()->associate($user);
        $export->forceFill([
            'file_disk' => ImportExportActions::FILE_DISK,
            'file_name' => 'export-leads',
            'exporter' => LeadExporter::class,
            'total_rows' => 1,
            'processed_rows' => 1,
            'successful_rows' => 1,
            'completed_at' => now(),
        ])->save();

        return $export->refresh();
    }
}
