<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\CustomFields\CustomFieldRegistry;
use App\Services\CustomFields\CustomFieldValidator;
use App\Services\CustomFields\CustomFieldValueService;
use Closure;
use DateTimeInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use WeakMap;
use WeakReference;

/**
 * The one surface the rest of the panel uses to show custom fields (D-9).
 *
 * A resource does not learn anything about the engine: it asks for a form
 * section, an infolist section, its table columns and filters, its import and
 * export columns, and hands the submitted state back to be persisted. Adding a
 * field type or changing how a value is rendered happens here and nowhere
 * else.
 *
 * Everything is built from the *active* definitions of one entity, in sort
 * order; a deactivated field disappears from every surface at once while its
 * stored values stay in the database.
 *
 * Validation is never invented here: every component carries the rules
 * CustomFieldValidator derives from the definition, and the service that
 * writes the values applies exactly the same rules again — the form is a
 * convenience, the service is the authority. Authorisation works the same way:
 * persist() and persistImported() go through CustomFieldValueService, which
 * authorises the actor against the record before anything is written.
 *
 * Integration notes — what a resource that wires this surface must do:
 *
 * 1. **Eager loading is mandatory.** Every listed column, infolist entry and
 *    export column reads a record's values, and reads them one query at a time
 *    unless the relation is loaded. Wrap the query of every list table
 *    (`->modifyQueryUsing(fn (Builder $query) => CustomFieldsSchema::eagerLoad($query))`),
 *    of the view page and of the exporter.
 * 2. **An importer resets the buffer per row.** Values collected from a row's
 *    cells are held against the importer instance until the record exists;
 *    Filament reuses one importer instance for every row of a chunk, so the
 *    importer must call forgetImported($this) from beforeValidate() (or
 *    beforeFill()) and persist with
 *    `CustomFieldsSchema::persistImported($this->record, CustomFieldsSchema::importedValues($this, $this->record), $actor)`
 *    from afterSave().
 */
final class CustomFieldsSchema
{
    /** The form state path the whole section lives under. */
    public const string STATE_PATH = 'custom_fields';

    /** Table columns, infolist entries and export columns are named with this prefix. */
    public const string NAME_PREFIX = 'custom_field_';

    /** How a multiple choice is written into one CSV cell, and read back out of it. */
    public const string LIST_SEPARATOR = ' | ';

    /**
     * Values collected from an importer's row, per importer instance, until
     * its afterSave hook persists them. A WeakMap so a finished importer takes
     * its buffer with it.
     *
     * Filament reuses one importer instance for every row of a chunk, so the
     * entry also remembers which record it was collected for: a row that never
     * reaches afterSave (a save that throws, a halted hook) can then not leak
     * its values onto the next row's record.
     *
     * The entry is `['record' => WeakReference to the record, 'values' => the
     * cells collected for it]`.
     *
     * @var ?WeakMap<object, array<string, mixed>>
     */
    private static ?WeakMap $importBuffer = null;

    /**
     * The custom fields of a record's form, or null when the entity has none —
     * a caller filters the null out of its schema.
     */
    public static function formSection(CustomFieldEntity $entity): ?Section
    {
        $fields = self::fields($entity);

        if ($fields === []) {
            return null;
        }

        $components = [];

        foreach ($fields as $field) {
            $components[] = self::formComponent($field);
        }

        return Section::make(__('custom_fields.sections.custom'))
            ->statePath(self::STATE_PATH)
            ->schema($components)
            ->columns(1);
    }

