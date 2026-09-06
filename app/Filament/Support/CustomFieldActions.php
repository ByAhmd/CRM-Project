<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\CustomFieldEntity;
use App\Models\CustomField;
use App\Models\User;
use App\Services\CustomFields\CustomFieldRegistry;
use App\Services\CustomFields\CustomFieldValidator;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use WeakMap;

/**
 * The wiring side of the custom field engine (decision D-9).
 *
 * CustomFieldsSchema builds the components; this class is everything a
 * *consumer* has to do around them, in one place instead of once per resource:
 * the guarded form section, the pre-fill of a modal action, holding the
 * submitted state until the record has an id, and the import columns whose
 * mapping is mandatory. The engine itself is never touched — every gap below
 * is marked ADAPTER so the engine owner can absorb it.
 *
 * All four entity forms, all four tables, all four list pages, the two account
 * relation managers and the four importers go through here, so a guard cannot
 * drift between entities.
 */
final class CustomFieldActions
{
    /**
     * The state a mounted action submitted, held between the mutation of the
     * record's own columns and the hook that writes it against the saved
     * record.
     *
     * Keyed by the action instance — Filament resolves one action per request
     * and hands the same instance to `mutateDataUsing()` and to `after()`, so a
     * WeakMap keeps the two halves together without a property on a shared
     * table or page class, and lets the entry die with the action.
     *
     * @var ?WeakMap<object, array<string, mixed>>
     */
    private static ?WeakMap $held = null;

    /**
     * The administrator's own fields (D-9) as a spreadable list, or nothing at
     * all when the entity carries no active definition.
     *
     * @return list<Section>
     */
    public static function formSection(CustomFieldEntity $entity): array
    {
        $section = CustomFieldsSchema::formSection($entity);

        if ($section === null) {
            return [];
        }

        $definitions = app(CustomFieldRegistry::class)->keyed($entity);

        foreach ($section->getDefaultChildComponents() as $component) {
            if (! $component instanceof Field) {
                continue;
            }

            $field = $definitions[$component->getName()] ?? null;

            if (! $field instanceof CustomField) {
                continue;
            }

            $component->validationMessages(self::validationMessages($field));

            // A DateTimePicker is the *parent* of DatePicker, so this matches
            // a "date" definition only — a "date and time" one already keeps
            // its raw state in a shape its own rule accepts.
            if ($component instanceof DatePicker) {
                $component->mutateStateForValidationUsing(static fn (mixed $state): mixed => self::validatableDate($state));
            }
        }

        return [$section];
    }

    /**
     * The record's stored values for a page's fill data, or nothing when the
     * entity carries no active definition.
     *
     * ADAPTER — the engine should absorb this: CustomFieldsSchema::fillFormData()
     * asks the value service for the values (an authorisation check plus a
     * select) and injects an empty `custom_fields` key before it discovers that
     * there is no section to fill, so a fresh installation pays that on every
     * edit page of all four entities. The guard belongs in fillFormData().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fillFormData(Model $record): array
    {
        $entity = CustomFieldRegistry::entityFor($record);

        if ($entity === null || app(CustomFieldRegistry::class)->active($entity)->isEmpty()) {
            return [];
        }

        return CustomFieldsSchema::fillFormData($record);
    }

    /**
     * Writes the state a form submitted against the saved record, as the user
     * who submitted it.
     *
     * @param  array<string, mixed>  $state
     */
    public static function persist(Model $record, array $state): void
    {
        $actor = auth()->user();

        if ($state === [] || ! $actor instanceof User) {
            return;
        }

        CustomFieldsSchema::persist($record, [CustomFieldsSchema::STATE_PATH => $state], $actor);
    }

    /**
     * Takes the custom field state out of an action's submitted data and holds
     * it until the record exists — the values are not columns of the record,
     * and handing them to `fill()` is a mass assignment error.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hold(object $action, array $data): array
    {
        $state = $data[CustomFieldsSchema::STATE_PATH] ?? null;
        $held = [];

        unset($data[CustomFieldsSchema::STATE_PATH]);

        if (is_array($state)) {
            foreach ($state as $key => $value) {
                $held[(string) $key] = $value;
            }
        }

        self::buffer()[$action] = $held;

        return $data;
    }

    /** Writes what hold() kept, once the action's record has an id. */
    public static function release(object $action, Model $record): void
    {
        $buffer = self::buffer();
        $state = $buffer[$action] ?? [];

        if ($buffer->offsetExists($action)) {
            $buffer->offsetUnset($action);
        }

        self::persist($record, $state);
    }

