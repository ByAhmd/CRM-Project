<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields\Schemas;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldService;
use App\Services\CustomFields\CustomFieldValidator;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Create / edit a custom field definition (decision D-9).
 *
 * What the engine cannot change later is disabled here rather than merely
 * discouraged: the entity and the key from the first save, the type from the
 * first stored value. A disabled field is not dehydrated, so
 * CustomFieldService falls back to the stored value — and still refuses a
 * value smuggled past the disabled state.
 *
 * The choices repeater and the validation inputs follow the chosen type: only
 * the constraints the type can express are shown, and the pages send the two
 * bags on every save so switching type cannot leave a stale constraint behind.
 */
final class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('custom_fields.sections.definition'))
                    ->schema([
                        Select::make('entity')
                            ->label(__('custom_fields.fields.entity'))
                            ->options(CustomFieldEntity::class)
                            ->required()
                            ->native(false)
                            ->disabled(fn (?CustomField $record): bool => $record !== null)
                            ->dehydrated(fn (?CustomField $record): bool => $record === null),

                        TextInput::make('key')
                            ->label(__('custom_fields.fields.key'))
                            ->helperText(__('custom_fields.helpers.key'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->maxLength(50)
                            ->rules([
                                'regex:'.CustomField::KEY_PATTERN,
                                fn (Get $get, ?CustomField $record): object => Rule::unique('custom_fields', 'key')
                                    ->where('entity', self::entity($get, $record)?->value)
                                    ->ignore($record?->getKey()),
                                fn (Get $get, ?CustomField $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    $entity = self::entity($get, $record);

                                    if ($entity !== null && is_string($value) && in_array($value, CustomFieldService::reservedKeys($entity), true)) {
                                        $fail((string) __('custom_fields.validation.key_reserved', ['key' => $value]));
                                    }
                                },
                            ])
                            ->validationMessages([
                                'regex' => __('custom_fields.validation.key_format'),
                                'unique' => __('custom_fields.validation.key_taken'),
                            ])
                            ->disabled(fn (?CustomField $record): bool => $record !== null)
                            ->dehydrated(fn (?CustomField $record): bool => $record === null),

                        TextInput::make('label_ar')
                            ->label(__('custom_fields.fields.label_ar'))
                            ->required()
                            ->maxLength(100),

                        TextInput::make('label_en')
                            ->label(__('custom_fields.fields.label_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->maxLength(100),

                        Select::make('type')
                            ->label(__('custom_fields.fields.type'))
                            ->helperText(fn (?CustomField $record): ?string => self::typeLocked($record)
                                ? __('custom_fields.helpers.type_locked')
                                : null)
                            ->options(CustomFieldType::class)
                            ->required()
                            ->live()
                            ->native(false)
                            ->disabled(fn (?CustomField $record): bool => self::typeLocked($record))
                            ->dehydrated(fn (?CustomField $record): bool => ! self::typeLocked($record)),

                        Toggle::make('is_required')
                            ->label(__('custom_fields.fields.is_required'))
                            ->default(false),

                        Toggle::make('is_filterable')
                            ->label(__('custom_fields.fields.is_filterable'))
                            ->default(false),

                        Toggle::make('is_listed')
                            ->label(__('custom_fields.fields.is_listed'))
                            ->default(false),

                        Toggle::make('is_active')
                            ->label(__('custom_fields.fields.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('custom_fields.fields.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(1),

                Section::make(__('custom_fields.sections.options'))
                    ->description(__('custom_fields.helpers.options'))
                    ->visible(fn (Get $get, ?CustomField $record): bool => self::type($get, $record)?->hasOptions() ?? false)
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('value')
                                    ->label(__('custom_fields.fields.option_value'))
                                    ->extraInputAttributes(['dir' => 'ltr'])
                                    ->required()
                                    ->maxLength(100),

                                TextInput::make('label_ar')
                                    ->label(__('custom_fields.fields.option_label_ar'))
                                    ->required()
                                    ->maxLength(100),

                                TextInput::make('label_en')
                                    ->label(__('custom_fields.fields.option_label_en'))
                                    ->extraInputAttributes(['dir' => 'ltr'])
                                    ->required()
                                    ->maxLength(100),
                            ])
                            ->addActionLabel(__('custom_fields.actions.add_option'))
                            ->reorderable()
                            ->minItems(1)
                            ->required()
                            ->columns(1),
                    ])
                    ->columns(1),

                Section::make(__('custom_fields.sections.validation'))
                    ->visible(fn (Get $get, ?CustomField $record): bool => self::constraints($get, $record) !== [])
                    ->schema([
                        TextInput::make('validation.min')
                            ->label(__('custom_fields.fields.min'))
                            ->numeric()
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'min')),

                        TextInput::make('validation.max')
                            ->label(__('custom_fields.fields.max'))
                            ->numeric()
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'max')),

                        TextInput::make('validation.step')
                            ->label(__('custom_fields.fields.step'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'step')),

                        TextInput::make('validation.min_length')
                            ->label(__('custom_fields.fields.min_length'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(CustomFieldValidator::TEXT_LENGTH)
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'min_length')),

                        TextInput::make('validation.max_length')
                            ->label(__('custom_fields.fields.max_length'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(CustomFieldValidator::TEXT_LENGTH)
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'max_length')),

                        TextInput::make('validation.regex')
                            ->label(__('custom_fields.fields.regex'))
                            ->helperText(__('custom_fields.helpers.regex'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->maxLength(CustomFieldValidator::PATTERN_LENGTH)
                            ->visible(fn (Get $get, ?CustomField $record): bool => self::allows($get, $record, 'regex')),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /** The type being edited: the live form state, or the stored one while the select is disabled. */
    private static function type(Get $get, ?CustomField $record): ?CustomFieldType
    {
        $value = $get('type');

        if ($value instanceof CustomFieldType) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return CustomFieldType::tryFrom($value);
        }

        return $record?->type;
    }

    private static function entity(Get $get, ?CustomField $record): ?CustomFieldEntity
    {
        $value = $get('entity');

        if ($value instanceof CustomFieldEntity) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return CustomFieldEntity::tryFrom($value);
        }

        return $record?->entity;
    }

    /**
     * @return list<string>
     */
    private static function constraints(Get $get, ?CustomField $record): array
    {
        $type = self::type($get, $record);

        return $type === null ? [] : CustomFieldValidator::allowedConstraints($type);
    }

    private static function allows(Get $get, ?CustomField $record, string $constraint): bool
    {
        return in_array($constraint, self::constraints($get, $record), true);
    }

    /** The type is frozen from the first stored value (D-9). */
    private static function typeLocked(?CustomField $record): bool
    {
        return $record?->hasValues() === true;
    }
}
