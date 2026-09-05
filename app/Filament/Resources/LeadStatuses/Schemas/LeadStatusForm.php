<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses\Schemas;

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Models\LeadStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * The locked rules are reflected in the form (the Converted row keeps its
 * kind, the default row keeps its flag) so the administrator sees them
 * before submitting. A disabled field is not dehydrated and
 * LeadStatusService falls back to the stored value — and still refuses a
 * value smuggled past the disabled state.
 */
final class LeadStatusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lead_statuses.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('lead_statuses.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?LeadStatus $record): object => Rule::unique('lead_statuses', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('lead_statuses.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('lead_statuses.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?LeadStatus $record): object => Rule::unique('lead_statuses', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('lead_statuses.validation.name_unique')]),

                        Select::make('kind')
                            ->label(__('lead_statuses.fields.kind'))
                            ->helperText(__('lead_statuses.helpers.kind'))
                            ->options(LeadStatusKind::class)
                            ->required()
                            ->native(false)
                            ->disabled(fn (?LeadStatus $record): bool => $record?->isConverted() === true),

                        Select::make('color')
                            ->label(__('lead_statuses.fields.color'))
                            ->helperText(__('lead_statuses.helpers.color'))
                            ->options(BadgeColor::class)
                            ->default(BadgeColor::Gray->value)
                            ->required()
                            ->native(false),

                        Toggle::make('is_default')
                            ->label(__('lead_statuses.fields.is_default'))
                            ->helperText(__('lead_statuses.helpers.is_default'))
                            ->default(false)
                            ->disabled(fn (?LeadStatus $record): bool => $record?->isDefault() === true),

                        Toggle::make('is_active')
                            ->label(__('lead_statuses.fields.is_active'))
                            ->helperText(__('lead_statuses.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('lead_statuses.fields.sort'))
                            ->helperText(__('lead_statuses.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }
}