    /**
     * The record's stored values in the shape the form components expect,
     * ready to be merged into a page's fill data.
     *
     * The values are read in one query through the value service, which
     * authorises the read against the user when the page has one.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fillFormData(Model $record): array
    {
        $entity = CustomFieldRegistry::entityFor($record);

        if ($entity === null) {
            return [self::STATE_PATH => []];
        }

        $reader = auth()->user();
        $stored = app(CustomFieldValueService::class)->values($record, $reader instanceof User ? $reader : null);
        $state = [];

        foreach (self::fields($entity) as $field) {
            $key = (string) $field->getAttribute('key');
            $state[$key] = self::formState($field, $stored[$key] ?? null);
        }

        return [self::STATE_PATH => $state];
    }

    /**
     * The eager load every surface that reads values depends on: without it a
     * listed column, an infolist entry or an export column costs one query per
     * record and field. A query whose model carries no custom fields is
     * returned untouched, so a caller may apply this blindly.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function eagerLoad(Builder $query): Builder
    {
        return CustomFieldRegistry::entityFor($query->getModel()) === null
            ? $query
            : $query->with('customFieldValues.field');
    }

    /**
     * Writes the section's submitted state through the value service, which
     * validates it against the definitions and audits what changed.
     *
     * @param  array<string, mixed>  $formData
     */
    public static function persist(Model $record, array $formData, User $actor): void
    {
        $values = $formData[self::STATE_PATH] ?? null;

        if (! is_array($values)) {
            return;
        }

        app(CustomFieldValueService::class)->fill($record, $values, $actor);
    }

    /** The custom fields of a record's view page, or null when the entity has none. */
    public static function infolistSection(CustomFieldEntity $entity): ?Section
    {
        $fields = self::fields($entity);

        if ($fields === []) {
            return null;
        }

        $entries = [];

        foreach ($fields as $field) {
            $entries[] = self::infolistEntry($field);
        }

        return Section::make(__('custom_fields.sections.custom'))
            ->schema($entries)
            ->columns(1);
    }

    /**
     * One toggleable column per field the administrator marked as listed,
     * hidden until a user asks for it.
     *
     * @return list<TextColumn>
     */
    public static function tableColumns(CustomFieldEntity $entity): array
    {
        $columns = [];

        foreach (self::fields($entity) as $field) {
            if (! $field->is_listed) {
                continue;
            }

            $columns[] = self::tableColumn($field);
        }

        return $columns;
    }

    /**
     * One filter per field the administrator marked as filterable. Each
     * narrows the list through the values relation, so the base query — and
     * with it the actor's visibility scope (D-4) — is never replaced.
     *
     * @return list<BaseFilter>
     */
    public static function tableFilters(CustomFieldEntity $entity): array
    {
        $filters = [];

        foreach (self::fields($entity) as $field) {
            if (! $field->is_filterable) {
                continue;
            }

            $filters[] = self::tableFilter($field);
        }

        return $filters;
    }

    /**
     * One import column per active field. The cell is cast to the field's own
     * shape first (so option labels, "yes" and dates behave), validated with
     * the definition's rules, then remembered until the row is saved — values
     * cannot be written before the record has an id.
     *
     * @return list<ImportColumn>
     */
    public static function importColumns(CustomFieldEntity $entity): array
    {
        $columns = [];

        foreach (self::fields($entity) as $field) {
            $key = (string) $field->getAttribute('key');

            $columns[] = ImportColumn::make(self::NAME_PREFIX.$key)
                ->label($field->display_label)
                ->castStateUsing(static fn (mixed $state): mixed => self::importValue($field, $state))
                ->rules(CustomFieldValidator::rulesFor($field))
                ->ignoreBlankState()
                ->fillRecordUsing(static function (Importer $importer, Model $record, mixed $state) use ($key): void {
                    self::rememberImported($importer, $record, $key, $state);
                });
        }

        return $columns;
    }

    /**
     * A CSV cell in the shape the field stores: "yes"/"no" become booleans, a
     * choice may be given as its stored value or as either label, a multiple
     * choice is separated by `|` or `,`.
     */
    public static function importValue(CustomField $field, mixed $state): mixed
    {
        if ($state === null) {
            return null;
        }

        $text = is_string($state) ? trim($state) : $state;

        if ($text === '') {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Boolean => self::importBoolean($text),
            CustomFieldType::Select => self::importOption($field, $text),
            CustomFieldType::MultiSelect => self::importOptions($field, $text),
            CustomFieldType::Number => is_numeric($text) ? (int) $text : $text,
            CustomFieldType::Decimal => is_numeric($text) ? (float) $text : $text,
            default => $text,
        };
    }

    /**
     * Persists what an import row collected. The importer calls this from its
     * afterSave hook, when the record finally has an id.
     *
     * @param  array<string, mixed>  $values
     */
    public static function persistImported(Model $record, array $values, User $actor): void
    {
        if ($values === []) {
            return;
        }

        app(CustomFieldValueService::class)->fill($record, $values, $actor);
    }

