<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\Permission;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SpreadsheetImportAction;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsUploadBytes;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The import and export actions on the list pages: who sees them, and an
 * import driven end to end through the panel (module 18, decision D-13).
 */
final class ImportExportActionsTest extends TestCase
{
    use BuildsUploadBytes;
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
    public function the_import_action_is_offered_to_a_manager_and_hidden_from_a_rep_on_every_importable_list(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class] as $page) {
            Livewire::actingAs($manager)->test($page)->assertActionVisible('import');
            Livewire::actingAs($rep)->test($page)->assertActionHidden('import');
        }
    }

    #[Test]
    public function the_import_and_export_actions_are_rate_limited_per_user(): void
    {
        // Plan section 7: heavy actions carry rateLimit(); each start queues work on the shared host (D-1).
        $this->actingAs($this->salesManager());

        $this->assertSame(ImportExportActions::IMPORT_RATE_LIMIT, ImportExportActions::import(LeadImporter::class, Lead::class)->getRateLimit());
        $this->assertSame(ImportExportActions::EXPORT_RATE_LIMIT, ImportExportActions::export(LeadExporter::class, Lead::class)->getRateLimit());
        $this->assertSame(ImportExportActions::EXPORT_RATE_LIMIT, ImportExportActions::exportBulk(LeadExporter::class, Lead::class)->getRateLimit());
    }

    #[Test]
    public function the_export_actions_are_offered_to_export_holders_and_hidden_from_everyone_else_on_every_list(): void
    {
        $rep = $this->salesRep();

        // A reader of every list who holds no export key (buildable through the Roles resource, D-3).
        $withoutExport = User::factory()->create();
        $withoutExport->syncRoles([]);
        $withoutExport->syncPermissions([
            Permission::LeadViewAny->value, Permission::ContactViewAny->value, Permission::AccountViewAny->value,
            Permission::DealViewAny->value, Permission::TaskViewAny->value, Permission::ActivityViewAny->value,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class, ListTasks::class, ListActivities::class] as $page) {
            Livewire::actingAs($rep)
                ->test($page)
                ->assertActionVisible(TestAction::make('export')->table())
                ->assertActionVisible(TestAction::make('export')->table()->bulk());

            // The action follows the entity's `{entity}.export` key alone (the role matrix decides who holds it).
            Livewire::actingAs($withoutExport)
                ->test($page)
                ->assertActionHidden(TestAction::make('export')->table())
                ->assertActionHidden(TestAction::make('export')->table()->bulk());
        }

        foreach ([$this->readOnly(), $this->support()] as $reader) {
            foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class, ListTasks::class, ListActivities::class] as $page) {
                $model = $page::getResource()::getModel();
                $component = Livewire::actingAs($reader)->test($page);

                $reader->can('export', $model)
                    ? $component->assertActionVisible(TestAction::make('export')->table())
                    : $component->assertActionHidden(TestAction::make('export')->table());
            }
        }
    }

    #[Test]
    public function a_manager_imports_a_csv_through_the_panel_and_the_leads_are_created_in_their_name(): void
    {
        $manager = $this->salesManager();
        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            "first_name,last_name,email,phone\nLive,Wire,live@example.com,0501234567\nSecond,Person,second@example.com,\n",
        );

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => $csv,
                'columnMap' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email',
                    'phone' => 'phone',
                ],
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $lead = Lead::query()->where('last_name', 'Wire')->firstOrFail();

        $this->assertSame($manager->getKey(), $lead->owner_id);
        $this->assertSame($manager->getKey(), $lead->created_by);
        $this->assertSame('+966501234567', $lead->phone_normalized);
        $this->assertTrue($lead->status->isDefault());
        $this->assertDatabaseHas('imports', ['user_id' => $manager->getKey(), 'total_rows' => 2, 'successful_rows' => 2]);
    }

    #[Test]
    public function a_manager_imports_an_excel_workbook_through_the_panel_and_the_rows_land_like_a_csv(): void
    {
        // The workbook is converted to CSV before Filament's reader sees it, so
        // the column mapping, the validation and the history behave exactly as
        // they do above, and the run keeps the name the user uploaded.
        $manager = $this->salesManager();
        $workbook = UploadedFile::fake()->createWithContent('leads.xlsx', $this->xlsxBytes([
            'العملاء' => [
                ['first_name', 'last_name', 'email', 'phone'],
                ['مها', 'الشمري', 'maha@example.com', '0501234567'],
                ['Sheet', 'Reader', 'sheet@example.com', null],
            ],
        ]));

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => $workbook,
                'columnMap' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email',
                    'phone' => 'phone',
                ],
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $lead = Lead::query()->where('email', 'maha@example.com')->firstOrFail();

        $this->assertSame('مها', $lead->first_name);
        $this->assertSame('الشمري', $lead->last_name);
        $this->assertSame('+966501234567', $lead->phone_normalized);
        $this->assertSame($manager->getKey(), $lead->owner_id);

        $this->assertDatabaseHas('leads', ['email' => 'sheet@example.com', 'last_name' => 'Reader']);
        $this->assertDatabaseHas('imports', [
            'user_id' => $manager->getKey(),
            'file_name' => 'leads.xlsx',
            'total_rows' => 2,
            'successful_rows' => 2,
        ]);
    }

    #[Test]
    public function only_the_workbook_format_the_reader_handles_faithfully_is_offered_and_accepted(): void
    {
        // OpenSpout cannot read .xls at all and reads an .ods boolean cell
        // wrongly, so the picker advertises neither and the upload rules let
        // neither through (module 18).
        $this->assertNotContains('application/vnd.ms-excel', SpreadsheetImportAction::ACCEPTED_FILE_TYPES);
        $this->assertNotContains('application/vnd.oasis.opendocument.spreadsheet', SpreadsheetImportAction::ACCEPTED_FILE_TYPES);
        $this->assertContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', SpreadsheetImportAction::ACCEPTED_FILE_TYPES);
        $this->assertSame(['csv', 'txt', 'xlsx'], SpreadsheetImportAction::ACCEPTED_EXTENSIONS);

        $rules = ImportExportActions::import(LeadImporter::class, Lead::class)->getFileValidationRules();

        $this->assertContains('extensions:csv,txt,xlsx', $rules);
        $this->assertNotContains('extensions:csv,txt', $rules, "Filament's own rule would refuse a workbook before it is read");

        // Real workbook bytes under each refused extension: the refusal is by
        // declared format, not by a container the reader could not have opened.
        $refused = [
            'legacy.xls' => $this->xlsxBytes(['Sheet1' => [['first_name'], ['Refused']]]),
            'contacts.ods' => $this->odsBytes(['Sheet1' => [['first_name'], ['Refused']]]),
        ];

        foreach ($refused as $name => $bytes) {
            Livewire::actingAs($this->salesManager())
                ->test(ListLeads::class)
                ->callAction('import', data: [
                    'file' => UploadedFile::fake()->createWithContent($name, $bytes),
                    'duplicate_strategy' => 'skip',
                ])
                ->assertHasActionErrors(['file']);
        }

        $this->assertDatabaseCount('leads', 0);
    }
}
