<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Schemas;

use App\Enums\BadgeColor;
use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('tags.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('tags.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Tag $record): object => Rule::unique('tags', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('tags.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('tags.fields.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Tag $record): object => Rule::unique('tags', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('tags.validation.name_unique')]),

                        Select::make('color')
                            ->label(__('tags.fields.color'))
                            ->helperText(__('tags.helpers.color'))
                            ->options(BadgeColor::class)
                            ->default(BadgeColor::Gray->value)
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label(__('tags.fields.is_active'))
                            ->helperText(__('tags.helpers.is_active'))
                            ->default(true),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }
}
