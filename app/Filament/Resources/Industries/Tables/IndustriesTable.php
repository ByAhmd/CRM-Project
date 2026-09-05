<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Tables;

use App\Models\Industry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class IndustriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('industries.fields.name'))
                    ->state(fn (Industry $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Industry::localisedNameColumn(), $direction)),

                IconColumn::make('is_active')
                    ->label(__('industries.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('industries.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->label(__('industries.actions.reorder')))
            ->filters([
                TernaryFilter::make('is_active')->label(__('industries.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('industries.empty.heading'))
            ->emptyStateDescription(__('industries.empty.description'));
    }
}
