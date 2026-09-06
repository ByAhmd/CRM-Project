<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\User;
use App\Services\CustomFields\CustomFieldValidator;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The integration surface (decision D-9): the components a definition becomes,
 * the round trip of a record's values, and the queries the columns and filters
 * produce.
 */
final class CustomFieldsSchemaTest extends TestCase
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

    #[Test]
    public function an_entity_without_definitions_gets_no_sections_columns_or_filters(): void
    {
        $this->assertNull(CustomFieldsSchema::formSection(CustomFieldEntity::Lead));
        $this->assertNull(CustomFieldsSchema::infolistSection(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::importColumns(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::exportColumns(CustomFieldEntity::Lead));
    }

    #[Test]
    public function an_inactive_definition_is_on_no_surface_at_all(): void
    {
        CustomField::factory()->inactive()->listed()->filterable()->create(['key' => 'retired_key']);

        $this->assertNull(CustomFieldsSchema::formSection(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead));
        $this->assertSame([], CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead));
    }

    #[Test]
    public function every_type_becomes_the_component_it_is_edited_with_and_carries_its_rules(): void
    {
        $expected = [
            CustomFieldType::Text->value => TextInput::class,
            CustomFieldType::Textarea->value => Textarea::class,
            CustomFieldType::Number->value => TextInput::class,
            CustomFieldType::Decimal->value => TextInput::class,
            CustomFieldType::Date->value => DatePicker::class,
            CustomFieldType::DateTime->value => DateTimePicker::class,
            CustomFieldType::Boolean->value => Toggle::class,
            CustomFieldType::Select->value => Select::class,
            CustomFieldType::MultiSelect->value => Select::class,
            CustomFieldType::Url->value => TextInput::class,
            CustomFieldType::Email->value => TextInput::class,
        ];

        $sort = 0;

        foreach (array_keys($expected) as $type) {
            CustomField::factory()->ofType(CustomFieldType::from($type))->create([
                'key' => $type.'_key',
                'sort' => $sort++,
            ]);
        }

        $section = CustomFieldsSchema::formSection(CustomFieldEntity::Lead);

        $this->assertNotNull($section);
        $this->assertSame(
            CustomFieldsSchema::STATE_PATH,
            Schema::make()->components([$section])->getComponents()[0]->getStatePath(),
        );

        $components = $section->getDefaultChildComponents();

        $this->assertCount(count($expected), $components);

        foreach (array_values($expected) as $index => $class) {
            $component = $components[$index];

            $this->assertInstanceOf($class, $component);
            $this->assertInstanceOf(Field::class, $component);

            $type = CustomFieldType::from(array_keys($expected)[$index]);
            $field = CustomField::query()->where('key', $type->value.'_key')->firstOrFail();

            $this->assertSame($type->value.'_key', $component->getName());

            // A single-choice Select derives its own `in` rule from the live
            // state of the form, so its rules can only be read inside a
            // rendered schema; every other component reads them standalone.
            if ($type === CustomFieldType::Select) {
                continue;
            }

            $applied = array_map(
                static fn (mixed $rule): mixed => is_object($rule) ? (string) $rule : $rule,
                $component->getValidationRules(),
            );

            foreach (CustomFieldValidator::rulesFor($field) as $rule) {
                $this->assertContains(is_object($rule) ? (string) $rule : $rule, $applied, 'rule of '.$type->value);
            }
        }
    }

    #[Test]
    public function a_records_values_survive_a_round_trip_through_the_form(): void
    {
        CustomField::factory()->create(['key' => 'note_key', 'sort' => 0]);
        CustomField::factory()->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 1]);
        CustomField::factory()->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 2]);
        CustomField::factory()->multiSelect(['one', 'two'])->create(['key' => 'tags_key', 'sort' => 3]);

        $lead = Lead::factory()->create();

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => [
                'note_key' => 'a note',
                'renewal_key' => '2026-05-09',
                'vip_key' => true,
                'tags_key' => ['one', 'two'],
            ],
        ], $this->actor);

        $state = CustomFieldsSchema::fillFormData($lead->fresh() ?? $lead)[CustomFieldsSchema::STATE_PATH];

        $this->assertSame('a note', $state['note_key']);
        $this->assertSame('2026-05-09', $state['renewal_key']);
        $this->assertTrue($state['vip_key']);
        $this->assertSame(['one', 'two'], $state['tags_key']);
    }

    #[Test]
    public function the_infolist_shows_a_value_the_way_a_reader_expects_it(): void
    {
        $select = CustomField::factory()->select(['one', 'two'])->create(['key' => 'band_key', 'sort' => 0]);
        $boolean = CustomField::factory()->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 1]);
        $date = CustomField::factory()->ofType(CustomFieldType::Date)->create(['key' => 'renewal_key', 'sort' => 2]);

        $lead = Lead::factory()->create();

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => [
                'band_key' => 'one',
                'vip_key' => false,
                'renewal_key' => '2026-05-09',
            ],
        ], $this->actor);

        $section = CustomFieldsSchema::infolistSection(CustomFieldEntity::Lead);

        $this->assertNotNull($section);
        $this->assertContainsOnlyInstancesOf(TextEntry::class, $section->getDefaultChildComponents());

        $this->assertSame($select->optionLabel('one'), CustomFieldsSchema::display($select, 'one'));
        $this->assertSame(__('custom_fields.values.no'), CustomFieldsSchema::display($boolean, false));
        $this->assertSame(__('custom_fields.values.yes'), CustomFieldsSchema::display($boolean, true));
        $this->assertSame('2026-05-09', CustomFieldsSchema::display($date, $lead->fresh()?->customField('renewal_key')));
        $this->assertNull(CustomFieldsSchema::display($select, null));
    }

    #[Test]
    public function only_listed_definitions_become_table_columns(): void
    {
        CustomField::factory()->listed()->create(['key' => 'shown_key', 'sort' => 0]);
        CustomField::factory()->create(['key' => 'hidden_key', 'sort' => 1]);

        $columns = CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead);

        $this->assertCount(1, $columns);
        $this->assertSame(CustomFieldsSchema::NAME_PREFIX.'shown_key', $columns[0]->getName());
        $this->assertTrue($columns[0]->isToggledHiddenByDefault());
    }

    #[Test]
    public function a_text_filter_narrows_the_list_to_the_matching_records(): void
    {
        CustomField::factory()->filterable()->create(['key' => 'note_key']);

        $matching = Lead::factory()->create();
        $other = Lead::factory()->create();

        CustomFieldsSchema::persist($matching, [CustomFieldsSchema::STATE_PATH => ['note_key' => 'riyadh expo']], $this->actor);
        CustomFieldsSchema::persist($other, [CustomFieldsSchema::STATE_PATH => ['note_key' => 'jeddah expo']], $this->actor);

        $filter = CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead)[0];

        $this->assertInstanceOf(Filter::class, $filter);

        $keys = $filter->apply(Lead::query(), ['value' => 'riyadh'])->pluck('id')->all();

        $this->assertSame([$matching->getKey()], $keys);
        $this->assertCount(2, $filter->apply(Lead::query(), ['value' => null])->get());
    }

    #[Test]
    public function a_choice_filter_and_a_range_filter_narrow_the_list(): void
    {
        CustomField::factory()->select(['one', 'two'])->filterable()->create(['key' => 'band_key', 'sort' => 0]);
        CustomField::factory()->ofType(CustomFieldType::Number)->filterable()->create(['key' => 'weight_key', 'sort' => 1]);

        $first = Lead::factory()->create();
        $second = Lead::factory()->create();

        CustomFieldsSchema::persist($first, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'one', 'weight_key' => 5]], $this->actor);
        CustomFieldsSchema::persist($second, [CustomFieldsSchema::STATE_PATH => ['band_key' => 'two', 'weight_key' => 50]], $this->actor);

        $filters = CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead);

        $this->assertInstanceOf(SelectFilter::class, $filters[0]);
        $this->assertInstanceOf(Filter::class, $filters[1]);

        $this->assertSame([$first->getKey()], $filters[0]->apply(Lead::query(), ['value' => 'one'])->pluck('id')->all());
        $this->assertSame([$second->getKey()], $filters[1]->apply(Lead::query(), ['from' => 10, 'to' => 100])->pluck('id')->all());
        $this->assertSame([$first->getKey()], $filters[1]->apply(Lead::query(), ['from' => null, 'to' => 10])->pluck('id')->all());
    }

    #[Test]
    public function a_listed_column_sorts_through_the_stored_value(): void
    {
        CustomField::factory()->listed()->create(['key' => 'note_key']);

        $a = Lead::factory()->create();
        $b = Lead::factory()->create();

        CustomFieldsSchema::persist($a, [CustomFieldsSchema::STATE_PATH => ['note_key' => 'zulu']], $this->actor);
        CustomFieldsSchema::persist($b, [CustomFieldsSchema::STATE_PATH => ['note_key' => 'alpha']], $this->actor);

        $column = CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead)[0];

        $ascending = $column->applySort(Lead::query(), 'asc')->pluck('id')->all();

        $this->assertSame([$b->getKey(), $a->getKey()], $ascending);
    }

    #[Test]
    public function an_import_cell_is_cast_to_the_shape_the_field_stores(): void
    {
        $boolean = CustomField::factory()->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 0]);
        $select = CustomField::factory()->select(['one', 'two'])->create(['key' => 'band_key', 'sort' => 1]);
        $multi = CustomField::factory()->multiSelect(['one', 'two'])->create(['key' => 'tags_key', 'sort' => 2]);
        $number = CustomField::factory()->ofType(CustomFieldType::Number)->create(['key' => 'weight_key', 'sort' => 3]);

        $this->assertTrue(CustomFieldsSchema::importValue($boolean, 'Yes'));
        $this->assertTrue(CustomFieldsSchema::importValue($boolean, 'نعم'));
        $this->assertFalse(CustomFieldsSchema::importValue($boolean, 'no'));
        $this->assertSame('one', CustomFieldsSchema::importValue($select, 'Option one'));
        $this->assertSame('one', CustomFieldsSchema::importValue($select, 'one'));
        $this->assertSame(['one', 'two'], CustomFieldsSchema::importValue($multi, 'Option one|two'));
        $this->assertSame(5, CustomFieldsSchema::importValue($number, ' 5 '));
        $this->assertNull(CustomFieldsSchema::importValue($number, '  '));

        $columns = CustomFieldsSchema::importColumns(CustomFieldEntity::Lead);

        $this->assertCount(4, $columns);
        $this->assertSame(CustomFieldsSchema::NAME_PREFIX.'vip_key', $columns[0]->getName());
        $this->assertTrue($columns[0]->castState('yes'));
        $this->assertSame($boolean->display_label, $columns[0]->getLabel());
    }

    #[Test]
    public function imported_values_are_persisted_against_the_saved_record(): void
    {
        CustomField::factory()->create(['key' => 'note_key']);

        $lead = Lead::factory()->create();

        CustomFieldsSchema::persistImported($lead, ['note_key' => 'from a csv'], $this->actor);

        $this->assertSame('from a csv', $lead->fresh()?->customField('note_key'));
    }

    #[Test]
    public function values_left_by_a_row_that_never_saved_are_not_written_onto_the_next_record(): void
    {
        CustomField::factory()->create(['key' => 'note_key']);

        $aborted = Lead::factory()->create();
        $next = Lead::factory()->create();

        $columns = CustomFieldsSchema::importColumns(CustomFieldEntity::Lead);

        $this->assertCount(1, $columns);

        // Filament reuses one importer instance for every row of a chunk; this
        // one does nothing but hold the record of the row being processed.
        $importer = new class(new Import, [], []) extends Importer
        {
            public function useRecord(Model $record): void
            {
                $this->record = $record;
            }

            /**
             * @return array<ImportColumn>
             */
            public static function getColumns(): array
            {
                return [];
            }

            public static function getCompletedNotificationBody(Import $import): string
            {
                return '';
            }
        };

        $column = $columns[0]->importer($importer);

        $fill = static function (Lead $record, mixed $state) use ($importer, $column): void {
            $importer->useRecord($record);
            $column->fillRecord($state);
        };

        // The first row collects a value and then aborts before its afterSave
        // hook, so its values are never drained.
        $fill($aborted, 'first row');

        $this->assertSame([], CustomFieldsSchema::importedValues($importer, $next));

        // And a row that is explicitly forgotten leaves nothing behind either.
        $fill($aborted, 'first row');
        CustomFieldsSchema::forgetImported($importer);

        $this->assertSame([], CustomFieldsSchema::importedValues($importer, $aborted));

        $fill($next, 'second row');

        $this->assertSame(['note_key' => 'second row'], CustomFieldsSchema::importedValues($importer, $next));
        $this->assertSame([], CustomFieldsSchema::importedValues($importer, $next));
    }

    #[Test]
    public function a_rep_cannot_import_values_onto_a_record_outside_their_scope(): void
    {
        CustomField::factory()->create(['key' => 'note_key']);

        $rep = $this->salesRep();
        $foreign = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->expectException(AuthorizationException::class);

        try {
            CustomFieldsSchema::persistImported($foreign, ['note_key' => 'not mine'], $rep);
        } finally {
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    #[Test]
    public function a_listed_column_reads_every_record_from_one_eager_loaded_relation(): void
    {
        CustomField::factory()->listed()->create(['key' => 'note_key', 'sort' => 0]);
        CustomField::factory()->listed()->ofType(CustomFieldType::Boolean)->create(['key' => 'vip_key', 'sort' => 1]);

        foreach (range(1, 4) as $index) {
            $lead = Lead::factory()->create();

            CustomFieldsSchema::persist($lead, [
                CustomFieldsSchema::STATE_PATH => ['note_key' => 'note '.$index, 'vip_key' => true],
            ], $this->actor);
        }

        $page = Livewire::actingAs($this->actor)->test(ListLeads::class)->instance();

        $this->assertInstanceOf(ListLeads::class, $page);

        $table = $page->getTable();
        $columns = CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead);
        $leads = CustomFieldsSchema::eagerLoad(Lead::query())->get();

        $this->assertCount(2, $columns);
        $this->assertCount(4, $leads);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        foreach ($leads as $lead) {
            foreach ($columns as $column) {
                $this->assertNotNull($column->table($table)->record($lead)->getState());
            }
        }

        $this->assertSame(0, $queries, 'the listed columns queried the database per cell');
    }

    #[Test]
    public function a_search_term_of_wildcards_matches_nothing_of_its_own_accord(): void
    {
        CustomField::factory()->filterable()->create(['key' => 'note_key']);

        $lead = Lead::factory()->create();

        CustomFieldsSchema::persist($lead, [CustomFieldsSchema::STATE_PATH => ['note_key' => 'riyadh expo']], $this->actor);

        $filter = CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead)[0];

        $this->assertInstanceOf(Filter::class, $filter);
        $this->assertSame([], $filter->apply(Lead::query(), ['value' => '%'])->pluck('id')->all());
        $this->assertSame([], $filter->apply(Lead::query(), ['value' => 'riyadh_expo'])->pluck('id')->all());
        $this->assertSame([$lead->getKey()], $filter->apply(Lead::query(), ['value' => 'riyadh expo'])->pluck('id')->all());
    }

    #[Test]
    public function a_number_input_carries_the_bounds_and_the_step_its_definition_configures(): void
    {
        CustomField::factory()->ofType(CustomFieldType::Number)->create([
            'key' => 'weight_key',
            'sort' => 0,
            'validation' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $section = CustomFieldsSchema::formSection(CustomFieldEntity::Lead);

        $this->assertNotNull($section);

        $component = $section->getDefaultChildComponents()[0];

        $this->assertInstanceOf(TextInput::class, $component);
        $this->assertSame(5.0, $component->getStep());
        $this->assertSame(5.0, $component->getMinValue());
        $this->assertSame(50.0, $component->getMaxValue());
    }

    #[Test]
    public function a_multiple_choice_survives_an_export_followed_by_an_import(): void
    {
        $field = CustomField::factory()->multiSelect(['riyadh', 'jeddah'])->create(['key' => 'cities_key']);

        $field->setAttribute('options', [
            ['value' => 'riyadh', 'label_ar' => 'الرياض، الوسطى', 'label_en' => 'Riyadh, Central'],
            ['value' => 'jeddah', 'label_ar' => 'جدة، الغربية', 'label_en' => 'Jeddah, Western'],
        ]);
        $field->save();

        $lead = Lead::factory()->create();

        CustomFieldsSchema::persist($lead, [
            CustomFieldsSchema::STATE_PATH => ['cities_key' => ['riyadh', 'jeddah']],
        ], $this->actor);

        // An exporter that does nothing but hold the record being written.
        $exporter = new class(new Export, [], []) extends Exporter
        {
            public function useRecord(Model $record): void
            {
                $this->record = $record;
            }

            /**
             * @return array<ExportColumn>
             */
            public static function getColumns(): array
            {
                return [];
            }

            public static function getCompletedNotificationBody(Export $export): string
            {
                return '';
            }
        };

        $exporter->useRecord($lead->fresh() ?? $lead);

        $cell = CustomFieldsSchema::exportColumns(CustomFieldEntity::Lead)[0]
            ->exporter($exporter)
            ->getState();

        $this->assertIsString($cell);
        $this->assertStringContainsString($field->optionLabel('riyadh'), $cell);
        $this->assertSame(['riyadh', 'jeddah'], CustomFieldsSchema::importValue($field->fresh() ?? $field, $cell));
    }

    #[Test]
    public function every_active_definition_becomes_an_export_column(): void
    {
        $note = CustomField::factory()->create(['key' => 'note_key', 'sort' => 0]);
        CustomField::factory()->inactive()->create(['key' => 'retired_key', 'sort' => 1]);

        $columns = CustomFieldsSchema::exportColumns(CustomFieldEntity::Lead);

        $this->assertCount(1, $columns);
        $this->assertSame(CustomFieldsSchema::NAME_PREFIX.'note_key', $columns[0]->getName());
        $this->assertSame($note->display_label, $columns[0]->getLabel());
    }
}
