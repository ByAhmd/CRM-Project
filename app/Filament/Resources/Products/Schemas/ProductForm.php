<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Services\Settings\SettingsRepository;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('products.sections.details'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('products.fields.code'))
                            ->placeholder(__('products.placeholders.code'))
                            ->helperText(__('products.helpers.code'))
                            ->nullable()
                            ->maxLength(50)
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->rules([
                                fn (?Product $record): object => Rule::unique('products', 'code')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                // The database index is global, so a deleted product with the
                                // same code must be restored rather than recreated; say so
                                // instead of crashing. Compare the normalised code the mutator
                                // would store (trimmed, upper-cased).
                                fn (?Product $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $code = mb_strtoupper(trim((string) $value));

                                    if ($code === '') {
                                        return;
                                    }

                                    $trashed = Product::onlyTrashed()
                                        ->where('code', $code)
                                        ->whereKeyNot($record?->getKey())
                                        ->exists();

                                    if ($trashed) {
                                        $fail(__('products.validation.code_unique_trashed'));
                                    }
                                },
                            ])
                            ->validationMessages(['unique' => __('products.validation.code_unique')]),

                        TextInput::make('name_ar')
                            ->label(__('products.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Product $record): object => Rule::unique('products', 'name_ar')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                self::trashedNamesakeRule('name_ar'),
                            ])
                            ->validationMessages(['unique' => __('products.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('products.fields.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Product $record): object => Rule::unique('products', 'name_en')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                self::trashedNamesakeRule('name_en'),
                            ])
                            ->validationMessages(['unique' => __('products.validation.name_unique')]),

                        TextInput::make('unit_price')
                            ->label(__('products.fields.unit_price'))
                            ->helperText(__('products.helpers.unit_price'))
                            ->suffix(fn (): string => app(SettingsRepository::class)->currency())
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(999999999999.99)
                            ->step(0.01)
                            ->rules(['decimal:0,2'])
                            ->default(0)
                            ->required()
                            ->validationMessages([
                                'decimal' => __('products.validation.unit_price_decimals'),
                                'min' => __('products.validation.unit_price_min'),
                            ]),

                        Toggle::make('is_active')
                            ->label(__('products.fields.is_active'))
                            ->helperText(__('products.helpers.is_active'))
                            ->default(true),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * The name indexes are global, so a soft-deleted product with the same
     * name must be restored rather than recreated; report that as a
     * validation message instead of letting the database index throw.
     */
    private static function trashedNamesakeRule(string $column): Closure
    {
        return fn (?Product $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $column): void {
            $trashed = Product::onlyTrashed()
                ->where($column, $value)
                ->whereKeyNot($record?->getKey())
                ->exists();

            if ($trashed) {
                $fail(__('products.validation.name_unique_trashed'));
            }
        };
    }
}
