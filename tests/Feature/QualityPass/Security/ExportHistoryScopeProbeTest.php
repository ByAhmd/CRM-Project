<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Filament\Exports\DealExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Support\ImportExportActions;
use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Filament\Actions\Imports\Models\FailedImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: export files and failed import rows are record data and
 * must not travel past the reader's visibility scope (D-4, D-13).
 *
 * `exports.view` / `imports.view` open every run, and the seeded
 * sales_manager role — a team-scoped role — holds both. An administrator's
 * export of every deal in the organisation, or the failed rows of an
 * organisation-wide import, is therefore downloadable by any sales manager,
 * whatever team the rows belong to.
 */
final class ExportHistoryScopeProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        Storage::fake('local');
    }

    #[Test]
    public function a_team_scoped_manager_cannot_download_an_organisation_wide_export(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $this->assertFalse($manager->can('deal.view_all'), 'precondition: the manager is team-scoped');

        $export = $this->makeExport($admin);

        $this->assertFalse($manager->can('view', $export), 'a team-scoped manager may open and download an admin\'s organisation-wide export');

        Livewire::actingAs($manager)
            ->test(ListExports::class)
            ->assertCanNotSeeTableRecords([$export]);
    }

    #[Test]
    public function a_team_scoped_manager_cannot_download_the_failed_rows_of_another_users_import(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $import = new Import;
        $import->user()->associate($admin);
        $import->forceFill([
            'file_name' => 'leads.csv',
            'file_path' => 'leads.csv',
            'importer' => LeadImporter::class,
            'total_rows' => 1,
            'processed_rows' => 1,
            'successful_rows' => 0,
            'completed_at' => now(),
        ])->save();

        FailedImportRow::query()->create([
            'import_id' => $import->getKey(),
            'data' => ['first_name' => 'Other', 'last_name' => 'Team', 'email' => 'other.team@example.com'],
            'validation_error' => 'invalid',
        ]);

        $this->assertFalse($manager->can('view', $import), 'a team-scoped manager may open the failed rows of an organisation-wide import');

        // The run is outside the manager's history query: the view page is a
        // 404, so the failed rows are never rendered, and Filament's own
        // failed-rows download route answers through the same policy.
        $this->actingAs($manager)
            ->get(ImportResource::getUrl('view', ['record' => $import]))
            ->assertNotFound()
            ->assertDontSee('other.team@example.com');

        $this->actingAs($manager)
            ->get(route('filament.imports.failed-rows.download', ['import' => $import->getKey()], false))
            ->assertForbidden();
    }

    private function makeExport(User $user): Export
    {
        $export = new Export;
        $export->user()->associate($user);
        $export->forceFill([
            'file_disk' => ImportExportActions::FILE_DISK,
            'file_name' => 'export-deals',
            'exporter' => DealExporter::class,
            'total_rows' => 1,
            'processed_rows' => 1,
            'successful_rows' => 1,
            'completed_at' => now(),
        ])->save();

        $disk = Storage::disk('local');
        $disk->put($export->getFileDirectory().'/headers.csv', "id,title\n");
        $disk->put($export->getFileDirectory().'/0000000000000001.csv', "1,Other team deal\n");

        return $export->refresh();
    }
}
