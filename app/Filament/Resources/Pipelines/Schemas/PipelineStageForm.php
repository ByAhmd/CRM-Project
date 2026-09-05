<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Schemas;

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * The stage form shown inside the pipeline's stages relation manager. Names
 * are unique within the owning pipeline, not globally.
 */
final class PipelineStageForm
{
    public static function configure(Schema $schema, Pipeline $pipeline): Schema
    {
        return $schema
            ->components([
                Section::make(__('pipelines.stages.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('pipelines.stages.fields.name_ar'))
                            ->placeholder(__('pipelines.stages.placeholders.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?PipelineStage $record): object => Rule::unique('pipeline_stages', 'name_ar')
                                    ->where('pipeline_id', $pipeline->getKey())
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('pipelines.stages.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('pipelines.stages.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->placeholder(__('pipelines.stages.placeholders.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?PipelineStage $record): object => Rule::unique('pipeline_stages', 'name_en')
                                    ->where('pipeline_id', $pipeline->getKey())
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('pipelines.stages.validation.name_unique')]),

                        Select::make('kind')
                            ->label(__('pipelines.stages.fields.kind'))
                            ->helperText(__('pipelines.stages.helpers.kind'))
                            ->options(StageKind::class)
                            ->default(StageKind::Open)
                            ->required()
                            ->native(false),

                        TextInput::make('probability')
                            ->label(__('pipelines.stages.fields.probability'))
                            ->helperText(__('pipelines.stages.helpers.probability'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->required()
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Select::make('color')
                            ->label(__('pipelines.stages.fields.color'))
                            ->helperText(__('pipelines.stages.helpers.color'))
                            ->options(BadgeColor::class)
                            ->default(BadgeColor::Primary)
                            ->required()
                            ->native(false),

                        Toggle::make('is_default')
                            ->label(__('pipelines.stages.fields.is_default'))
                            ->helperText(__('pipelines.stages.helpers.is_default'))
                            ->default(false),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }
}