    /**
     * The values an importer's row collected for this record, cleared as they
     * are read.
     *
     * The record is part of the question, not decoration: one importer instance
     * serves every row of a chunk, so values left behind by a row that never
     * reached its afterSave hook belong to another record and are dropped
     * rather than written onto this one.
     *
     * @return array<string, mixed>
     */
    public static function importedValues(object $importer, Model $record): array
    {
        $buffer = self::buffer();
        $entry = $buffer[$importer] ?? [];

        unset($buffer[$importer]);

        return self::belongsTo($entry, $record) ? self::bufferedValues($entry) : [];
    }

    /**
     * Drops whatever a row left in the buffer. An importer calls this before
     * every row (from beforeValidate() or beforeFill()), so a row that aborts
     * between filling and saving cannot hand its values to the next one.
     */
    public static function forgetImported(object $importer): void
    {
        $buffer = self::buffer();

        if ($buffer->offsetExists($importer)) {
            $buffer->offsetUnset($importer);
        }
    }

    /**
     * One export column per active field, rendered the way the record page
     * shows it.
     *
     * A multiple choice is joined with the same separator the importer splits
     * on first, so a label carrying a comma survives an export followed by an
     * import instead of arriving as two unknown choices.
     *
     * @return list<ExportColumn>
     */
    public static function exportColumns(CustomFieldEntity $entity): array
    {
        $columns = [];

        foreach (self::fields($entity) as $field) {
            $columns[] = ExportColumn::make(self::NAME_PREFIX.(string) $field->getAttribute('key'))
                ->label($field->display_label)
                ->state(static function (Model $record) use ($field): ?string {
                    $display = self::display($field, self::valueOf($record, $field));

                    return is_array($display) ? implode(self::LIST_SEPARATOR, $display) : $display;
                })
                // Values and option labels are typed by users: a cell starting
                // with = + - @ TAB or CR is neutralised (CWE-1236); numbers,
                // including sign-led ones, are left as they are.
                ->preventFormulaInjection();
        }

        return $columns;
    }

