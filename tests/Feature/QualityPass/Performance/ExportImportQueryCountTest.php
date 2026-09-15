<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Enums\CustomFieldEntity;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\CustomFieldsSchema;
use App\Jobs\RescoreLeads;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Leads\LeadScoringService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: an export, an import and the rescore job scale with chunks, not
 * with rows (plan section 8: "imports chunked, exports chunked").
 */
final class ExportImportQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->admin = $this->admin();
    }

    #[Test]
    public function exporting_fifty_deals_costs_no_more_queries_than_exporting_ten(): void
    {
        Storage::fake('local');

        $this->makeDeals(10);
        $small = $this->queriesDuring(fn () => $this->exportDeals());

        $this->makeDeals(40);
        $large = $this->queriesDuring(fn () => $this->exportDeals());

        $this->assertLessThanOrEqual(
            count($small) + 5,
            count($large),
            sprintf("DealExporter: %d queries for 10 rows, %d for 50 rows. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function importing_contacts_resolves_the_account_by_name_without_wrapping_the_indexed_column(): void
    {
        Storage::fake('local');
        Account::factory()->create(['owner_id' => $this->admin->getKey(), 'name' => 'Riyadh Trading']);

        $csv = UploadedFile::fake()->createWithContent(
            'contacts.csv',
            "first_name,last_name,email,account\nNora,Saleh,nora@example.com,Riyadh Trading\nOmar,Faisal,omar@example.com,riyadh trading\n",
        );

        $queries = $this->queriesDuring(fn () => Livewire::actingAs($this->admin)
            ->test(ListContacts::class)
            ->callAction('import', data: [
                'file' => $csv,
                'columnMap' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email',
                    'account' => 'account',
                ],
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors());

        $this->assertSame(2, Contact::query()->whereNotNull('account_id')->count(), 'the probe must reach the account lookup');

        $wrapped = array_values(array_filter($queries, static fn (string $sql): bool => (bool) preg_match('/LOWER\(\s*`?name`?\s*\)/i', $sql)));

        $this->assertSame(
            [],
            $wrapped,
            'the accounts.name index (accounts_name_index) cannot serve LOWER(name) = ?: every imported row scans the accounts table. The column collation is already case-insensitive.',
        );
    }

    #[Test]
    public function one_list_request_reads_the_custom_field_definitions_once(): void
    {
        Lead::factory()->count(3)->create(['owner_id' => $this->admin->getKey()]);
        $this->renderLeads();

        $definitions = $this->queriesTouching($this->queriesDuring(fn () => $this->renderLeads()), 'custom_fields');

        $this->assertLessThanOrEqual(
            1,
            count($definitions),
            sprintf("One ListLeads render read custom_fields %d times: CustomFieldsSchema::fields() and CustomFieldActions resolve a fresh CustomFieldRegistry per call, so its per-instance memo never survives.\n%s", count($definitions), $this->topRepeated($definitions)),
        );
    }

    #[Test]
    public function importing_rows_does_not_reload_the_custom_field_definitions_per_row(): void
    {
        Storage::fake('local');
        CustomField::factory()->create(['entity' => CustomFieldEntity::Lead, 'key' => 'expo']);

        $small = $this->queriesTouching($this->queriesDuring(fn () => $this->importLeads(2)), 'custom_fields');
        $large = $this->queriesTouching($this->queriesDuring(fn () => $this->importLeads(12)), 'custom_fields');

        $this->assertLessThanOrEqual(
            count($small) + 1,
            count($large),
            sprintf('custom_fields read %d times for 2 rows and %d times for 12 rows: CustomFieldRegistry is resolved per call, so its memo never survives.', count($small), count($large)),
        );
    }

    private function renderLeads(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->assertOk();
    }

    #[Test]
    public function the_rescore_job_costs_at_most_one_query_per_lead(): void
    {
        Lead::factory()->count(10)->create();
        $this->travel(5)->minutes();
        $small = $this->countQueries(fn () => (new RescoreLeads)->handle(app(LeadScoringService::class)));

        Lead::factory()->count(30)->create();
        $this->travel(5)->minutes();
        $large = $this->countQueries(fn () => (new RescoreLeads)->handle(app(LeadScoringService::class)));

        $this->assertLessThanOrEqual(
            $small + 30 + 2,
            $large,
            sprintf('RescoreLeads: %d queries for 10 leads, %d for 40 leads — the chunk selects ids only and then re-reads every lead with find().', $small, $large),
        );
    }

    private function exportDeals(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors();
    }

    private function makeDeals(int $count): void
    {
        $source = LeadSource::query()->orderBy('id')->firstOrFail();

        for ($i = 0; $i < $count; $i++) {
            $account = Account::factory()->create(['owner_id' => $this->admin->getKey()]);
            $contact = Contact::factory()->create(['owner_id' => $this->admin->getKey(), 'account_id' => $account->getKey()]);

            Deal::factory()->create([
                'owner_id' => $this->admin->getKey(),
                'account_id' => $account->getKey(),
                'contact_id' => $contact->getKey(),
                'lead_source_id' => $source->getKey(),
            ]);
        }
    }

    private function importLeads(int $rows): void
    {
        $lines = ['first_name,last_name,email,expo'];

        for ($i = 0; $i < $rows; $i++) {
            $lines[] = sprintf('Row%d,Person%d,row%d-%d@example.com,Riyadh expo', $i, $i, $i, random_int(1, PHP_INT_MAX));
        }

        Livewire::actingAs($this->admin)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', implode("\n", $lines)."\n"),
                'columnMap' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email',
                    CustomFieldsSchema::NAME_PREFIX.'expo' => 'expo',
                ],
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();
    }
}
