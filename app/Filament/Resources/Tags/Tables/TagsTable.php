<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Tables;

use App\Models\Tag;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('tags.fields.name'))
                    ->state(fn (Tag $record): string => $record->display_name)
                    ->badge()
                    ->color(fn (Tag $record): string => $record->color->value)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Tag::localisedNameColumn(), $direction)),

                TextColumn::make('color')
                    ->label(__('tags.fields.color'))
                    ->badge()
                    ->color(fn (Tag $record): string => $record->color->value),

                IconColumn::make('is_active')
                    ->label(__('tags.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort(Tag::localisedNameColumn())
            ->filters([
                TernaryFilter::make('is_active')->label(__('tags.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('tags.empty.heading'))
            ->emptyStateDescription(__('tags.empty.description'));
    }
}
