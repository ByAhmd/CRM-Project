<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\AccountType;
use App\Enums\ActivityKind;
use App\Enums\CompanySize;
use App\Enums\CrmRole;
use App\Enums\ForecastCategory;
use App\Enums\LeadPriority;
use App\Enums\LeadStatusKind;
use App\Enums\TaskPriority;
use App\Filament\Exports\AccountExporter;
use App\Filament\Exports\ActivityExporter;
use App\Filament\Exports\ContactExporter;
use App\Filament\Exports\DealExporter;
use App\Filament\Exports\LeadExporter;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Export;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The exporters' column mapping and cell formatting, and the scope of an
 * export launched from a table (module 18, decision D-13).
 */
final class ExportersTest extends TestCase
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
    public function every_exporter_labels_its_columns_from_the_language_files(): void
    {
        $exporters = [
            LeadExporter::class => 'lead',
            ContactExporter::class => 'contact',
            AccountExporter::class => 'account',
            DealExporter::class => 'deal',
            TaskExporter::class => 'task',
            ActivityExporter::class => 'activity',
        ];

        foreach ($exporters as $exporter => $entity) {
            $columns = $exporter::getColumns();

            $this->assertGreaterThan(10, count($columns), $exporter);

            $labels = array_map(fn (ExportColumn $column): ?string => $column->getLabel(), $columns);
            /** @var array<string, string> $dictionary */
            $dictionary = __('exports.columns.'.$entity);

            foreach ($labels as $label) {
                $this->assertIsString($label);
                $this->assertContains($label, $dictionary, "{$exporter} carries a label outside exports.columns.{$entity}");
            }

            $this->assertSame(array_unique(array_map(fn (ExportColumn $column): string => $column->getName(), $columns)), array_map(fn (ExportColumn $column): string => $column->getName(), $columns));
        }
    }

    #[Test]
    public function the_lead_exporter_renders_lookups_enums_tags_country_and_dates_in_the_run_locale(): void
    {
        $rep = $this->salesRep();
        $contacted = LeadStatus::query()->where('kind', LeadStatusKind::Working->value)->firstOrFail();
        $website = LeadSource::query()->where('name_en', 'Website')->firstOrFail();
        $lead = Lead::factory()->create([
            'owner_id' => $rep->getKey(),
            'lead_status_id' => $contacted->getKey(),
            'lead_source_id' => $website->getKey(),
            'priority' => LeadPriority::High,
            'country' => 'SA',
            'first_name' => '=SUM(A1)',
        ]);
        $lead->tags()->sync([
            Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء'])->getKey(),
            Tag::factory()->create(['name_en' => 'Enterprise', 'name_ar' => 'المنشآت'])->getKey(),
        ]);

        $english = $this->cells(LeadExporter::class, $lead->fresh(), $rep, 'en');

        $this->assertSame('Contacted', $english['status.display_name']);
        $this->assertSame('Website', $english['source.display_name']);
        $this->assertSame('High', $english['priority']);
        $this->assertSame('Saudi Arabia', $english['country']);
        $this->assertSame('VIP, Enterprise', $english['tags']);
        $this->assertSame($rep->name, $english['owner.name']);
        $this->assertSame((string) $lead->effective_score, $english['effective_score']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) $english['created_at']);
        $this->assertSame("'=SUM(A1)", $english['first_name'], 'free text is protected against formula injection');

        $arabic = $this->cells(LeadExporter::class, $lead->fresh(), $rep, 'ar');

        $this->assertSame($contacted->name_ar, $arabic['status.display_name']);
        $this->assertSame(__('enums.lead_priority.high', [], 'ar'), $arabic['priority']);
    }

    #[Test]
    public function every_exporter_renders_the_completion_notification_in_the_run_locale(): void
    {
        $export = new Export;
        $export->user()->associate($this->salesRep());
        $export->exporter = LeadExporter::class;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'test';
        $export->total_rows = 3;
        $export->successful_rows = 2;
        $export->save();

        $expected = [
            'en' => '2 rows exported. 1 row failed.',
            'ar' => 'صُدِّر صفّان. تعذّر تصدير صف واحد.',
        ];

        foreach ([LeadExporter::class, ContactExporter::class, AccountExporter::class, DealExporter::class, TaskExporter::class, ActivityExporter::class] as $exporter) {
            foreach (['en', 'ar'] as $locale) {
                $export->options([ImportExportActions::LOCALE_OPTION => $locale]);

                $this->assertSame(
                    $expected[$locale],
                    $exporter::getCompletedNotificationBody($export),
                    "{$exporter} renders the {$locale} completion body regardless of the worker's locale",
                );
            }
        }

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function the_completion_body_pluralises_the_counts_and_leaves_out_an_empty_failure_count(): void
    {
        $export = new Export;
        $export->user()->associate($this->salesRep());
        $export->exporter = LeadExporter::class;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'test';

        $cases = [
            // total, successful, en, ar
            [1, 1, '1 row exported.', 'صُدِّر صف واحد.'],
            [5, 5, '5 rows exported.', 'صُدِّرت 5 صفوف.'],
            [25, 25, '25 rows exported.', 'صُدِّر 25 صفاً.'],
            [150, 150, '150 rows exported.', 'صُدِّر 150 صف.'],
            [3, 0, 'No rows were exported. 3 rows failed.', 'لم يُصدَّر أي صف. تعذّر تصدير 3 صفوف.'],
        ];

        foreach ($cases as [$total, $successful, $english, $arabic]) {
            $export->total_rows = $total;
            $export->successful_rows = $successful;

            $export->options([ImportExportActions::LOCALE_OPTION => 'en']);
            $this->assertSame($english, LeadExporter::getCompletedNotificationBody($export));

            $export->options([ImportExportActions::LOCALE_OPTION => 'ar']);
            $this->assertSame($arabic, LeadExporter::getCompletedNotificationBody($export));
        }

        app()->setLocale((string) config('app.locale'));
    }

    #[Test]
    public function every_user_controlled_text_cell_is_protected_against_formula_injection_while_numbers_stay_numbers(): void
    {
        $payload = '@SUM(A1:A9)';
        $owner = $this->makeUser(CrmRole::SalesRep, ['name' => '=HYPERLINK("http://evil.example")']);
        $tag = Tag::factory()->create(['name_en' => '+cmd', 'name_ar' => '+cmd']);
        $lead = Lead::factory()->create([
            'owner_id' => $owner->getKey(),
            'email' => 'lead@example.com',
            'phone' => '+966551234567',
            'website' => $payload,
            'postal_code' => "\t=1+1",
        ]);
        $lead->tags()->sync([$tag->getKey()]);

        $cells = $this->cells(LeadExporter::class, $lead->fresh(), $owner, 'en');

        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $cells['owner.name']);
        $this->assertSame("'".$payload, $cells['website']);
        $this->assertSame("'\t=1+1", $cells['postal_code']);
        $this->assertSame("'+cmd", $cells['tags']);
        $this->assertSame('+966551234567', $cells['phone'], 'a sign-led number is a number, not a formula');
        $this->assertSame('lead@example.com', $cells['email']);
        $this->assertSame((string) $lead->getKey(), (string) $cells['id']);

        $task = Task::factory()->create(['assignee_id' => $owner->getKey(), 'created_by' => $owner->getKey()]);
        $taskCells = $this->cells(TaskExporter::class, $task->fresh(), $owner, 'en');

        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $taskCells['assignee.name']);
    }

    #[Test]
    public function the_deal_exporter_renders_money_probability_and_stage_names(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading']);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'first_name' => 'Noura', 'last_name' => 'Alotaibi']);
        $deal = Deal::factory()->create([
            'owner_id' => $rep->getKey(),
            'account_id' => $account->getKey(),
            'contact_id' => $contact->getKey(),
            'amount' => 15000.5,
            'probability' => 60,
            'forecast_category' => ForecastCategory::Commit,
            'expected_close_date' => '2026-12-31',
        ]);

        $cells = $this->cells(DealExporter::class, $deal->fresh(), $rep, 'en');

        $this->assertSame('Horizon Trading', $cells['account.name']);
        $this->assertSame('Noura Alotaibi', $cells['contact.full_name']);
        $this->assertSame('Sales', $cells['pipeline.display_name']);
        $this->assertSame($deal->stage?->name_en, $cells['stage.display_name']);
        $this->assertSame('Open', $cells['status']);
        $this->assertSame('15000.50', $cells['amount']);
        $this->assertSame('60', $cells['effective_probability']);
        $this->assertSame('9000.30', $cells['weighted_amount']);
        $this->assertSame('2026-12-31', $cells['expected_close_date']);
        $this->assertSame('Commit', $cells['forecast_category']);
    }

    #[Test]
    public function the_contact_account_task_and_activity_exporters_render_their_lookups_and_enums(): void
    {
        $rep = $this->salesRep();
        $industry = Industry::query()->where('name_en', 'Technology')->firstOrFail();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Horizon Trading', 'type' => AccountType::Customer, 'size' => CompanySize::From51To200, 'industry_id' => $industry->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'is_primary' => true, 'country' => 'AE']);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'priority' => TaskPriority::Urgent]);
        $activity = Activity::factory()->create(['owner_id' => $rep->getKey(), 'created_by' => $rep->getKey(), 'lead_id' => null, 'account_id' => $account->getKey()]);

        $contactCells = $this->cells(ContactExporter::class, $contact->fresh(), $rep, 'en');
        $this->assertSame('Horizon Trading', $contactCells['account.name']);
        $this->assertSame(__('exports.values.yes', [], 'en'), $contactCells['is_primary']);
        $this->assertSame('United Arab Emirates', $contactCells['country']);

        $accountCells = $this->cells(AccountExporter::class, $account->fresh(), $rep, 'en');
        $this->assertSame('Customer', $accountCells['type']);
        $this->assertSame('Technology', $accountCells['industry.display_name']);
        $this->assertSame(__('enums.company_size.51_200', [], 'en'), $accountCells['size']);

        $taskCells = $this->cells(TaskExporter::class, $task->fresh(), $rep, 'en');
        $this->assertSame('Task', $taskCells['kind']);
        $this->assertSame('Pending', $taskCells['status']);
        $this->assertSame('Urgent', $taskCells['priority']);
        $this->assertSame('Horizon Trading', $taskCells['related']);
        $this->assertSame($rep->name, $taskCells['assignee.name']);

        $activityCells = $this->cells(ActivityExporter::class, $activity->fresh(), $rep, 'en');
        $this->assertSame(ActivityKind::Call->getLabel(), $activityCells['kind']);
        $this->assertSame($activity->type?->name_en, $activityCells['type.display_name']);
        $this->assertSame('Horizon Trading', $activityCells['related']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) $activityCells['occurred_at']);
    }

    #[Test]
    public function a_rep_exporting_all_rows_gets_exactly_the_rows_inside_their_scope_in_both_formats_on_the_private_disk(): void
    {
        Storage::fake('local');
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Lead::factory()->count(2)->create(['owner_id' => $rep->getKey(), 'company_name' => 'Mine Ltd']);
        Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Theirs Ltd']);

        $this->actingAs($rep);
        $this->assertSame(2, LeadResource::getEloquentQuery()->count(), 'the table query the action exports is already scoped');

        foreach ([ImportExportActions::export(LeadExporter::class, Lead::class), ImportExportActions::exportBulk(LeadExporter::class, Lead::class)] as $action) {
            $this->assertNull(
                (new ReflectionProperty($action, 'modifyQueryUsing'))->getValue($action),
                'the export actions never set modifyQueryUsing: nothing can widen the scoped table query',
            );
        }

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();

        $this->assertSame($rep->getKey(), $export->user_id);
        $this->assertSame(LeadExporter::class, $export->exporter);
        $this->assertSame(ImportExportActions::FILE_DISK, $export->file_disk);
        $this->assertSame(2, $export->total_rows);
        $this->assertSame(2, $export->successful_rows);
        $this->assertNotNull($export->completed_at);

        $disk = Storage::disk('local');
        $directory = $export->getFileDirectory();
        $rows = [];

        foreach ($disk->files($directory) as $file) {
            if (str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv')) {
                $rows = [...$rows, ...array_filter(explode("\n", trim((string) $disk->get($file))))];
            }
        }

        $this->assertCount(2, $rows, 'the file holds the rep\'s two leads and nothing else');
        $this->assertStringContainsString('Mine Ltd', implode("\n", $rows));
        $this->assertStringNotContainsString('Theirs Ltd', implode("\n", $rows));
        $this->assertTrue($disk->exists($directory.'/headers.csv'));
        $this->assertTrue($disk->exists($directory.'/'.$export->file_name.'.xlsx'), 'the XLSX format is produced too');
        $this->assertTrue($export->hasFile());

        foreach ($mine as $lead) {
            $this->assertStringContainsString($lead->email, implode("\n", $rows));
        }

        $this->assertStringStartsWith(
            str_replace('\\', '/', storage_path('app/private')),
            str_replace('\\', '/', (string) config('filesystems.disks.local.root')),
        );
        $this->assertNull(config('filesystems.disks.local.url'), 'the private disk has no public URL');

        $served = str_replace('\\', '/', $directory).'/headers.csv';
        $this->get(route('storage.local', ['path' => $served]))
            ->assertForbidden();
        $this->get(URL::temporarySignedRoute('storage.local', now()->addMinute(), ['path' => $served], absolute: false))
            ->assertOk();
    }

    #[Test]
    public function the_bulk_export_action_exports_the_selected_rows_inside_scope_only(): void
    {
        Storage::fake('local');
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $selected = Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Selected Ltd']);
        Lead::factory()->create(['owner_id' => $rep->getKey(), 'company_name' => 'Unselected Ltd']);
        $theirs = Lead::factory()->create(['owner_id' => $other->getKey(), 'company_name' => 'Theirs Ltd']);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->selectTableRecords([$selected, $theirs])
            ->callAction(TestAction::make('export')->table()->bulk())
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $export->total_rows, 'a selected key outside the scope is dropped by the scoped table query');
        $this->assertSame(1, $export->successful_rows);

        $disk = Storage::disk('local');
        $content = '';

        foreach ($disk->files($export->getFileDirectory()) as $file) {
            if (str_ends_with($file, '.csv')) {
                $content .= (string) $disk->get($file);
            }
        }

        $this->assertStringContainsString('Selected Ltd', $content);
        $this->assertStringNotContainsString('Unselected Ltd', $content);
        $this->assertStringNotContainsString('Theirs Ltd', $content);
    }

    /**
     * The exporter's cells for one record, keyed by column name, rendered in
     * the given locale the way the queued job would render them.
     *
     * @param  class-string<Exporter>  $exporter
     * @return array<string, mixed>
     */
    private function cells(string $exporter, Model $record, User $user, string $locale): array
    {
        $export = new Export;
        $export->user()->associate($user);
        $export->exporter = $exporter;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'test';
        $export->total_rows = 1;
        $export->save();

        $columnMap = [];

        foreach ($exporter::getColumns() as $column) {
            $columnMap[$column->getName()] = (string) $column->getLabel();
        }

        $instance = new $exporter($export, $columnMap, [ImportExportActions::LOCALE_OPTION => $locale]);

        $cells = array_combine(array_keys($columnMap), $instance($record));

        app()->setLocale((string) config('app.locale'));

        return $cells;
    }
}
