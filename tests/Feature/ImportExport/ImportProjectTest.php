<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Imports\Events\ImportStarted;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Concerns\DrainsQueueLikeTheScheduler;
use Tests\TestCase;

/**
 * The import's project (decision D-30): the modal's required «المشروع»
 * select, first among the options and pre-filled with the importer's current
 * project; the records a run creates land in the chosen project; duplicates,
 * accounts, parents and contacts are looked up inside it only — the same
 * e-mail or company name in another project is not found, which is the
 * isolation the owner asked for; the history row keeps the run's project
 * (StampImportRunOptions) and the history shows it as the project's badge.
 *
 * Every run writes into «إيقان», a second active project, while the default
 * project holds look-alike records that must stay untouched and unseen. The
 * direct ImportCsv path drives the importers the way the queue does; the
 * panel action covers the modal, the history and the queued and check-only
 * runs.
 */
final class ImportProjectTest extends TestCase
{
    use CreatesCrmFixtures;
    use DrainsQueueLikeTheScheduler;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function every_importer_offers_the_project_select_first_with_the_active_projects_and_the_current_project_selected(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);
        $this->useProject($manager, $iqan);
        $this->actingAs($manager);

        $default = $this->defaultProject();
        $expectedOptions = [
            (int) $default->getKey() => $default->display_name,
            (int) $iqan->getKey() => $iqan->display_name,
        ];

        foreach ([LeadImporter::class, ContactImporter::class, AccountImporter::class, DealImporter::class] as $importer) {
            $components = $importer::getOptionsFormComponents();

            $this->assertCount(3, $components, $importer);

            $select = $components[0];
            $this->assertInstanceOf(Select::class, $select, $importer);
            $this->assertSame(ImportExportActions::PROJECT_OPTION, $select->getName(), $importer);
            $this->assertSame(__('imports.fields.project'), $select->getLabel(), $importer);
            $this->assertTrue($select->isRequired(), "{$importer}: a run always names its project");
            $this->assertSame($expectedOptions, $select->getOptions(), "{$importer}: the active projects in switcher order, the inactive one left out");
            $this->assertArrayNotHasKey((int) $legacy->getKey(), $select->getOptions(), $importer);
            $this->assertSame((int) $iqan->getKey(), $select->getDefaultState(), "{$importer}: the importer's current project is pre-selected");

            $strategy = $components[1];
            $this->assertInstanceOf(Select::class, $strategy, $importer);
            $this->assertSame('duplicate_strategy', $strategy->getName(), $importer);

            $checkbox = $components[2];
            $this->assertInstanceOf(Checkbox::class, $checkbox, $importer);
            $this->assertSame(ImportExportActions::DRY_RUN_OPTION, $checkbox->getName(), $importer);
        }

