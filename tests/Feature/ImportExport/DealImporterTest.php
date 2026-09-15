<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Enums\Permission;
use App\Enums\StageKind;
use App\Filament\Imports\DealImporter;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Import;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The deal importer, driven through Filament's ImportCsv job with a prepared
 * Import row — exactly what the ImportAction dispatches (module 18).
 */
final class DealImporterTest extends TestCase
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
    public function a_valid_csv_imports_deals_with_the_account_contact_pipeline_stage_and_lookups_resolved(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'first_name' => 'Noura', 'last_name' => 'Alotaibi']);
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);

        $import = $this->runImport($rep, $this->importFixtureRows('deals.csv'));

        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $supply = Deal::query()->where('title', 'Server Supply')->firstOrFail();
        $this->assertSame($account->getKey(), $supply->account_id);
        $this->assertSame($contact->getKey(), $supply->contact_id);
        $this->assertSame('Sales', $supply->pipeline?->name_en);
        $this->assertSame('Proposal', $supply->stage?->name_en);
        $this->assertSame(DealStatus::Open, $supply->status);
        $this->assertSame('15000.50', $supply->amount);
        $this->assertSame(app(SettingsRepository::class)->currency(), $supply->currency);
        $this->assertSame(60, $supply->probability);
        $this->assertSame('2026-12-31', $supply->expected_close_date?->toDateString());
        $this->assertSame(ForecastCategory::Commit, $supply->forecast_category);
        $this->assertSame('Website', $supply->source?->name_en);
        $this->assertSame($rep->getKey(), $supply->owner_id);
        $this->assertSame($rep->getKey(), $supply->created_by);
        $this->assertSame(['VIP'], $supply->tags->pluck('name_en')->all());

        $migration = Deal::query()->where('title', 'Cloud Migration')->firstOrFail();
        $this->assertTrue($migration->pipeline?->isDefault());
        $this->assertTrue($migration->stage?->isDefault());
        $this->assertSame(StageKind::Open, $migration->stage->kind);
        $this->assertSame('0.00', $migration->amount);
        $this->assertSame(ForecastCategory::Pipeline, $migration->forecast_category);
        $this->assertNull($migration->contact_id);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::DealCreated->value,
            'subject_id' => $supply->getKey(),
            'causer_id' => $rep->getKey(),
        ]);
    }

    #[Test]
    public function invalid_rows_land_in_failed_rows_with_a_translated_reason_and_do_not_stop_the_import(): void
    {
        $rep = $this->salesRep();
        Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);

        $import = $this->runImport($rep, $this->importFixtureRows('deals_invalid.csv'));

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(7, $import->getFailedRowsCount());
        $this->assertTrue(Deal::query()->where('title', 'Good Deal')->exists());

        $this->assertSame(__('imports.validation.account_not_found', ['value' => '']), $this->failedImportReason($import, 'title', 'No Account'));
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Nonexistent Co']), $this->failedImportReason($import, 'title', 'Ghost Account'));
        $this->assertSame(__('imports.validation.stage_not_open', ['value' => 'Won']), $this->failedImportReason($import, 'title', 'Closed Stage'));
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'Moon']), $this->failedImportReason($import, 'title', 'Unknown Stage'));
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'Nobody Here']), $this->failedImportReason($import, 'title', 'Unknown Contact'));
        $this->assertSame(__('validation.max.numeric', ['attribute' => __('imports.columns.deal.probability'), 'max' => 100]), $this->failedImportReason($import, 'title', 'Bad Probability'));
        $this->assertSame(__('validation.date_format', ['attribute' => __('imports.columns.deal.expected_close_date'), 'format' => 'Y-m-d']), $this->failedImportReason($import, 'title', 'Bad Date'));
    }

    #[Test]
    public function a_stage_of_another_pipeline_is_refused(): void
    {
        $rep = $this->salesRep();
        Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);
        $enterprise = Pipeline::factory()->create(['name_en' => 'Enterprise', 'name_ar' => 'المنشآت', 'is_default' => false]);
        PipelineStage::factory()->create(['pipeline_id' => $enterprise->getKey(), 'name_en' => 'Discovery', 'name_ar' => 'الاستكشاف', 'kind' => StageKind::Open, 'is_default' => true]);

        $import = $this->runImport($rep, [
            ['title' => 'Wrong Pipeline', 'account' => 'Horizon Trading', 'stage' => 'Discovery'],
        ]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(__('imports.validation.stage_outside_pipeline', ['value' => 'Discovery']), $this->failedImportReason($import, 'title', 'Wrong Pipeline'));
    }

    #[Test]
    public function an_account_outside_the_importers_scope_cannot_carry_the_deal(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Far Away Co']);

        $import = $this->runImport($rep, [['title' => 'Out of reach', 'account' => 'Far Away Co']]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(__('imports.validation.account_not_found', ['value' => 'Far Away Co']), $this->failedImportReason($import, 'title', 'Out of reach'));
        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function a_duplicate_inside_scope_is_matched_on_title_and_account_and_skipped_or_updated_by_the_run_strategy(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);
        $existing = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'title' => 'Server Supply', 'amount' => 100]);
        $proposal = PipelineStage::query()->where('pipeline_id', $existing->pipeline_id)->where('name_en', 'Proposal')->firstOrFail();
        $row = ['title' => 'server supply', 'account' => 'Horizon Trading', 'amount' => '250', 'stage' => $proposal->name_en];

        $skipped = $this->runImport($rep, [$row]);

        $this->assertSame(0, $skipped->successful_rows);
        $this->assertSame(__('imports.validation.duplicate', ['id' => (string) $existing->getKey()]), $this->failedImportReason($skipped, 'title', 'server supply'));
        $this->assertSame(1, Deal::query()->count());

        $updated = $this->runImport($rep, [$row], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $updated->successful_rows);
        $this->assertSame(1, Deal::query()->count());
        $existing->refresh();
        $this->assertSame('250.00', $existing->amount);
        $this->assertTrue($existing->stage?->isDefault(), 'an existing deal keeps its stage: moves go through the workflow');
    }

    #[Test]
    public function a_duplicate_outside_the_importers_scope_is_invisible_so_a_new_deal_is_created(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $stranger = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);
        Deal::factory()->create(['owner_id' => $stranger->getKey(), 'account_id' => $account->getKey(), 'title' => 'Server Supply']);

        $import = $this->runImport($manager, [['title' => 'Server Supply', 'account' => 'Horizon Trading']], ['duplicate_strategy' => 'update']);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(2, Deal::query()->where('title', 'Server Supply')->count());
    }

    #[Test]
    public function the_importer_carries_the_duplicate_strategy_option_and_translated_column_labels(): void
    {
        $components = DealImporter::getOptionsFormComponents();

        $this->assertCount(1, $components);
        $this->assertInstanceOf(Select::class, $components[0]);
        $this->assertSame('duplicate_strategy', $components[0]->getName());

        foreach (DealImporter::getColumns() as $column) {
            $this->assertSame(__('imports.columns.deal.'.$column->getName()), $column->getLabel());
        }
    }

    #[Test]
    public function the_policy_verbs_gate_creating_updating_and_reassigning_through_the_import(): void
    {
        $team = $this->makeTeam();
        $teammate = $this->salesRep($team);
        $teammate->forceFill(['email' => 'teammate@example.com'])->save();
        $account = Account::factory()->create(['owner_id' => $teammate->getKey(), 'name' => 'Horizon Trading']);
        $theirs = Deal::factory()->create(['owner_id' => $teammate->getKey(), 'account_id' => $account->getKey(), 'title' => 'Server Supply', 'amount' => 100]);
        $importer = $this->userWithPermissions($team, [
            Permission::DealViewAny, Permission::DealViewTeam, Permission::DealImport,
            Permission::AccountViewAny, Permission::AccountViewTeam,
        ]);

        $refused = $this->runImport($importer, [
            ['title' => 'Server Supply', 'account' => 'Horizon Trading', 'amount' => '250'],
            ['title' => 'Fresh Deal', 'account' => 'Horizon Trading', 'amount' => '10'],
        ], ['duplicate_strategy' => 'update']);

        $this->assertSame(0, $refused->successful_rows);
        $this->assertSame(__('imports.validation.update_forbidden', ['id' => (string) $theirs->getKey()]), $this->failedImportReason($refused, 'title', 'Server Supply'));
        $this->assertSame(__('imports.validation.create_forbidden'), $this->failedImportReason($refused, 'title', 'Fresh Deal'));
        $this->assertSame('100.00', $theirs->refresh()->amount);
        $this->assertSame(1, Deal::query()->count());

        $importer->givePermissionTo(Permission::DealCreate->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $handed = $this->runImport($importer->fresh() ?? $importer, [
            ['title' => 'Handed Deal', 'account' => 'Horizon Trading', 'owner' => 'teammate@example.com'],
            ['title' => 'Kept Deal', 'account' => 'Horizon Trading', 'owner' => $importer->email],
        ]);

        $this->assertSame(1, $handed->successful_rows);
        $this->assertSame(__('imports.validation.owner_out_of_reach', ['value' => 'teammate@example.com']), $this->failedImportReason($handed, 'title', 'Handed Deal'));
        $this->assertFalse(Deal::query()->where('title', 'Handed Deal')->exists());
        $this->assertSame($importer->getKey(), Deal::query()->where('title', 'Kept Deal')->firstOrFail()->owner_id);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(User $user, array $rows, array $options = []): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->file_name = 'deals.csv';
        $import->file_path = 'deals.csv';
        $import->importer = DealImporter::class;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'skip', ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }
}
