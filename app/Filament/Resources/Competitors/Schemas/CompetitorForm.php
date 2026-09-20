<?php

declare(strict_types=1);

namespace App\Filament\Resources\Competitors\Schemas;

use App\Models\Competitor;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class CompetitorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('competitors.sections.details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('competitors.fields.name'))
                            ->placeholder(__('competitors.placeholders.name'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(150)
                            ->rules([
                                fn (?Competitor $record): object => Rule::unique('competitors', 'name')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                // The database index is global, so a deleted namesake must be
                                // restored rather than recreated; say so instead of crashing.
                                fn (?Competitor $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $trashed = Competitor::onlyTrashed()
                                        ->where('name', $value)
                                        ->whereKeyNot($record?->getKey())
                                        ->exists();

                                    if ($trashed) {
                                        $fail(__('competitors.validation.name_unique_trashed'));
                                    }
                                },
                            ])
                            ->validationMessages(['unique' => __('competitors.validation.name_unique')]),

                        TextInput::make('website')
                            ->label(__('competitors.fields.website'))
                            ->placeholder(__('competitors.placeholders.website'))
                            ->helperText(__('competitors.helpers.website'))
                            ->url()
                            ->nullable()
                            ->maxLength(255)
                            ->validationMessages(['url' => __('competitors.validation.website_url')])
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Textarea::make('notes')
                            ->label(__('competitors.fields.notes'))
                            ->placeholder(__('competitors.placeholders.notes'))
                            ->helperText(__('competitors.helpers.notes'))
                            ->nullable()
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label(__('competitors.fields.is_active'))
                            ->helperText(__('competitors.helpers.is_active'))
                            ->default(true),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }
}
