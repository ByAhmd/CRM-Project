<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadSources\Schemas;

use App\Models\LeadSource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class LeadSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lead_sources.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('lead_sources.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?LeadSource $record): object => Rule::unique('lead_sources', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('lead_sources.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('lead_sources.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?LeadSource $record): object => Rule::unique('lead_sources', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('lead_sources.validation.name_unique')]),

                        Toggle::make('is_active')
                            ->label(__('lead_sources.fields.is_active'))
                            ->helperText(__('lead_sources.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('lead_sources.fields.sort'))
                            ->helperText(__('lead_sources.helpers.sort'))
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
