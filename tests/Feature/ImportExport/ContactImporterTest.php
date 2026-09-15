<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Filament\Imports\ContactImporter;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Import;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The contact importer, driven through Filament's ImportCsv job with a
 * prepared Import row — exactly what the ImportAction dispatches (module 18).
 */
final class ContactImporterTest extends TestCase
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
    public function a_valid_csv_imports_contacts_with_the_account_resolved_the_primary_rule_applied_and_tags_synced(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);
        $previousPrimary = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'is_primary' => true]);
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);

        $import = $this->runImport($rep, $this->importFixtureRows('contacts.csv'));

        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $noura = Contact::query()->where('last_name', 'Alotaibi')->firstOrFail();
        $this->assertSame($account->getKey(), $noura->account_id);
        $this->assertSame('noura@example.com', $noura->email_normalized);
        $this->assertSame('+966509998877', $noura->phone_normalized, 'the mobile number feeds the normalised phone');
        $this->assertSame('ar', $noura->preferred_locale);
        $this->assertTrue($noura->is_primary);
        $this->assertFalse($previousPrimary->refresh()->is_primary, 'one primary contact per account');
        $this->assertSame($rep->getKey(), $noura->owner_id);
        $this->assertSame($rep->getKey(), $noura->created_by);
        $this->assertSame(['VIP'], $noura->tags->pluck('name_en')->all());

        $sara = Contact::query()->where('last_name', 'Ahmed')->firstOrFail();
        $this->assertNull($sara->account_id);
        $this->assertFalse($sara->is_primary);
        $this->assertSame('en', $sara->preferred_locale);
        $this->assertCount(0, $sara->tags);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::ContactCreated->value,
            'subject_id' => $noura->getKey(),
            'causer_id' => $rep->getKey(),
        ]);
    }

    #[Test]
    public function invalid_rows_land_in_failed_rows_with_a_translated_reason_and_do_not_stop_the_import(): void
    {
        $rep = $this->salesRep();
        $this->makeUser(CrmRole::SalesRep, ['email' => 'outsider@example.com']);

        $import = $this->runImport($rep, $this->importFixtureRows('contacts_invalid.csv'));

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(5, $import->getFailedRowsCount());
        $this->assertTrue(Contact::query()->where('last_name', 'Contact')->exists());

        $this->assertSame(__('validation.required', ['attribute' => __('imports.columns.contact.first_name')]), $this->failedImportReason($import, 'last_name', 'NoFirst'));
        $this->assertSame(__('validation.email', ['attribute' => __('imports.columns.contact.email')]), $this->failedImportReason($import, 'last_name', 'Mail'));
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Nonexistent Co']), $this->failedImportReason($import, 'email', 'ghost@example.com'));
        $this->assertSame(__('validation.in', ['attribute' => __('imports.columns.contact.preferred_locale')]), $this->failedImportReason($import, 'email', 'locale@example.com'));
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'outsider@example.com']), $this->failedImportReason($import, 'email', 'outside@example.com'));
    }

    #[Test]
    public function an_account_outside_the_importers_scope_cannot_be_linked_but_a_manager_of_the_team_can_link_it(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $manager = $this->salesManager($team);
        $stranger = $this->salesRep();
        Account::factory()->create(['owner_id' => $stranger->getKey(), 'name' => 'Far Away Co']);
        Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Team Co']);
        $row = ['first_name' => 'Linked', 'last_name' => 'Person', 'email' => 'linked@example.com', 'account' => 'Far Away Co'];

        $refused = $this->runImport($rep, [$row]);

        $this->assertSame(0, $refused->successful_rows);
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Far Away Co']), $this->failedImportReason($refused, 'email', 'linked@example.com'));

        $accepted = $this->runImport($manager, [['first_name' => 'Team', 'last_name' => 'Person', 'email' => 'team@example.com', 'account' => 'team co']]);

        $this->assertSame(1, $accepted->successful_rows);
        $this->assertSame('Team Co', Contact::query()->where('last_name', 'Person')->firstOrFail()->account?->name);
    }

    #[Test]
    public function a_duplicate_inside_scope_is_matched_on_email_or_mobile_and_skipped_or_updated_by_the_run_strategy(): void
    {
        $rep = $this->salesRep();
        $existing = Contact::factory()->create(['owner_id' => $rep->getKey(), 'email' => 'someone@example.com', 'mobile' => '0509998877', 'job_title' => 'Old title']);
        $row = ['first_name' => 'By', 'last_name' => 'Mobile', 'email' => 'different@example.com', 'mobile' => '+966 50 999 8877', 'job_title' => 'New title'];

        $skipped = $this->runImport($rep, [$row]);

        $this->assertSame(0, $skipped->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $existing->getKey()]), $this->failedImportReason($skipped, 'last_name', 'Mobile'));
        $this->assertSame(1, Contact::query()->count());

        $updated = $this->runImport($rep, [$row], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $updated->successful_rows);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame('New title', $existing->refresh()->job_title);
    }

    #[Test]
    public function a_duplicate_outside_the_importers_scope_is_invisible_so_a_new_contact_is_created(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        Contact::factory()->create(['owner_id' => $other->getKey(), 'email' => 'shared@example.com']);

        $import = $this->runImport($rep, [
            ['first_name' => 'Same', 'last_name' => 'Email', 'email' => 'shared@example.com'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(2, Contact::query()->where('email_normalized', 'shared@example.com')->count());
    }

    #[Test]
    public function the_importer_carries_the_duplicate_strategy_option_and_translated_column_labels(): void
    {
        $components = ContactImporter::getOptionsFormComponents();

        $this->assertCount(1, $components);
        $this->assertInstanceOf(Select::class, $components[0]);
        $this->assertSame('duplicate_strategy', $components[0]->getName());

        foreach (ContactImporter::getColumns() as $column) {
            $this->assertSame(__('imports.columns.contact.'.$column->getName()), $column->getLabel());
        }
    }

    #[Test]
    public function the_policy_verbs_gate_creating_updating_and_reassigning_through_the_import(): void
    {
        $team = $this->makeTeam();
        $teammate = $this->salesRep($team);
        $teammate->forceFill(['email' => 'teammate@example.com'])->save();
        $theirs = Contact::factory()->create(['owner_id' => $teammate->getKey(), 'email' => 'theirs@example.com', 'job_title' => 'Old title']);
        $importer = $this->userWithPermissions($team, [Permission::ContactViewAny, Permission::ContactViewTeam, Permission::ContactImport]);

        $refused = $this->runImport($importer, [
            ['first_name' => 'Update', 'last_name' => 'Theirs', 'email' => 'theirs@example.com', 'job_title' => 'New title'],
            ['first_name' => 'Brand', 'last_name' => 'New', 'email' => 'new@example.com'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(0, $refused->successful_rows);
        $this->assertSame(__('imports.validation.update_forbidden', ['id' => (string) $theirs->getKey()]), $this->failedImportReason($refused, 'email', 'theirs@example.com'));
        $this->assertSame(__('imports.validation.create_forbidden'), $this->failedImportReason($refused, 'email', 'new@example.com'));
        $this->assertSame('Old title', $theirs->refresh()->job_title);
        $this->assertSame(1, Contact::query()->count());

        $importer->givePermissionTo(Permission::ContactCreate->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $handed = $this->runImport($importer->fresh() ?? $importer, [
            ['first_name' => 'Handed', 'last_name' => 'Over', 'email' => 'handed@example.com', 'owner' => 'teammate@example.com'],
            ['first_name' => 'Kept', 'last_name' => 'Mine', 'email' => 'kept@example.com', 'owner' => $importer->email],
        ]);

        $this->assertSame(1, $handed->successful_rows);
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'teammate@example.com']), $this->failedImportReason($handed, 'email', 'handed@example.com'));
        $this->assertFalse(Contact::query()->where('email_normalized', 'handed@example.com')->exists());
        $this->assertSame($importer->getKey(), Contact::query()->where('email_normalized', 'kept@example.com')->firstOrFail()->owner_id);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(User $user, array $rows, array $options = []): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->file_name = 'contacts.csv';
        $import->file_path = 'contacts.csv';
        $import->importer = ContactImporter::class;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'skip', ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }
}
