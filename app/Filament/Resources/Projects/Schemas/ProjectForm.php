<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\BadgeColor;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * The project form (decision D-30): both names, the badge colour, the order
 * and the two flags. The default and active rules are ProjectService's; the
 * form only shows the refusal beside the toggle it concerns.
 */
final class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('projects.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('projects.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Project $record): object => Rule::unique('projects', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('projects.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('projects.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Project $record): object => Rule::unique('projects', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('projects.validation.name_unique')]),

                        Select::make('color')
                            ->label(__('projects.fields.color'))
                            ->helperText(__('projects.helpers.color'))
                            ->options(BadgeColor::class)
                            ->default(BadgeColor::Gray->value)
                            ->required()
                            ->native(false),

                        TextInput::make('sort')
                            ->label(__('projects.fields.sort'))
                            ->helperText(__('projects.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),

                        Toggle::make('is_active')
                            ->label(__('projects.fields.is_active'))
                            ->helperText(__('projects.helpers.is_active'))
                            ->default(true),

                        Toggle::make('is_default')
                            ->label(__('projects.fields.is_default'))
                            ->helperText(__('projects.helpers.is_default'))
                            ->default(false),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }
}
