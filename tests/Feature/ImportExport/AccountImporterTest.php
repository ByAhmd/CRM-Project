<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CompanySize;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Filament\Imports\AccountImporter;
use App\Models\Account;
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
 * The account importer, driven through Filament's ImportCsv job with a
 * prepared Import row — exactly what the ImportAction dispatches (module 18).
 */
final class AccountImporterTest extends TestCase
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
    public function a_valid_csv_imports_accounts_with_enums_industry_parent_and_tags_resolved(): void
    {
        // The file sets a customer type by hand, which needs account.set_type (D-6), so an admin runs it.
        $admin = $this->admin();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        Tag::factory()->create(['name_en' => 'Enterprise', 'name_ar' => 'المنشآت']);

        $import = $this->runImport($admin, $this->importFixtureRows('accounts.csv'));

        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $trading = Account::query()->where('name', 'Horizon Trading')->firstOrFail();
        $this->assertSame(AccountType::Customer, $trading->type);
        $this->assertSame('Technology', $trading->industry?->name_en);
        $this->assertSame(CompanySize::From51To200, $trading->size);
        $this->assertSame('info@horizon.example.com', $trading->email_normalized);
        $this->assertSame('+966112223344', $trading->phone_normalized);
        $this->assertSame('SA', $trading->country);
        $this->assertSame($admin->getKey(), $trading->owner_id);
        $this->assertSame($admin->getKey(), $trading->created_by);
        $this->assertEqualsCanonicalizing(['VIP', 'Enterprise'], $trading->tags->pluck('name_en')->all());

        $retail = Account::query()->where('name', 'Horizon Retail')->firstOrFail();
        $this->assertSame(AccountType::Prospect, $retail->type, 'a new account is a prospect unless the file says otherwise');
        $this->assertSame('Retail', $retail->industry?->name_en, 'the industry was named in Arabic');
        $this->assertSame($trading->getKey(), $retail->parent_account_id, 'the parent was created earlier in the same run');
        $this->assertCount(0, $retail->tags);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AccountCreated->value,
            'subject_id' => $trading->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function invalid_rows_land_in_failed_rows_with_a_translated_reason_and_do_not_stop_the_import(): void
    {
        $rep = $this->salesRep();
        $this->makeUser(CrmRole::SalesRep, ['email' => 'outsider@example.com']);

        $import = $this->runImport($rep, $this->importFixtureRows('accounts_invalid.csv'));

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(5, $import->getFailedRowsCount());
        $this->assertTrue(Account::query()->where('name', 'Good Account')->exists());

        $this->assertSame(__('validation.required', ['attribute' => __('imports.columns.account.name')]), $this->failedImportReason($import, 'email', 'noname@example.com'));
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'vendor']), $this->failedImportReason($import, 'name', 'Bad Type'));
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'Astrology']), $this->failedImportReason($import, 'name', 'Bad Industry'));
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Nonexistent Group']), $this->failedImportReason($import, 'name', 'Bad Parent'));
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'outsider@example.com']), $this->failedImportReason($import, 'name', 'Owner Outside'));
    }

    #[Test]
    public function a_duplicate_inside_scope_is_matched_on_name_or_email_and_skipped_or_updated_by_the_run_strategy(): void
    {
        $rep = $this->salesRep();
        $existing = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading', 'website' => 'https://old.example.com']);
        $row = ['name' => 'horizon trading', 'website' => 'https://new.example.com'];

        $skipped = $this->runImport($rep, [$row]);

        $this->assertSame(0, $skipped->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $existing->getKey()]), $this->failedImportReason($skipped, 'name', 'horizon trading'));
        $this->assertSame(1, Account::query()->count());

        $updated = $this->runImport($rep, [$row], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $updated->successful_rows);
        $this->assertSame(1, Account::query()->count());
        $this->assertSame('https://new.example.com', $existing->refresh()->website);

        $byEmail = $this->runImport($rep, [['name' => 'Another Name', 'email' => $existing->email]]);

        $this->assertSame(0, $byEmail->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $existing->getKey()]), $this->failedImportReason($byEmail, 'name', 'Another Name'));
    }

    #[Test]
    public function a_duplicate_outside_the_importers_scope_is_invisible_so_a_new_account_is_created(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Shared Name']);

        $import = $this->runImport($rep, [['name' => 'Shared Name']], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(2, Account::query()->where('name', 'Shared Name')->count());
    }

    #[Test]
    public function the_importer_carries_the_duplicate_strategy_option_and_translated_column_labels(): void
    {
        $components = AccountImporter::getOptionsFormComponents();

        $this->assertCount(1, $components);
        $this->assertInstanceOf(Select::class, $components[0]);
        $this->assertSame('duplicate_strategy', $components[0]->getName());

        foreach (AccountImporter::getColumns() as $column) {
            $this->assertSame(__('imports.columns.account.'.$column->getName()), $column->getLabel());
        }
    }

    #[Test]
    public function the_policy_verbs_gate_creating_updating_and_reassigning_through_the_import(): void
    {
        $team = $this->makeTeam();
        $teammate = $this->salesRep($team);
        $teammate->forceFill(['email' => 'teammate@example.com'])->save();
        $theirs = Account::factory()->create(['owner_id' => $teammate->getKey(), 'name' => 'Horizon Trading', 'website' => 'https://old.example.com']);
        $importer = $this->userWithPermissions($team, [Permission::AccountViewAny, Permission::AccountViewTeam, Permission::AccountImport]);

        $refused = $this->runImport($importer, [
            ['name' => 'Horizon Trading', 'website' => 'https://new.example.com'],
            ['name' => 'Fresh Co'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(0, $refused->successful_rows);
        $this->assertSame(__('imports.validation.update_forbidden', ['id' => (string) $theirs->getKey()]), $this->failedImportReason($refused, 'name', 'Horizon Trading'));
        $this->assertSame(__('imports.validation.create_forbidden'), $this->failedImportReason($refused, 'name', 'Fresh Co'));
        $this->assertSame('https://old.example.com', $theirs->refresh()->website);
        $this->assertSame(1, Account::query()->count());

        $importer->givePermissionTo(Permission::AccountCreate->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $handed = $this->runImport($importer->fresh() ?? $importer, [
            ['name' => 'Handed Co', 'owner' => 'teammate@example.com'],
            ['name' => 'Kept Co', 'owner' => $importer->email],
        ]);

        $this->assertSame(1, $handed->successful_rows);
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'teammate@example.com']), $this->failedImportReason($handed, 'name', 'Handed Co'));
        $this->assertFalse(Account::query()->where('name', 'Handed Co')->exists());
        $this->assertSame($importer->getKey(), Account::query()->where('name', 'Kept Co')->firstOrFail()->owner_id);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(User $user, array $rows, array $options = []): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->file_name = 'accounts.csv';
        $import->file_path = 'accounts.csv';
        $import->importer = AccountImporter::class;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'skip', ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }
}
