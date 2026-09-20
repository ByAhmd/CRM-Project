<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\LtrText;
use App\Models\Product;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                LtrText::column(
                    TextColumn::make('code')
                        ->label(__('products.fields.code'))
                        ->placeholder(__('products.placeholders.no_code'))
                        ->searchable()
                        ->sortable(),
                ),

                TextColumn::make('display_name')
                    ->label(__('products.fields.name'))
                    ->state(fn (Product $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Product::localisedNameColumn(), $direction)),

                LtrText::column(
                    TextColumn::make('unit_price')
                        ->label(__('products.fields.unit_price'))
                        ->money(
                            currency: fn (): string => app(SettingsRepository::class)->currency(),
                            locale: fn (): string => app()->getLocale(),
                        )
                        ->sortable(),
                ),

                IconColumn::make('is_active')
                    ->label(__('products.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort(Product::localisedNameColumn())
            ->filters([
                TernaryFilter::make('is_active')->label(__('products.filters.is_active')),
                TrashedFilter::make()->label(__('products.filters.trashed')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('products.empty.heading'))
            ->emptyStateDescription(__('products.empty.description'));
    }
}
