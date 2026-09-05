<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Schemas;

use App\Models\Pipeline;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class PipelineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('pipelines.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('pipelines.fields.name_ar'))
                            ->placeholder(__('pipelines.placeholders.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Pipeline $record): object => Rule::unique('pipelines', 'name_ar')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                            ])
                            ->validationMessages(['unique' => __('pipelines.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('pipelines.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->placeholder(__('pipelines.placeholders.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Pipeline $record): object => Rule::unique('pipelines', 'name_en')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                            ])
                            ->validationMessages(['unique' => __('pipelines.validation.name_unique')]),

                        Toggle::make('is_default')
                            ->label(__('pipelines.fields.is_default'))
                            ->helperText(__('pipelines.helpers.is_default'))
                            ->default(false),

                        Toggle::make('is_active')
                            ->label(__('pipelines.fields.is_active'))
                            ->helperText(__('pipelines.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('pipelines.fields.sort'))
                            ->helperText(__('pipelines.helpers.sort'))
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
