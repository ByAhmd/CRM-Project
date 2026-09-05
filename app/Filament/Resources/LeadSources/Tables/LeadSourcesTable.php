<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadSources\Tables;

use App\Models\LeadSource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LeadSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('lead_sources.fields.name'))
                    ->state(fn (LeadSource $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(LeadSource::localisedNameColumn(), $direction)),

                IconColumn::make('is_active')
                    ->label(__('lead_sources.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('lead_sources.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->label(__('lead_sources.actions.reorder')))
            ->filters([
                TernaryFilter::make('is_active')->label(__('lead_sources.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('lead_sources.empty.heading'))
            ->emptyStateDescription(__('lead_sources.empty.description'));
    }
}
