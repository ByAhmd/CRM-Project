<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\Permission;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Support\ImportExportActions;
use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The import and export history screens, their visibility rules, the
 * downloads and the retention pruning (module 18, decision D-13).
 */
final class ImportExportHistoryTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function a_user_sees_only_their_own_imports_and_exports_while_a_reviewer_sees_every_run(): void
    {
        $manager = $this->salesManager();
        $rep = $this->importingRep();
        $other = $this->importingRep();
        $mine = $this->makeImport($rep);
        $theirs = $this->makeImport($other);
        $myExport = $this->makeExport($rep);
        $theirExport = $this->makeExport($other);

        $this->assertTrue($manager->can(Permission::ImportsView->value));
        $this->assertFalse($rep->can(Permission::ImportsView->value));

        Livewire::actingAs($rep)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$mine, $theirs]);

        Livewire::actingAs($rep)
            ->test(ListExports::class)
            ->assertCanSeeTableRecords([$myExport])
            ->assertCanNotSeeTableRecords([$theirExport]);

        Livewire::actingAs($manager)
            ->test(ListExports::class)
            ->assertCanSeeTableRecords([$myExport, $theirExport]);

        $this->actingAs($other)->get(ImportResource::getUrl('view', ['record' => $mine]))->assertNotFound();
        $this->actingAs($manager)->get(ImportResource::getUrl('view', ['record' => $mine]))->assertOk();
    }

    #[Test]
    public function the_view_page_shows_the_failed_rows_and_offers_their_download_to_the_owner_and_to_a_reviewer(): void
    {
        $rep = $this->importingRep();
        $manager = $this->salesManager();
        $import = $this->makeImport($rep, ['total_rows' => 3, 'processed_rows' => 3, 'successful_rows' => 1]);
        $clean = $this->makeImport($rep, ['total_rows' => 1, 'processed_rows' => 1, 'successful_rows' => 1]);

        FailedImportRow::query()->create([
            'import_id' => $import->getKey(),
            'data' => ['first_name' => '', 'last_name' => 'Missing'],
            'validation_error' => 'first name is required',
        ]);
        FailedImportRow::query()->create([
            'import_id' => $import->getKey(),
            'data' => ['first_name' => 'Bad', 'last_name' => 'Email'],
            'validation_error' => 'email is invalid',
        ]);

        Livewire::actingAs($rep)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.sections.failed_rows'))
            ->assertSee('first name is required')
            ->assertSee('email is invalid')
            ->assertSee('last_name: Missing')
            ->assertActionVisible('downloadFailedRows')
            ->callAction('downloadFailedRows')
            ->assertFileDownloaded();

        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertActionVisible('downloadFailedRows')
            ->callAction('downloadFailedRows')
            ->assertFileDownloaded();

        Livewire::actingAs($rep)
            ->test(ViewImport::class, ['record' => $clean->getRouteKey()])
            ->assertDontSee(__('imports.sections.failed_rows'))
            ->assertActionHidden('downloadFailedRows');
    }

    #[Test]
    public function the_history_screens_are_reachable_only_with_an_import_or_export_permission(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $readOnly = $this->readOnly();

        $this->actingAs($rep);
        $this->assertFalse(ImportResource::canAccess(), 'a rep holds no import permission');
        $this->assertTrue(ExportResource::canAccess(), 'a rep may export within their scope (D-13)');

        $this->actingAs($manager);
        $this->assertTrue(ImportResource::canAccess());
        $this->assertTrue(ExportResource::canAccess());

        $this->actingAs($readOnly);
        $this->assertFalse(ImportResource::canAccess());
        $this->assertFalse(ExportResource::canAccess());

        $this->actingAs($rep)->get(ImportResource::getUrl('index'))->assertForbidden();
        $this->actingAs($readOnly)->get(ExportResource::getUrl('index'))->assertForbidden();
        $this->actingAs($rep)->get(ExportResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(ImportResource::getUrl('index'))->assertOk();
    }

    #[Test]
    public function export_files_are_downloaded_per_format_by_the_owner_or_a_reviewer_while_they_exist(): void
    {
        Storage::fake('local');
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $manager = $this->salesManager();
        $export = $this->makeExport($rep);
        $fileless = $this->makeExport($rep, ['file_name' => 'export-gone-leads']);
        $this->writeFiles($export);

        Livewire::actingAs($rep)
            ->test(ListExports::class)
            ->assertCanSeeTableRecords([$export, $fileless])
            ->assertActionVisible(TestAction::make('download_csv')->table($export))
            ->assertActionVisible(TestAction::make('download_xlsx')->table($export))
            ->assertActionHidden(TestAction::make('download_csv')->table($fileless))
            ->assertActionHidden(TestAction::make('download_xlsx')->table($fileless))
            ->callAction(TestAction::make('download_csv')->table($export))
            ->assertFileDownloaded($export->file_name.'.csv');

        Livewire::actingAs($manager)
            ->test(ListExports::class)
            ->assertCanSeeTableRecords([$export])
            ->assertActionVisible(TestAction::make('download_csv')->table($export))
            ->callAction(TestAction::make('download_xlsx')->table($export))
            ->assertFileDownloaded($export->file_name.'.xlsx');

        Livewire::actingAs($other)
            ->test(ListExports::class)
            ->assertCanNotSeeTableRecords([$export]);
    }

    #[Test]
    public function old_runs_are_pruned_with_their_files_and_failed_rows_and_the_prune_is_scheduled_daily(): void
    {
        Storage::fake('local');
        $rep = $this->importingRep();
        $oldExport = $this->makeExport($rep);
        $recentExport = $this->makeExport($rep, ['file_name' => 'export-recent-leads']);
        $this->writeFiles($oldExport);
        $this->writeFiles($recentExport);
        Export::query()->whereKey($oldExport->getKey())->update(['created_at' => now()->subDays(Export::RETENTION_DAYS + 1)]);

        $oldImport = $this->makeImport($rep);
        $recentImport = $this->makeImport($rep);
        FailedImportRow::query()->create(['import_id' => $oldImport->getKey(), 'data' => ['first_name' => ''], 'validation_error' => 'required']);
        Import::query()->whereKey($oldImport->getKey())->update(['created_at' => now()->subDays(Import::RETENTION_DAYS + 1)]);

        Artisan::call('model:prune', ['--model' => [Import::class, Export::class]]);

        $this->assertDatabaseMissing('exports', ['id' => $oldExport->getKey()]);
        $this->assertDatabaseHas('exports', ['id' => $recentExport->getKey()]);
        $this->assertFalse(Storage::disk('local')->directoryExists($oldExport->getFileDirectory()), 'the pruned run takes its files with it');
        $this->assertTrue(Storage::disk('local')->directoryExists($recentExport->getFileDirectory()));

        $this->assertDatabaseMissing('imports', ['id' => $oldImport->getKey()]);
        $this->assertDatabaseHas('imports', ['id' => $recentImport->getKey()]);
        $this->assertSame(0, FailedImportRow::query()->where('import_id', $oldImport->getKey())->count());

        $scheduled = implode("\n", array_map(
            fn (Event $event): string => $event->command ?? $event->description ?? '',
            app(Schedule::class)->events(),
        ));

        $this->assertStringContainsString('model:prune', $scheduled);
        $this->assertStringContainsString(Export::class, $scheduled);
        $this->assertStringContainsString(Import::class, $scheduled);
        $this->assertStringNotContainsString(
            FailedImportRow::class,
            $scheduled,
            'failed rows live as long as their import: Filament\'s own one-month window would empty a run\'s failure list early',
        );
    }

    /** A sales rep who may import leads but holds no `imports.view`: own runs only. */
    private function importingRep(): User
    {
        $rep = $this->salesRep();
        $rep->givePermissionTo(Permission::LeadImport->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $rep->fresh() ?? $rep;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeImport(User $user, array $attributes = []): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->forceFill([
            'file_name' => 'leads.csv',
            'file_path' => 'leads.csv',
            'importer' => LeadImporter::class,
            'total_rows' => 3,
            'processed_rows' => 3,
            'successful_rows' => 2,
            'completed_at' => now(),
            ...$attributes,
        ])->save();

        return $import->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeExport(User $user, array $attributes = []): Export
    {
        $export = new Export;
        $export->user()->associate($user);
        $export->forceFill([
            'file_disk' => ImportExportActions::FILE_DISK,
            'file_name' => 'export-leads',
            'exporter' => LeadExporter::class,
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 2,
            'completed_at' => now(),
            ...$attributes,
        ])->save();

        return $export->refresh();
    }

    private function writeFiles(Export $export): void
    {
        $disk = Storage::disk('local');
        $directory = $export->getFileDirectory();

        $disk->put($directory.'/headers.csv', "id,name\n");
        $disk->put($directory.'/0000000000000001.csv', "1,Fahad\n2,Noura\n");
    }
}