    /**
     * A modal create action built from an entity form: the custom section is
     * part of that form, so the action strips the state before the record is
     * filled and writes it once the record is saved.
     *
     * Filament keeps one `mutateDataUsing` and one `after` callback per action,
     * so a caller that needs its own hands them over instead of chaining them —
     * chaining would silently replace the ones this adapter installs.
     *
     * @param  ?Closure  $mutate  the caller's own data mutation, applied before the state is held
     * @param  ?Closure  $after  the caller's own after hook, run before the values are written
     */
    public static function createAction(CreateAction $action, ?Closure $mutate = null, ?Closure $after = null): CreateAction
    {
        $action->mutateDataUsing(static function (array $data) use ($action, $mutate): array {
            if ($mutate instanceof Closure) {
                $mutated = $action->evaluate($mutate, ['data' => $data]);
                $data = is_array($mutated) ? $mutated : $data;
            }

            return self::hold($action, $data);
        });

        return $action->after(static function (Model $record) use ($action, $after): void {
            if ($after instanceof Closure) {
                $action->evaluate($after);
            }

            self::release($action, $record);
        });
    }

    /**
     * A modal edit action built from an entity form: the stored values fill the
     * section, and the submitted ones are written after the record is updated.
     *
     * @param  ?Closure  $after  the caller's own after hook, run before the values are written
     */
    public static function editAction(EditAction $action, ?Closure $after = null): EditAction
    {
        $action
            ->mutateRecordDataUsing(static fn (array $data, Model $record): array => [...$data, ...self::fillFormData($record)])
            ->mutateDataUsing(static fn (array $data): array => self::hold($action, $data));

        return $action->after(static function (Model $record) use ($action, $after): void {
            if ($after instanceof Closure) {
                $action->evaluate($after);
            }

            self::release($action, $record);
        });
    }

    /**
     * The engine's import columns, with the mapping of a required definition
     * made mandatory for a row that creates a record.
     *
     * ADAPTER — the engine should absorb this (its own GAP-4): the value
     * service enforces every required definition on a record it has just seen
     * created, and the importer writes values from `afterSave()`, i.e. after
     * the record is already saved. Filament's import job wraps a whole chunk in
     * one transaction and rolls nothing back per row, so an unmapped required
     * column would otherwise leave a half-populated record live next to a
     * failed row. `requiredMappingForNewRecordsOnly()` moves the refusal ahead
     * of the save, where the row fails and nothing is written.
     *
     * @return list<ImportColumn>
     */
    public static function importColumns(CustomFieldEntity $entity): array
    {
        $required = [];

        foreach (app(CustomFieldRegistry::class)->active($entity) as $field) {
            if ($field->is_required) {
                $required[] = CustomFieldsSchema::NAME_PREFIX.(string) $field->getAttribute('key');
            }
        }

        return array_map(
            static fn (ImportColumn $column): ImportColumn => in_array($column->getName(), $required, true)
                ? $column->requiredMappingForNewRecordsOnly()
                : $column,
            CustomFieldsSchema::importColumns($entity),
        );
    }

    /**
     * The values every custom infolist entry of a record page reads, loaded in
     * one query instead of one per field.
     *
     * ADAPTER — the engine exposes eagerLoad() for a query only, while a record
     * page resolves a single model; the relations are therefore read off the
     * engine's own eager load rather than named here a second time.
     */
    public static function loadValues(Model $record): Model
    {
        $relations = array_keys(CustomFieldsSchema::eagerLoad($record->newQuery())->getEagerLoads());

        return $relations === [] ? $record : $record->loadMissing($relations);
    }

    /**
     * The engine's own validation messages keyed the way a Filament field wants
     * them — by rule name rather than by attribute — so the form refuses a
     * value with exactly the sentence the value service would.
     *
     * @return array<string, string>
     */
    private static function validationMessages(CustomField $field): array
    {
        $messages = [];

        foreach (CustomFieldValidator::messagesFor($field, 'value') as $attribute => $message) {
            $messages[Str::after($attribute, 'value.')] = $message;
        }

        return $messages;
    }

    /**
     * A date picker's raw state in the shape its rule reads.
     *
     * ADAPTER — the engine should absorb this: a "date" definition is validated
     * with `date_format:Y-m-d`, while a Filament date picker keeps its state in
     * the internal `Y-m-d H:i:s` shape and Filament validates that raw state.
     * The validator is therefore shown the day the picker stands for; the
     * dehydrated state the page persists is untouched and already carries
     * `Y-m-d`.
     *
     * Whatever cannot be read as a date is handed back exactly as it arrived:
     * the state is client controlled, so a value this adapter cannot parse must
     * reach the validator and be refused by `date_format:Y-m-d` with the
     * field's own label — never crash the request.
     */
    private static function validatableDate(mixed $state): mixed
    {
        if (! is_string($state) || $state === '') {
            return $state;
        }

        return rescue(
            static fn (): string => Carbon::parse($state)->format('Y-m-d'),
            $state,
            report: false,
        );
    }

    /**
     * @return WeakMap<object, array<string, mixed>>
     */
    private static function buffer(): WeakMap
    {
        return self::$held ??= new WeakMap;
    }
}
