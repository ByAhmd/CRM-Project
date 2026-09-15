<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Enums\UserStatus;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Support\ImportExportActions;
use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Filament\Actions\Imports\Models\FailedImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: accounts that may no longer sign in (D-11) are refused on
 * every download route while their session is still alive.
 *
 * The attachment route runs the panel's Authenticate middleware, which
 * checks User::canAccessPanel(). Filament's export download and failed-rows
 * download routes run under the plain `web` group and only check that
 * someone is logged in, so a disabled (or reset-to-pending) user keeps
 * pulling export files and failed-import CSVs with the session they had.
 */
final class InactiveUserDownloadsProbeTest extends TestCase
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
    public function a_disabled_user_cannot_download_their_export_file(): void
    {
        $rep = $this->salesRep();
        $export = $this->makeExport($rep);

        $url = URL::signedRoute('filament.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false);

        $this->actingAs($rep)->get($url)->assertOk();

        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        $response = $this->actingAs($rep->fresh() ?? $rep)->get($url);

        $this->assertFalse($response->isSuccessful(), 'a disabled account still streams its export file (status '.$response->getStatusCode().')');
    }

    #[Test]
    public function a_disabled_reviewer_cannot_download_failed_import_rows(): void
    {
        $manager = $this->salesManager();
        $import = $this->makeImport($manager);

        FailedImportRow::query()->create([
            'import_id' => $import->getKey(),
            'data' => ['first_name' => 'Secret', 'email' => 'secret@example.com'],
            'validation_error' => 'invalid',
        ]);

        $url = URL::signedRoute('filament.imports.failed-rows.download', ['authGuard' => 'web', 'import' => $import], absolute: false);

        $manager->forceFill(['status' => UserStatus::Disabled])->save();

        $response = $this->actingAs($manager->fresh() ?? $manager)->get($url);

        $this->assertFalse($response->isSuccessful(), 'a disabled account still downloads failed import rows (status '.$response->getStatusCode().')');
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

        $disk = Storage::disk('local');
        $disk->put($export->getFileDirectory().'/headers.csv', "id,name\n");
        $disk->put($export->getFileDirectory().'/0000000000000001.csv', "1,Fahad\n");

        return $export->refresh();
    }

    private function makeImport(User $user): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->forceFill([
            'file_name' => 'leads.csv',
            'file_path' => 'leads.csv',
            'importer' => LeadImporter::class,
            'total_rows' => 1,
            'processed_rows' => 1,
            'successful_rows' => 0,
            'completed_at' => now(),
        ])->save();

        return $import->refresh();
    }
}
