<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Schemas;

use App\Models\Industry;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class IndustryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('industries.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('industries.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Industry $record): object => Rule::unique('industries', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('industries.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('industries.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Industry $record): object => Rule::unique('industries', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('industries.validation.name_unique')]),

                        Toggle::make('is_active')
                            ->label(__('industries.fields.is_active'))
                            ->helperText(__('industries.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('industries.fields.sort'))
                            ->helperText(__('industries.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }
}
