<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Enums\CrmRole;
use App\Enums\CustomFieldEntity;
use App\Filament\Exports\LeadExporter;
use App\Filament\Exports\Reports\ReportRowExporter;
use App\Filament\Exports\TaskExporter;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\CustomField;
use App\Models\Export;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Statistics\Reports\ReportRow;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: CSV / spreadsheet formula injection (CWE-1236).
 *
 * Every text cell a user can influence must be neutralised when it starts
 * with = + - @ TAB or CR. The exporters protect only a hand-picked subset of
 * columns: custom field values, user names (editable by any user on their
 * own profile), phone / email / website cells (importable as free text) and
 * tag / lookup names go out raw; the failed-rows CSV of an import is written
 * with Filament's protection switched off; the report exporter misses TAB
 * and CR.
 */
final class SpreadsheetFormulaInjectionProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const PAYLOAD = '=HYPERLINK("http://evil.example","x")';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    protected function tearDown(): void
    {
        app()->setLocale((string) config('app.locale'));

        parent::tearDown();
    }

    #[Test]
    public function a_text_custom_field_value_is_neutralised_in_the_lead_export(): void
    {
        $admin = $this->admin();
        CustomField::factory()->forEntity(CustomFieldEntity::Lead)->create(['key' => 'note_key', 'sort' => 0]);

        $lead = Lead::factory()->create(['owner_id' => $admin->getKey()]);
        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['note_key' => self::PAYLOAD]], $admin);

        $cells = $this->cells(LeadExporter::class, $lead->fresh() ?? $lead, $admin);

        $this->assertNotSame(self::PAYLOAD, $cells[CustomFieldsSchema::NAME_PREFIX.'note_key'], 'custom field cell exported as a live formula');
    }

    #[Test]
    public function user_controlled_cells_of_the_lead_export_are_neutralised(): void
    {
        // A user sets their own name on the profile page; phone arrives through the importer as free text.
        $owner = $this->makeUser(CrmRole::SalesRep, ['name' => self::PAYLOAD]);
        $lead = Lead::factory()->create([
            'owner_id' => $owner->getKey(),
            'phone' => '=1+2',
        ]);

        $cells = $this->cells(LeadExporter::class, $lead->fresh() ?? $lead, $owner);

        $this->assertNotSame(self::PAYLOAD, $cells['owner.name'], 'owner name exported as a live formula');
        $this->assertNotSame('=1+2', $cells['phone'], 'phone exported as a live formula');
    }

    #[Test]
    public function the_assignee_name_is_neutralised_in_the_task_export(): void
    {
        $assignee = $this->makeUser(CrmRole::SalesRep, ['name' => self::PAYLOAD]);
        $task = Task::factory()->create(['assignee_id' => $assignee->getKey(), 'created_by' => $assignee->getKey()]);

        $cells = $this->cells(TaskExporter::class, $task->fresh() ?? $task, $assignee);

        $this->assertNotSame(self::PAYLOAD, $cells['assignee.name'], 'assignee name exported as a live formula');
    }

    #[Test]
    public function the_failed_rows_csv_of_every_importer_is_protected(): void
    {
        foreach ([LeadImporter::class, ContactImporter::class, AccountImporter::class, DealImporter::class] as $importer) {
            $this->assertTrue($importer::shouldPreventFormulaInjection(), $importer.' writes its failed-rows CSV with formula cells intact');
        }
    }

    #[Test]
    public function the_report_exporter_neutralises_tab_and_carriage_return_prefixes(): void
    {
        $path = ReportRowExporter::write(
            ReportRowExporter::FORMAT_CSV,
            'Owner',
            ['leads' => 'Leads'],
            ['leads' => ReportRow::FORMAT_COUNT],
            new Collection([new ReportRow("\t=1+2", ['leads' => 1]), new ReportRow("\r=3+4", ['leads' => 2])]),
            null,
        );

        try {
            $content = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        $this->assertStringNotContainsString("\"\t=1+2\"", $content, 'a TAB-prefixed label is written unescaped');
        $this->assertStringNotContainsString("\"\r=3+4\"", $content, 'a CR-prefixed label is written unescaped');
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @return array<string, mixed>
     */
    private function cells(string $exporter, Model $record, User $user): array
    {
        $export = new Export;
        $export->user()->associate($user);
        $export->exporter = $exporter;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'probe';
        $export->total_rows = 1;
        $export->save();

        $columnMap = [];

        foreach ($exporter::getColumns() as $column) {
            $columnMap[$column->getName()] = (string) $column->getLabel();
        }

        $instance = new $exporter($export, $columnMap, [ImportExportActions::LOCALE_OPTION => 'en']);

        return array_combine(array_keys($columnMap), $instance($record));
    }
}
