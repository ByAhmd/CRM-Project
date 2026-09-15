<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Schemas;

use App\Models\Pipeline;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Create / edit pipeline. Both names are unique across live pipelines; the
 * index is global, so a deleted namesake is refused with a "restore instead"
 * message rather than failing on insert.
 */
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
                                // The database index is global, so a deleted namesake must be
                                // restored rather than recreated; say so instead of crashing.
                                fn (?Pipeline $record): Closure => self::trashedNamesakeRule('name_ar', $record),
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
                                // The database index is global, so a deleted namesake must be
                                // restored rather than recreated; say so instead of crashing.
                                fn (?Pipeline $record): Closure => self::trashedNamesakeRule('name_en', $record),
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

    /** Fails when a soft-deleted pipeline other than the record carries the name. */
    private static function trashedNamesakeRule(string $column, ?Pipeline $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $record): void {
            $trashed = Pipeline::onlyTrashed()
                ->where($column, $value)
                ->whereKeyNot($record?->getKey())
                ->exists();

            if ($trashed) {
                $fail(__('pipelines.validation.'.$column.'_unique_trashed'));
            }
        };
    }
}