    /**
     * A stored value as a reader sees it: a label instead of an option value,
     * yes/no instead of a bit, a formatted date. Null when there is nothing to
     * show, so every surface can fall back to its own placeholder.
     */
    public static function display(CustomField $field, mixed $value): string|array|null
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Boolean => $value
                ? (string) __('custom_fields.values.yes')
                : (string) __('custom_fields.values.no'),
            CustomFieldType::Date => self::formatDate($value, 'Y-m-d'),
            CustomFieldType::DateTime => self::formatDate($value, 'Y-m-d H:i'),
            CustomFieldType::Select => $field->optionLabel((string) $value),
            CustomFieldType::MultiSelect => array_map(
                static fn (mixed $element): string => $field->optionLabel((string) $element),
                is_array($value) ? array_values($value) : [$value],
            ),
            default => (string) $value,
        };
    }

    /**
     * The active definitions of the entity, in presentation order.
     *
     * @return list<CustomField>
     */
    private static function fields(CustomFieldEntity $entity): array
    {
        return array_values(app(CustomFieldRegistry::class)->active($entity)->all());
    }

    private static function formComponent(CustomField $field): Field
    {
        $key = (string) $field->getAttribute('key');

        $component = match ($field->type) {
            CustomFieldType::Text => TextInput::make($key)->maxLength(CustomFieldValidator::maxLength($field)),
            CustomFieldType::Textarea => Textarea::make($key)->rows(3),
            CustomFieldType::Number => self::numericInput($field, $key, integer: true),
            CustomFieldType::Decimal => self::numericInput($field, $key, integer: false),
            CustomFieldType::Date => DatePicker::make($key)->native(false),
            CustomFieldType::DateTime => DateTimePicker::make($key)->seconds(false)->native(false),
            CustomFieldType::Boolean => Toggle::make($key),
            CustomFieldType::Select => Select::make($key)->options($field->optionLabels())->native(false),
            CustomFieldType::MultiSelect => Select::make($key)->options($field->optionLabels())->multiple()->native(false),
            CustomFieldType::Url => TextInput::make($key)->url()->extraInputAttributes(['dir' => 'ltr'])->maxLength(CustomFieldValidator::maxLength($field)),
            CustomFieldType::Email => TextInput::make($key)->email()->extraInputAttributes(['dir' => 'ltr'])->maxLength(CustomFieldValidator::maxLength($field)),
        };

        return $component
            ->label($field->display_label)
            ->helperText(self::hint($field))
            ->required($field->is_required && $field->type !== CustomFieldType::Boolean)
            ->rules(CustomFieldValidator::rulesFor($field));
    }

    /**
     * A number input carrying the constraints the administrator configured:
     * the same `min`, `max` and `step` the hint promises and the rules enforce,
     * so the browser answers before the round trip instead of after it.
     */
    private static function numericInput(CustomField $field, string $key, bool $integer): TextInput
    {
        $input = TextInput::make($key);
        $input = $integer ? $input->integer() : $input->numeric();

        // `integer()` and `numeric()` set a step of their own, so a configured
        // one is applied after them or it would be overwritten.
        $step = $field->constraint('step');
        $min = $field->constraint('min');
        $max = $field->constraint('max');

        if (is_numeric($step)) {
            $input = $input->step((float) $step);
        }

        if (is_numeric($min)) {
            $input = $input->minValue((float) $min);
        }

        if (is_numeric($max)) {
            $input = $input->maxValue((float) $max);
        }

        return $input;
    }

    private static function infolistEntry(CustomField $field): TextEntry
    {
        $entry = TextEntry::make(self::NAME_PREFIX.(string) $field->getAttribute('key'))
            ->label($field->display_label)
            ->state(static fn (Model $record): string|array|null => self::display($field, self::valueOf($record, $field)))
            ->placeholder(__('common.placeholders.empty'));

        if ($field->type === CustomFieldType::MultiSelect) {
            return $entry->badge()->color('gray');
        }

        if ($field->type === CustomFieldType::Url) {
            return $entry
                ->extraAttributes(['dir' => 'ltr'])
                ->url(static fn (Model $record): ?string => self::stringValue($record, $field))
                ->openUrlInNewTab();
        }

        if ($field->type === CustomFieldType::Email) {
            $entry->extraAttributes(['dir' => 'ltr']);

            return $entry->url(static function (Model $record) use ($field): ?string {
                $value = self::stringValue($record, $field);

                return $value === null ? null : 'mailto:'.$value;
            });
        }

        return $entry;
    }

    private static function tableColumn(CustomField $field): TextColumn
    {
        $column = TextColumn::make(self::NAME_PREFIX.(string) $field->getAttribute('key'))
            ->label($field->display_label)
            ->state(static fn (Model $record): string|array|null => self::display($field, self::valueOf($record, $field)))
            ->placeholder(__('common.placeholders.empty'))
            ->toggleable(isToggledHiddenByDefault: true)
            ->sortable(query: static fn (Builder $query, string $direction): Builder => self::orderByValue($query, $field, $direction));

        return $field->type === CustomFieldType::MultiSelect
            ? $column->badge()->color('gray')
            : $column;
    }

    /**
     * Sorting on a value without a join: a correlated subquery reads the one
     * typed column of the one row that belongs to this record, so the table's
     * own query — filters, scope, pagination — is untouched.
     */
    private static function orderByValue(Builder $query, CustomField $field, string $direction): Builder
    {
        $model = $query->getModel();

        return $query->orderBy(
            CustomFieldValue::query()
                ->select('custom_field_values.'.$field->valueColumn())
                ->whereColumn('custom_field_values.entity_id', $model->getTable().'.'.$model->getKeyName())
                ->where('custom_field_values.entity_type', $model->getMorphClass())
                ->where('custom_field_values.custom_field_id', $field->getKey())
                ->limit(1)
                ->getQuery(),
            $direction,
        );
    }

    private static function tableFilter(CustomField $field): BaseFilter
    {
        $key = (string) $field->getAttribute('key');
        $name = self::NAME_PREFIX.$key;
        $column = $field->valueColumn();

        return match ($field->type) {
            CustomFieldType::Select => SelectFilter::make($name)
                ->label($field->display_label)
                ->options($field->optionLabels())
                ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? self::narrow($query, $field, static fn (Builder $inner): Builder => $inner->where($column, $data['value']))
                    : $query),

            CustomFieldType::MultiSelect => SelectFilter::make($name)
                ->label($field->display_label)
                ->options($field->optionLabels())
                ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? self::narrow($query, $field, static fn (Builder $inner): Builder => $inner->whereJsonContains($column, $data['value']))
                    : $query),

            CustomFieldType::Boolean => SelectFilter::make($name)
                ->label($field->display_label)
                ->options([
                    '1' => __('custom_fields.values.yes'),
                    '0' => __('custom_fields.values.no'),
                ])
                ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? self::narrow($query, $field, static fn (Builder $inner): Builder => $inner->where($column, (bool) $data['value']))
                    : $query),

            CustomFieldType::Number, CustomFieldType::Decimal => Filter::make($name)
                ->schema([
                    TextInput::make('from')->label(__('custom_fields.filters.from'))->numeric(),
                    TextInput::make('to')->label(__('custom_fields.filters.to'))->numeric(),
                ])
                ->query(static fn (Builder $query, array $data): Builder => self::between($query, $field, $column, $data))
                ->indicateUsing(static fn (array $data): array => self::rangeIndicators($field, $data)),

            CustomFieldType::Date, CustomFieldType::DateTime => Filter::make($name)
                ->schema([
                    DatePicker::make('from')->label(__('custom_fields.filters.from'))->native(false),
                    DatePicker::make('to')->label(__('custom_fields.filters.to'))->native(false),
                ])
                ->query(static fn (Builder $query, array $data): Builder => self::betweenDates($query, $field, $column, $data))
                ->indicateUsing(static fn (array $data): array => self::rangeIndicators($field, $data)),

            default => Filter::make($name)
                ->schema([
                    TextInput::make('value')->label($field->display_label),
                ])
                ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? self::narrow($query, $field, static fn (Builder $inner): Builder => $inner->where(
                        $column,
                        'like',
                        '%'.self::escapeLike((string) $data['value']).'%',
                    ))
                    : $query)
                ->indicateUsing(static fn (array $data): array => filled($data['value'] ?? null)
                    ? [$field->display_label.': '.$data['value']]
                    : []),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function between(Builder $query, CustomField $field, string $column, array $data): Builder
    {
        return $query
            ->when(filled($data['from'] ?? null), static fn (Builder $query): Builder => self::narrow(
                $query,
                $field,
                static fn (Builder $inner): Builder => $inner->where($column, '>=', $data['from']),
            ))
            ->when(filled($data['to'] ?? null), static fn (Builder $query): Builder => self::narrow(
                $query,
                $field,
                static fn (Builder $inner): Builder => $inner->where($column, '<=', $data['to']),
            ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function betweenDates(Builder $query, CustomField $field, string $column, array $data): Builder
    {
        return $query
            ->when(filled($data['from'] ?? null), static fn (Builder $query): Builder => self::narrow(
                $query,
                $field,
                static fn (Builder $inner): Builder => $inner->whereDate($column, '>=', $data['from']),
            ))
            ->when(filled($data['to'] ?? null), static fn (Builder $query): Builder => self::narrow(
                $query,
                $field,
                static fn (Builder $inner): Builder => $inner->whereDate($column, '<=', $data['to']),
            ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function rangeIndicators(CustomField $field, array $data): array
    {
        $indicators = [];

        if (filled($data['from'] ?? null)) {
            $indicators[] = $field->display_label.' '.__('custom_fields.filters.from').': '.$data['from'];
        }

        if (filled($data['to'] ?? null)) {
            $indicators[] = $field->display_label.' '.__('custom_fields.filters.to').': '.$data['to'];
        }

        return $indicators;
    }

    /**
     * A search term as a literal inside a LIKE pattern: `%` and `_` are the
     * pattern's own wildcards, so a user typing one means the character, not
     * "anything".
     */
    private static function escapeLike(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * Narrows the list to the records whose value of this field satisfies the
     * condition. Always an existence test on the values relation, never a
     * join, so the filter composes with everything else on the table.
     *
     * @param  Closure(Builder): Builder  $condition
     */
    private static function narrow(Builder $query, CustomField $field, Closure $condition): Builder
    {
        return $query->whereHas('customFieldValues', static function (Builder $inner) use ($field, $condition): void {
            $condition($inner->where('custom_field_id', $field->getKey()));
        });
    }

    /** The stored value of one field on one record, from the loaded relation when there is one. */
    private static function valueOf(Model $record, CustomField $field): mixed
    {
        if ($record->relationLoaded('customFieldValues')) {
            $loaded = $record->getRelation('customFieldValues');

            if ($loaded instanceof Collection) {
                foreach ($loaded as $row) {
                    if ($row instanceof CustomFieldValue && (int) $row->getAttribute('custom_field_id') === (int) $field->getKey()) {
                        return $row->setRelation('field', $field)->typedValue();
                    }
                }

                return null;
            }
        }

        $row = CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->where('entity_type', $record->getMorphClass())
            ->where('entity_id', $record->getKey())
            ->first();

        return $row?->setRelation('field', $field)->typedValue();
    }

    private static function stringValue(Model $record, CustomField $field): ?string
    {
        $value = self::valueOf($record, $field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A stored value in the shape its form component reads. */
    private static function formState(CustomField $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Date => self::formatDate($value, 'Y-m-d'),
            CustomFieldType::DateTime => self::formatDate($value, 'Y-m-d H:i'),
            CustomFieldType::Boolean => (bool) $value,
            CustomFieldType::MultiSelect => is_array($value) ? array_values($value) : [$value],
            default => $value,
        };
    }

    private static function formatDate(mixed $value, string $format): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format($format);
        }

        return (string) $value;
    }

    private static function importBoolean(mixed $state): mixed
    {
        if (is_bool($state)) {
            return $state;
        }

        $text = mb_strtolower(trim((string) $state));

        if (in_array($text, ['1', 'true', 'yes', 'y', 'نعم'], true)) {
            return true;
        }

        if (in_array($text, ['0', 'false', 'no', 'n', 'لا'], true)) {
            return false;
        }

        return $state;
    }

    /** A choice given as its stored value, its Arabic label or its English label. */
    private static function importOption(CustomField $field, mixed $state): mixed
    {
        $text = trim((string) $state);

        foreach ($field->options ?? [] as $option) {
            $value = (string) ($option['value'] ?? '');

            foreach ([$value, (string) ($option['label_ar'] ?? ''), (string) ($option['label_en'] ?? '')] as $candidate) {
                if ($candidate !== '' && mb_strtolower($candidate) === mb_strtolower($text)) {
                    return $value;
                }
            }
        }

        return $state;
    }

    /**
     * The choices of one cell. `|` is the separator the exporter writes, so it
     * wins whenever the cell carries one; a comma is only a fallback for a
     * hand-written file, and a label containing a comma is therefore not torn
     * apart by its own punctuation.
     *
     * @return list<mixed>
     */
    private static function importOptions(CustomField $field, mixed $state): array
    {
        if (is_array($state)) {
            return array_values($state);
        }

        $text = (string) $state;
        $parts = str_contains($text, '|') ? explode('|', $text) : explode(',', $text);
        $values = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '') {
                $values[] = self::importOption($field, $part);
            }
        }

        return $values;
    }

    private static function rememberImported(object $importer, Model $record, string $key, mixed $state): void
    {
        $buffer = self::buffer();
        $entry = $buffer[$importer] ?? [];
        $values = self::belongsTo($entry, $record) ? self::bufferedValues($entry) : [];
        $values[$key] = $state;

        $buffer[$importer] = ['record' => WeakReference::create($record), 'values' => $values];
    }

    /**
     * Whether a buffer entry was collected for exactly this record instance —
     * the identity, not the key, because a row is filled before it has one.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function belongsTo(array $entry, Model $record): bool
    {
        $reference = $entry['record'] ?? null;

        return $reference instanceof WeakReference && $reference->get() === $record;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function bufferedValues(array $entry): array
    {
        $values = $entry['values'] ?? null;

        return is_array($values) ? $values : [];
    }

    /**
     * @return WeakMap<object, array<string, mixed>>
     */
    private static function buffer(): WeakMap
    {
        return self::$importBuffer ??= new WeakMap;
    }

    /** The configured constraints as a line of help under the input. */
    private static function hint(CustomField $field): ?string
    {
        $parts = [];

        foreach (['min', 'max', 'min_length', 'max_length', 'step', 'regex'] as $constraint) {
            $value = $field->constraint($constraint);

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = __('custom_fields.fields.'.$constraint).': '.(is_scalar($value) ? (string) $value : '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