        // The modal carries the select with its label and its explanation
        // (the helper text is the Text component Filament puts below the
        // field), pre-filled with the current project.
        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->mountAction('import')
            ->assertFormFieldExists(ImportExportActions::PROJECT_OPTION, static function (Select $field): bool {
                $helper = $field->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;

                return $field->getLabel() === __('imports.fields.project')
                    && $helper instanceof Text
                    && $helper->getContent() === __('imports.helpers.project');
            })
            ->assertActionDataSet([ImportExportActions::PROJECT_OPTION => (int) $iqan->getKey()]);
    }

    #[Test]
    public function a_lead_run_creates_its_leads_in_the_chosen_project_and_finds_duplicates_inside_it_only(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $default = $this->defaultProject();
        $inDefault = Lead::factory()->create(['owner_id' => $manager->getKey(), 'email' => 'shared@example.com', 'company_name' => 'Default Co']);
        $inIqan = Lead::factory()->create(['owner_id' => $manager->getKey(), 'project_id' => $iqan->getKey(), 'email' => 'iqan.dup@example.com', 'company_name' => 'Old Co']);

        $import = $this->runImport($manager, LeadImporter::class, [
            ['first_name' => 'Fresh', 'last_name' => 'Lead', 'email' => 'fresh@example.com', 'company_name' => ''],
            ['first_name' => 'Same', 'last_name' => 'Email', 'email' => 'shared@example.com', 'company_name' => 'Iqan Co'],
            ['first_name' => 'Dup', 'last_name' => 'Iqan', 'email' => 'iqan.dup@example.com', 'company_name' => 'New Co'],
        ], [ImportExportActions::PROJECT_OPTION => $iqan->getKey(), 'duplicate_strategy' => LeadImporter::DUPLICATE_UPDATE]);

        $this->assertSame(3, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $fresh = Lead::query()->where('email_normalized', 'fresh@example.com')->firstOrFail();
        $this->assertSame($iqan->getKey(), $fresh->project_id, 'a created lead lands in the chosen project');
        $this->assertSame($import->getKey(), $fresh->getAttribute('import_id'));

        // The same e-mail in the default project is not a duplicate here: a new lead is created in «إيقان».
        $shared = Lead::query()->where('email_normalized', 'shared@example.com')->orderBy('id')->get();
        $this->assertCount(2, $shared);
        $this->assertSame($default->getKey(), $inDefault->refresh()->project_id);
        $this->assertSame('Default Co', $inDefault->company_name, 'the look-alike of the default project is untouched');
        $iqanShared = $shared->last();
        $this->assertNotNull($iqanShared);
        $this->assertSame($iqan->getKey(), $iqanShared->project_id);
        $this->assertSame('Iqan Co', $iqanShared->company_name);

        // The duplicate inside «إيقان» is updated and keeps its project.
        $this->assertSame(1, Lead::query()->where('email_normalized', 'iqan.dup@example.com')->count());
        $inIqan->refresh();
        $this->assertSame('New Co', $inIqan->company_name);
        $this->assertSame($iqan->getKey(), $inIqan->project_id);
        $this->assertNull($inIqan->getAttribute('import_id'), 'an updated duplicate is not marked as created by the run');

        // Under «skip» the lead just created in «إيقان» — not the default project's — is the duplicate named.
        $skipped = $this->runImport($manager, LeadImporter::class, [
            ['first_name' => 'Same', 'last_name' => 'Again', 'email' => 'shared@example.com'],
        ], [ImportExportActions::PROJECT_OPTION => $iqan->getKey()]);

        $this->assertSame(0, $skipped->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $iqanShared->getKey()]), $this->failedImportReason($skipped, 'email', 'shared@example.com'));
    }

    #[Test]
    public function a_contact_run_resolves_the_account_inside_the_chosen_project_only(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);
        $iqanHorizon = Account::factory()->create(['owner_id' => $manager->getKey(), 'project_id' => $iqan->getKey(), 'name' => 'Horizon Trading']);
        Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Default Only Co']);

        $import = $this->runImport($manager, ContactImporter::class, [
            ['first_name' => 'Linked', 'last_name' => 'Person', 'email' => 'linked@example.com', 'account' => 'Horizon Trading'],
            ['first_name' => 'Lost', 'last_name' => 'Person', 'email' => 'lost@example.com', 'account' => 'Default Only Co'],
        ], [ImportExportActions::PROJECT_OPTION => $iqan->getKey()]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());

        $linked = Contact::query()->where('email_normalized', 'linked@example.com')->firstOrFail();
        $this->assertSame($iqanHorizon->getKey(), $linked->account_id, 'the same-named account of «إيقان», not the default project\'s');
        $this->assertSame($iqan->getKey(), $linked->project_id);

        $this->assertSame(
            __('imports.validation.account_not_found', ['value' => 'Default Only Co']),
            $this->failedImportReason($import, 'email', 'lost@example.com'),
            'an account that only another project holds is not found',
        );
        $this->assertFalse(Contact::query()->where('email_normalized', 'lost@example.com')->exists());
    }

    #[Test]
    public function a_deal_run_resolves_the_account_and_the_contact_inside_the_chosen_project_only(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $defaultHorizon = Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);
        $iqanHorizon = Account::factory()->create(['owner_id' => $manager->getKey(), 'project_id' => $iqan->getKey(), 'name' => 'Horizon Trading']);
        Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Default Only Co']);
        Contact::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $defaultHorizon->getKey(), 'first_name' => 'Sara', 'last_name' => 'Ahmed']);
        $noura = Contact::factory()->create(['owner_id' => $manager->getKey(), 'project_id' => $iqan->getKey(), 'account_id' => $iqanHorizon->getKey(), 'first_name' => 'Noura', 'last_name' => 'Alotaibi']);

        $import = $this->runImport($manager, DealImporter::class, [
            ['title' => 'Iqan Deal', 'account' => 'Horizon Trading', 'contact' => 'Noura Alotaibi'],
            ['title' => 'Lost Deal', 'account' => 'Default Only Co', 'contact' => ''],
            ['title' => 'Wrong Contact', 'account' => 'Horizon Trading', 'contact' => 'Sara Ahmed'],
        ], [ImportExportActions::PROJECT_OPTION => $iqan->getKey()]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(2, $import->getFailedRowsCount());

        $deal = Deal::query()->where('title', 'Iqan Deal')->firstOrFail();
        $this->assertSame($iqanHorizon->getKey(), $deal->account_id, 'the same-named account of «إيقان», not the default project\'s');
        $this->assertSame($noura->getKey(), $deal->contact_id);
        $this->assertSame($iqan->getKey(), $deal->project_id);

        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Default Only Co']), $this->failedImportReason($import, 'title', 'Lost Deal'));
        $this->assertSame(
            __('imports.validation.unknown_lookup', ['value' => 'Sara Ahmed']),
            $this->failedImportReason($import, 'title', 'Wrong Contact'),
            'a contact of the default project\'s account is not a contact of «إيقان»\'s',
        );
        $this->assertSame(1, Deal::query()->count());
    }

    #[Test]
    public function an_account_run_finds_duplicates_and_parents_inside_the_chosen_project_only(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);
        $iqanHorizon = Account::factory()->create(['owner_id' => $manager->getKey(), 'project_id' => $iqan->getKey(), 'name' => 'Horizon Trading']);
        Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Default Only Co']);

        $import = $this->runImport($manager, AccountImporter::class, [
            ['name' => 'Horizon Trading', 'parent' => ''],
            ['name' => 'Horizon Retail', 'parent' => 'Default Only Co'],
            ['name' => 'Horizon Branch', 'parent' => 'Horizon Trading'],
            ['name' => 'Default Only Co', 'parent' => ''],
        ], [ImportExportActions::PROJECT_OPTION => $iqan->getKey()]);

        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(2, $import->getFailedRowsCount());

        // The duplicate named is the one in «إيقان»; the parent found is the one in «إيقان».
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $iqanHorizon->getKey()]), $this->failedImportReason($import, 'name', 'Horizon Trading'));
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Default Only Co']), $this->failedImportReason($import, 'name', 'Horizon Retail'));

        $branch = Account::query()->where('name', 'Horizon Branch')->firstOrFail();
        $this->assertSame($iqanHorizon->getKey(), $branch->parent_account_id);
        $this->assertSame($iqan->getKey(), $branch->project_id);

        // A name the default project already holds is new in «إيقان».
        $copies = Account::query()->where('name', 'Default Only Co')->orderBy('id')->get();
        $this->assertCount(2, $copies);
        $this->assertSame($iqan->getKey(), $copies->last()?->project_id);
    }

    #[Test]
    public function a_run_started_from_the_panel_records_its_project_on_the_history_row_and_the_history_shows_it(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $default = $this->defaultProject();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', self::csv(['first_name', 'last_name', 'email'], [['Fresh', 'Lead', 'fresh@example.com']])),
                'columnMap' => ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email'],
                'duplicate_strategy' => LeadImporter::DUPLICATE_SKIP,
                ImportExportActions::PROJECT_OPTION => $iqan->getKey(),
            ])
            ->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertSame($iqan->getKey(), $import->project_id);
        $this->assertTrue($import->project?->is($iqan) ?? false);
        $this->assertSame(1, $import->successful_rows);
        $this->assertSame($iqan->getKey(), Lead::query()->where('email_normalized', 'fresh@example.com')->firstOrFail()->project_id);

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$import])
            ->assertSee($iqan->display_name);

        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.fields.project'))
            ->assertSee($iqan->display_name);

        // Left as the modal pre-fills it, the run goes into the importer's current project.
        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', self::csv(['first_name', 'last_name', 'email'], [['Second', 'Lead', 'second@example.com']])),
                'columnMap' => ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email'],
                'duplicate_strategy' => LeadImporter::DUPLICATE_SKIP,
            ])
            ->assertHasNoActionErrors();

        $second = Import::query()->latest('id')->firstOrFail();
        $this->assertNotSame($import->getKey(), $second->getKey());
        $this->assertSame($default->getKey(), $second->project_id);
        $this->assertSame($default->getKey(), Lead::query()->where('email_normalized', 'second@example.com')->firstOrFail()->project_id);
    }

    /**
     * Production's path (D-1, A-7): the panel action queues the rows on the
     * `database` connection and the scheduler drains them a minute later in a
     * console process with no panel and no signed-in user — so the project
     * must travel in the options and sit on the history row before any job
     * runs, never be read from whoever is signed in.
     */
    #[Test]
    public function a_queued_run_carries_its_project_from_the_request_to_the_drained_rows(): void
    {
        config()->set('queue.default', 'database');

        $manager = $this->salesManager();
        $iqan = $this->makeProject();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', self::csv(['first_name', 'last_name', 'email'], [
                    ['Queued', 'One', 'queued.one@example.com'],
                    ['Queued', 'Two', 'queued.two@example.com'],
                ])),
                'columnMap' => ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email'],
                'duplicate_strategy' => LeadImporter::DUPLICATE_SKIP,
                ImportExportActions::PROJECT_OPTION => $iqan->getKey(),
            ])
            ->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();

        // The project is on the row before any job runs: the listener ran in the request.
        $this->assertSame($iqan->getKey(), $import->project_id, 'stamped by StampImportRunOptions in the request, before the drain');
        $this->assertSame(0, $import->processed_rows);
        $this->assertNull($import->completed_at);
        $this->assertSame(0, Lead::query()->count(), 'nothing is written in the request');
        $this->assertSame(1, DB::table('jobs')->count());

        $output = $this->drainQueueLikeTheScheduler();

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);

        $import->refresh();
        $this->assertSame(2, $import->successful_rows);
        $this->assertNotNull($import->completed_at);
        $this->assertSame($iqan->getKey(), $import->project_id);
        $this->assertSame(2, Lead::query()->where('project_id', $iqan->getKey())->count(), 'the worker wrote where the modal said');
        $this->assertSame(2, Lead::query()->count());
    }

    #[Test]
    public function a_check_only_run_writes_nothing_and_still_records_the_project(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('check.csv', self::csv(['first_name', 'last_name', 'email'], [['Checked', 'Lead', 'checked@example.com']])),
                'columnMap' => ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email'],
                'duplicate_strategy' => LeadImporter::DUPLICATE_SKIP,
                ImportExportActions::PROJECT_OPTION => $iqan->getKey(),
                ImportExportActions::DRY_RUN_OPTION => true,
            ])
            ->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertTrue($import->dry_run);
        $this->assertSame($iqan->getKey(), $import->project_id, 'the history says which project the file was checked against');
        $this->assertSame(ImportResource::MODE_DRY_RUN, ImportResource::mode($import));
        $this->assertSame(1, $import->successful_rows, 'the row is valid');
        $this->assertSame(0, Lead::query()->count(), 'and nothing was written');

        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.modes.dry_run'))
            ->assertSee($iqan->display_name);
    }

    /**
     * Filament fires ImportStarted right after it saves the Import row and
     * before it dispatches the batch; the listener is the one place that sees
     * the row and the modal's options together, so what it stamps is what the
     * jobs, the completion notice and the history read.
     */
    #[Test]
    public function the_listener_stamps_the_project_and_the_check_only_flag_from_the_options_on_the_instance_the_event_carries(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);

        $named = $this->startedRun($manager);
        $both = $this->startedRun($manager);
        $unknown = $this->startedRun($manager);
        $none = $this->startedRun($manager);
        $inactive = $this->startedRun($manager);

        event(new ImportStarted($named, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::PROJECT_OPTION => $iqan->getKey()]));
        event(new ImportStarted($both, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::PROJECT_OPTION => (string) $iqan->getKey(), ImportExportActions::DRY_RUN_OPTION => true]));
        event(new ImportStarted($unknown, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::PROJECT_OPTION => 999999]));
        event(new ImportStarted($none, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP]));
        event(new ImportStarted($inactive, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::PROJECT_OPTION => $legacy->getKey()]));

        $this->assertSame($iqan->getKey(), $named->project_id, 'stamped on the very instance the event carries, which the batch serialises afterwards');
        $this->assertSame($iqan->getKey(), $named->refresh()->project_id, 'and saved, so a worker reading the row back sees it');
        $this->assertFalse($named->dry_run);

        $both->refresh();
        $this->assertSame($iqan->getKey(), $both->project_id, 'the select sends the key as a string');
        $this->assertTrue($both->dry_run);

        $this->assertNull($unknown->refresh()->project_id, 'an id naming no project is not written, so the foreign key never refuses the run');
        $this->assertNull($none->refresh()->project_id, 'a run from before projects, or without the modal, names none');
        $this->assertSame($legacy->getKey(), $inactive->refresh()->project_id, 'the history records which project was asked for even when the importer then refuses it');
    }

    #[Test]
    public function an_inactive_project_in_the_options_fails_every_row_with_a_translated_reason_and_writes_nothing(): void
    {
        $manager = $this->salesManager();
        $legacy = $this->makeProject('Legacy', 'قديم', ['is_active' => false]);

        $import = $this->runImport($manager, LeadImporter::class, [
            ['first_name' => 'First', 'last_name' => 'Row', 'email' => 'first@example.com'],
            ['first_name' => 'Second', 'last_name' => 'Row', 'email' => 'second@example.com'],
        ], [ImportExportActions::PROJECT_OPTION => $legacy->getKey()]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(2, $import->getFailedRowsCount());
        $this->assertSame(__('projects.validation.inactive'), $this->failedImportReason($import, 'email', 'first@example.com'));
        $this->assertSame(__('projects.validation.inactive'), $this->failedImportReason($import, 'email', 'second@example.com'));
        $this->assertSame(0, Lead::query()->count());

        $unknown = $this->runImport($manager, LeadImporter::class, [
            ['first_name' => 'Third', 'last_name' => 'Row', 'email' => 'third@example.com'],
        ], [ImportExportActions::PROJECT_OPTION => 999999]);

        $this->assertSame(__('projects.validation.inactive'), $this->failedImportReason($unknown, 'email', 'third@example.com'));
        $this->assertSame(0, Lead::query()->count());
    }

    /**
     * The direct ImportCsv path without the modal — the only way a run's
     * options carry no project — writes into the importing user's current
     * project, the same answer the BelongsToProject guard gives a record
     * created without one; nothing reads the signed-in user.
     */
    #[Test]
    public function a_run_whose_options_name_no_project_writes_into_the_importing_users_current_project(): void
    {
        $manager = $this->salesManager();
        $iqan = $this->makeProject();
        $this->useProject($manager, $iqan);

        $import = $this->runImport($manager, LeadImporter::class, [
            ['first_name' => 'Current', 'last_name' => 'Project', 'email' => 'current@example.com'],
        ]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame($iqan->getKey(), Lead::query()->where('email_normalized', 'current@example.com')->firstOrFail()->project_id);
    }

    /**
     * Drives the importer through Filament's ImportCsv job with a prepared
     * Import row — exactly what the ImportAction dispatches — with the given
     * options on top of the «skip» strategy. The first row's keys are the
     * file's columns, so every row carries the same keys.
     *
     * @param  class-string  $importer
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(User $user, string $importer, array $rows, array $options = []): Import
    {
        $import = $this->startedRun($user, $importer, count($rows));
        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }

    /**
     * An Import row as Filament saves it before ImportStarted fires: no rows
     * processed, not completed — makeImport() with the completion undone.
     *
     * @param  class-string  $importer
     */
    private function startedRun(User $user, string $importer = LeadImporter::class, int $totalRows = 1): Import
    {
        return $this->makeImport($user, $importer, 0, [
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private static function csv(array $headers, array $rows): string
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines)."\n";
    }
}
