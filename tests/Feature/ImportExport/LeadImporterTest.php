<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\LeadPriority;
use App\Enums\LeadStatusKind;
use App\Enums\Permission;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Models\Import;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Csv\Reader;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The lead importer, driven through Filament's ImportCsv job with a prepared
 * Import row — exactly what the ImportAction dispatches (module 18).
 */
final class LeadImporterTest extends TestCase
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
    public function a_valid_csv_imports_leads_with_lookups_resolved_owner_defaulted_tags_synced_and_audited(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        Tag::factory()->create(['name_en' => 'Enterprise', 'name_ar' => 'المنشآت']);

        $import = $this->runImport($manager, $this->rows('leads.csv'));

        $this->assertSame(3, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $fahad = Lead::query()->where('last_name', 'Alqahtani')->firstOrFail();
        $this->assertSame('fahad@example.com', $fahad->email_normalized);
        $this->assertSame('+966501112233', $fahad->phone_normalized);
        $this->assertSame('SA', $fahad->country);
        $this->assertSame(LeadStatusKind::Working, $fahad->status->kind);
        $this->assertSame('Website', $fahad->source?->name_en);
        $this->assertSame(LeadPriority::High, $fahad->priority);
        $this->assertSame($manager->getKey(), $fahad->owner_id);
        $this->assertSame($manager->getKey(), $fahad->created_by);
        $this->assertEqualsCanonicalizing(['VIP', 'Enterprise'], $fahad->tags->pluck('name_en')->all());
        $this->assertNotNull($fahad->scored_at);

        $noura = Lead::query()->where('last_name', 'العتيبي')->firstOrFail();
        $this->assertTrue($noura->status->isDefault());
        $this->assertSame('Referral', $noura->source?->name_en);
        $this->assertSame(LeadPriority::High, $noura->priority);
        $this->assertSame($rep->getKey(), $noura->owner_id);
        $this->assertSame(['VIP'], $noura->tags->pluck('name_en')->all());

        $omar = Lead::query()->where('last_name', 'Saleh')->firstOrFail();
        $this->assertTrue($omar->status->isDefault());
        $this->assertSame(LeadPriority::Medium, $omar->priority);
        $this->assertSame($manager->getKey(), $omar->owner_id);
        $this->assertCount(0, $omar->tags);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LeadCreated->value,
            'subject_id' => $fahad->getKey(),
            'causer_id' => $manager->getKey(),
        ]);
    }

    #[Test]
    public function invalid_rows_land_in_failed_rows_with_a_translated_reason_and_do_not_stop_the_import(): void
    {
        $rep = $this->salesRep();
        $this->makeUser(CrmRole::SalesRep, ['email' => 'outsider@example.com']);

        $import = $this->runImport($rep, $this->rows('leads_invalid.csv'));

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(6, $import->getFailedRowsCount());
        $this->assertSame(6, FailedImportRow::query()->where('import_id', $import->getKey())->count());
        $this->assertTrue(Lead::query()->where('last_name', 'Row')->exists());

        $this->assertSame(__('validation.required', ['attribute' => __('imports.columns.lead.first_name')]), $this->failure($import, 'last_name', 'Missing'));
        $this->assertSame(__('validation.email', ['attribute' => __('imports.columns.lead.email')]), $this->failure($import, 'last_name', 'Email'));
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'Nonexistent']), $this->failure($import, 'email', 'unknown@example.com'));
        $this->assertSame(__('imports.validation.converted_status'), $this->failure($import, 'email', 'conv@example.com'));
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'outsider@example.com']), $this->failure($import, 'email', 'owner@example.com'));
        $this->assertSame(__('validation.in', ['attribute' => __('imports.columns.lead.country')]), $this->failure($import, 'email', 'country@example.com'));
    }

    #[Test]
    public function the_run_locale_decides_the_language_of_the_failure_reasons(): void
    {
        $rep = $this->salesRep();

        $import = $this->runImport($rep, [
            ['first_name' => 'Unknown', 'last_name' => 'Status', 'status' => 'Nonexistent'],
        ], ['locale' => 'en']);

        $this->assertSame(
            __('imports.validation.unknown_lookup', ['value' => 'Nonexistent'], 'en'),
            $this->failure($import, 'first_name', 'Unknown'),
        );

        foreach ([LeadImporter::class, ContactImporter::class, AccountImporter::class, DealImporter::class] as $importer) {
            foreach (['en', 'ar'] as $locale) {
                $import->options(['duplicate_strategy' => 'skip', 'locale' => $locale]);

                $this->assertSame(
                    __('imports.notifications.completed', ['successful' => '0', 'failed' => '1'], $locale),
                    $importer::getCompletedNotificationBody($import),
                    "{$importer} renders the {$locale} completion body regardless of the worker's locale",
                );
            }
        }

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function a_duplicate_inside_scope_is_skipped_or_updated_by_the_run_strategy(): void
    {
        $rep = $this->salesRep();
        $existing = Lead::factory()->create(['owner_id' => $rep->getKey(), 'email' => 'dup@example.com', 'company_name' => 'Old Co']);
        $contacted = LeadStatus::query()->where('kind', LeadStatusKind::Working->value)->firstOrFail();
        $row = ['first_name' => 'Dup', 'last_name' => 'Licate', 'email' => 'DUP@example.com', 'company_name' => 'New Co', 'status' => $contacted->name_en];

        $skipped = $this->runImport($rep, [$row]);

        $this->assertSame(0, $skipped->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $existing->getKey()]), $this->failure($skipped, 'email', 'DUP@example.com'));
        $this->assertSame(1, Lead::query()->count());
        $this->assertSame('Old Co', $existing->refresh()->company_name);

        $updated = $this->runImport($rep, [$row], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $updated->successful_rows);
        $this->assertSame(1, Lead::query()->count());
        $existing->refresh();
        $this->assertSame('New Co', $existing->company_name);
        $this->assertSame('Dup', $existing->first_name);
        $this->assertTrue($existing->status->isDefault(), 'an existing lead keeps its status: moves go through the workflow');
    }

    #[Test]
    public function a_duplicate_outside_the_importers_scope_is_invisible_so_a_new_lead_is_created(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        Lead::factory()->create(['owner_id' => $other->getKey(), 'email' => 'shared@example.com']);

        $import = $this->runImport($rep, [
            ['first_name' => 'Same', 'last_name' => 'Email', 'email' => 'shared@example.com'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(2, Lead::query()->where('email_normalized', 'shared@example.com')->count());
        $this->assertSame($rep->getKey(), Lead::query()->where('last_name', 'Email')->firstOrFail()->owner_id);
    }

    #[Test]
    public function the_importer_carries_the_duplicate_strategy_option_and_translated_column_labels(): void
    {
        $components = LeadImporter::getOptionsFormComponents();

        $this->assertCount(1, $components);
        $this->assertInstanceOf(Select::class, $components[0]);
        $this->assertSame('duplicate_strategy', $components[0]->getName());

        foreach (LeadImporter::getColumns() as $column) {
            $this->assertSame(__('imports.columns.lead.'.$column->getName()), $column->getLabel());
        }
    }

    #[Test]
    public function the_policy_verbs_gate_creating_updating_and_reassigning_through_the_import(): void
    {
        $team = $this->makeTeam();
        $teammate = $this->salesRep($team);
        $teammate->forceFill(['email' => 'teammate@example.com'])->save();
        $theirs = Lead::factory()->create(['owner_id' => $teammate->getKey(), 'email' => 'theirs@example.com', 'company_name' => 'Old Co']);
        $importer = $this->userWithPermissions($team, [Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadImport]);

        $refused = $this->runImport($importer, [
            ['first_name' => 'Update', 'last_name' => 'Theirs', 'email' => 'theirs@example.com', 'company_name' => 'New Co'],
            ['first_name' => 'Brand', 'last_name' => 'New', 'email' => 'new@example.com'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(0, $refused->successful_rows);
        $this->assertSame(__('imports.validation.update_forbidden', ['id' => (string) $theirs->getKey()]), $this->failure($refused, 'email', 'theirs@example.com'));
        $this->assertSame(__('imports.validation.create_forbidden'), $this->failure($refused, 'email', 'new@example.com'));
        $this->assertSame('Old Co', $theirs->refresh()->company_name, 'a visible teammate record is not updated without the update verb');
        $this->assertSame(1, Lead::query()->count(), 'nothing is created without the create verb');

        $importer->givePermissionTo(Permission::LeadCreate->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $handed = $this->runImport($importer->fresh() ?? $importer, [
            ['first_name' => 'Handed', 'last_name' => 'Over', 'email' => 'handed@example.com', 'owner' => 'teammate@example.com'],
            ['first_name' => 'Kept', 'last_name' => 'Mine', 'email' => 'kept@example.com', 'owner' => $importer->email],
        ]);

        $this->assertSame(1, $handed->successful_rows);
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'teammate@example.com']), $this->failure($handed, 'email', 'handed@example.com'));
        $this->assertFalse(Lead::query()->where('email_normalized', 'handed@example.com')->exists(), 'a reachable teammate is still not an owner without the assign verb (D-4)');
        $this->assertSame($importer->getKey(), Lead::query()->where('email_normalized', 'kept@example.com')->firstOrFail()->owner_id);
    }

    /**
     * A user holding exactly the given permissions and nothing through a
     * role (buildable through the Roles resource, D-3), in the team.
     *
     * @param  list<Permission>  $permissions
     */
    private function userWithPermissions(Team $team, array $permissions): User
    {
        $user = User::factory()->create(['team_id' => $team->getKey()]);
        $user->syncRoles([]);
        $user->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(User $user, array $rows, array $options = []): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->file_name = 'leads.csv';
        $import->file_path = 'leads.csv';
        $import->importer = LeadImporter::class;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'skip', ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $fixture): array
    {
        $reader = Reader::createFromPath(base_path('tests/Fixtures/imports/'.$fixture));
        $reader->setHeaderOffset(0);

        return array_values(iterator_to_array($reader->getRecords()));
    }

    /** The reason the row whose $column holds $value was refused. */
    private function failure(Import $import, string $column, string $value): ?string
    {
        foreach (FailedImportRow::query()->where('import_id', $import->getKey())->get() as $row) {
            if (($row->data[$column] ?? null) === $value) {
                return $row->validation_error;
            }
        }

        $this->fail("no failed row with {$column} = {$value}");
    }
}
