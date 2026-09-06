<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFieldsWiring;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\FailedImportRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Custom fields through the CSV importers and exporters (decision D-9): a
 * column per active definition, values written once the row's record exists,
 * and a cell that names an unknown choice refused with the field's own label.
 */
final class CustomFieldsImportExportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->actor = $this->admin();
    }

    protected function tearDown(): void
    {
        app()->setLocale((string) config('app.locale'));

        parent::tearDown();
    }

    #[Test]
    public function an_active_definition_becomes_an_import_column_of_every_entity(): void
    {
        $this->leadDefinitions();
        $this->contactDefinitions();

        $focus = $this->definition(CustomFieldEntity::Lead, 'focus_key');
        $names = array_map(
            static fn (ImportColumn $column): string => $column->getName(),
            LeadImporter::getColumns(),
        );

        $this->assertContains(CustomFieldsSchema::NAME_PREFIX.'focus_key', $names);
        $this->assertContains(CustomFieldsSchema::NAME_PREFIX.'band_key', $names);
        $this->assertNotContains(CustomFieldsSchema::NAME_PREFIX.'retired_key', $names);

        $this->assertContains(
            $focus->display_label,
            array_map(static fn (ImportColumn $column): ?string => $column->getLabel(), LeadImporter::getColumns()),
        );

        $contactNames = array_map(
            static fn (ImportColumn $column): string => $column->getName(),
            ContactImporter::getColumns(),
        );

        $this->assertContains(CustomFieldsSchema::NAME_PREFIX.'floor_key', $contactNames);
    }

    #[Test]
    public function a_lead_row_carries_its_custom_values_onto_the_saved_lead(): void
    {
        $this->leadDefinitions();

        $band = $this->definition(CustomFieldEntity::Lead, 'band_key');

        $import = $this->runImport(LeadImporter::class, [
            [
                'first_name' => 'Fahad',
                'last_name' => 'Alqahtani',
                'email' => 'fahad@example.com',
                CustomFieldsSchema::NAME_PREFIX.'focus_key' => 'Riyadh expo',
                CustomFieldsSchema::NAME_PREFIX.'band_key' => $band->optionLabel('one'),
                CustomFieldsSchema::NAME_PREFIX.'vip_key' => 'yes',
                CustomFieldsSchema::NAME_PREFIX.'seats_key' => '12',
            ],
            [
                'first_name' => 'Noura',
                'last_name' => 'Alotaibi',
                'email' => 'noura@example.com',
                CustomFieldsSchema::NAME_PREFIX.'focus_key' => 'Jeddah expo',
                CustomFieldsSchema::NAME_PREFIX.'band_key' => 'two',
                CustomFieldsSchema::NAME_PREFIX.'vip_key' => '',
                CustomFieldsSchema::NAME_PREFIX.'seats_key' => '',
            ],
        ]);

        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $fahad = Lead::query()->where('last_name', 'Alqahtani')->firstOrFail();

        $this->assertSame('Riyadh expo', $fahad->customField('focus_key'));
        $this->assertSame('one', $fahad->customField('band_key'));
        $this->assertTrue($fahad->customField('vip_key'));
        $this->assertSame(12, $fahad->customField('seats_key'));

        $noura = Lead::query()->where('last_name', 'Alotaibi')->firstOrFail();

        $this->assertSame('Jeddah expo', $noura->customField('focus_key'));
        $this->assertSame('two', $noura->customField('band_key'));
        $this->assertNull($noura->customField('vip_key'), 'a blank cell writes nothing');
        $this->assertNull($noura->customField('seats_key'));
    }

    #[Test]
    public function a_contact_row_carries_its_custom_values_onto_the_saved_contact(): void
    {
        $this->contactDefinitions();

        $import = $this->runImport(ContactImporter::class, [
            [
                'first_name' => 'Sara',
                'last_name' => 'Alotaibi',
                'email' => 'sara@example.com',
                CustomFieldsSchema::NAME_PREFIX.'floor_key' => 'Third floor',
            ],
        ]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $contact = Contact::query()->where('last_name', 'Alotaibi')->firstOrFail();

        $this->assertSame('Third floor', $contact->customField('floor_key'));
    }

    #[Test]
    public function a_cell_naming_an_unknown_choice_fails_the_row_with_the_fields_own_label(): void
    {
        $this->leadDefinitions();

        $import = $this->runImport(LeadImporter::class, [
            [
                'first_name' => 'Wrong',
                'last_name' => 'Choice',
                'email' => 'wrong@example.com',
                CustomFieldsSchema::NAME_PREFIX.'focus_key' => 'Riyadh expo',
                CustomFieldsSchema::NAME_PREFIX.'band_key' => 'nonexistent',
                CustomFieldsSchema::NAME_PREFIX.'vip_key' => '',
                CustomFieldsSchema::NAME_PREFIX.'seats_key' => '',
            ],
            [
                'first_name' => 'Right',
                'last_name' => 'Choice',
                'email' => 'right@example.com',
                CustomFieldsSchema::NAME_PREFIX.'focus_key' => 'Jeddah expo',
                CustomFieldsSchema::NAME_PREFIX.'band_key' => 'two',
                CustomFieldsSchema::NAME_PREFIX.'vip_key' => '',
                CustomFieldsSchema::NAME_PREFIX.'seats_key' => '',
            ],
        ], ['locale' => 'en']);

        $band = $this->definition(CustomFieldEntity::Lead, 'band_key');

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());
        // Filament names an import column's attribute after its label, with a
        // lower-cased first letter — a custom field's label is data (D-9), so
        // the reason names the field the administrator typed.
        $this->assertSame(
            __('validation.in', ['attribute' => Str::lcfirst($band->display_label)], 'en'),
            $this->failure($import, 'email', 'wrong@example.com'),
        );

        $this->assertFalse(Lead::query()->where('email_normalized', 'wrong@example.com')->exists());

        // The refused row is filled before it is saved, so the surviving row
        // must carry only its own cells.
        $right = Lead::query()->where('email_normalized', 'right@example.com')->firstOrFail();

        $this->assertSame('Jeddah expo', $right->customField('focus_key'));
        $this->assertSame('two', $right->customField('band_key'));
    }

    #[Test]
    public function the_lead_exporter_writes_a_column_per_definition_in_the_run_locale(): void
    {
        $this->leadDefinitions();

        $lead = Lead::factory()->create(['owner_id' => $this->actor->getKey()]);

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => [
                'focus_key' => 'Riyadh expo',
                'band_key' => 'one',
                'vip_key' => true,
                'seats_key' => 12,
            ],
        ], $this->actor);

        $names = array_map(
            static fn (ExportColumn $column): string => $column->getName(),
            LeadExporter::getColumns(),
        );

        $this->assertContains(CustomFieldsSchema::NAME_PREFIX.'focus_key', $names);
        $this->assertNotContains(CustomFieldsSchema::NAME_PREFIX.'retired_key', $names);

        foreach (['en', 'ar'] as $locale) {
            $cells = $this->cells(LeadExporter::class, $lead->fresh() ?? $lead, $locale);
            $band = $this->definition(CustomFieldEntity::Lead, 'band_key');

            $this->assertSame('Riyadh expo', $cells[CustomFieldsSchema::NAME_PREFIX.'focus_key']);
            $this->assertSame($band->optionLabel('one'), $cells[CustomFieldsSchema::NAME_PREFIX.'band_key']);
            $this->assertSame(__('custom_fields.values.yes'), $cells[CustomFieldsSchema::NAME_PREFIX.'vip_key']);
            $this->assertSame('12', $cells[CustomFieldsSchema::NAME_PREFIX.'seats_key']);
        }
    }

    #[Test]
    public function a_required_definition_whose_column_is_not_mapped_refuses_the_row_before_the_lead_is_created(): void
    {
        CustomField::factory()
            ->forEntity(CustomFieldEntity::Lead)
            ->required()
            ->create(['key' => 'focus_key', 'sort' => 0]);

        $import = $this->runImport(LeadImporter::class, [
            [
                'first_name' => 'Fahad',
                'last_name' => 'Alqahtani',
                'email' => 'fahad@example.com',
            ],
        ]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('custom_field_values', 0);
    }

    #[Test]
    public function a_required_definition_that_is_mapped_lets_the_row_through(): void
    {
        CustomField::factory()
            ->forEntity(CustomFieldEntity::Lead)
            ->required()
            ->create(['key' => 'focus_key', 'sort' => 0]);

        $import = $this->runImport(LeadImporter::class, [
            [
                'first_name' => 'Fahad',
                'last_name' => 'Alqahtani',
                'email' => 'fahad@example.com',
                CustomFieldsSchema::NAME_PREFIX.'focus_key' => 'Riyadh expo',
            ],
        ]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $lead = Lead::query()->where('last_name', 'Alqahtani')->firstOrFail();

        $this->assertSame('Riyadh expo', $lead->customField('focus_key'));
    }

    /** A text, a choice, a flag and a number on leads, plus a retired field. */
    private function leadDefinitions(): void
    {
        $factory = CustomField::factory()->forEntity(CustomFieldEntity::Lead);

        $factory->create(['key' => 'focus_key', 'sort' => 0]);
        $factory->select(['one', 'two'])->create(['key' => 'band_key', 'sort' => 1]);
        $factory->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 2]);
        $factory->ofType(CustomFieldType::Number)->create(['key' => 'seats_key', 'sort' => 3]);
        $factory->inactive()->create(['key' => 'retired_key', 'sort' => 4]);
    }

    private function contactDefinitions(): void
    {
        CustomField::factory()->forEntity(CustomFieldEntity::Contact)->create(['key' => 'floor_key', 'sort' => 0]);
    }

    private function definition(CustomFieldEntity $entity, string $key): CustomField
    {
        return CustomField::query()
            ->forEntity($entity)
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
     * One CSV run through the job the import action dispatches.
     *
     * @param  class-string<Importer>  $importer
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function runImport(string $importer, array $rows, array $options = []): Import
    {
        $import = new Import;
        $import->user()->associate($this->actor);
        $import->file_name = 'custom-fields.csv';
        $import->file_path = 'custom-fields.csv';
        $import->importer = $importer;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'skip', ...$options]))->handle();

        $import->touch('completed_at');

        return $import->refresh();
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

    /**
     * One exported row keyed by column name, written in the run's locale.
     *
     * @param  class-string<Exporter>  $exporter
     * @return array<string, mixed>
     */
    private function cells(string $exporter, Model $record, string $locale): array
    {
        $export = new Export;
        $export->user()->associate($this->actor);
        $export->exporter = $exporter;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'custom-fields';
        $export->total_rows = 1;
        $export->save();

        $columnMap = [];

        foreach ($exporter::getColumns() as $column) {
            $columnMap[$column->getName()] = (string) $column->getLabel();
        }

        $instance = new $exporter($export, $columnMap, [ImportExportActions::LOCALE_OPTION => $locale]);

        return array_combine(array_keys($columnMap), $instance($record));
    }
}
