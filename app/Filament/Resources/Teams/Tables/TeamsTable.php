<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Team;
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

final class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('teams.fields.name'))
                    ->state(fn (Team $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Team::localisedNameColumn(), $direction)),

                TextColumn::make('manager.name')
                    ->label(__('teams.fields.manager'))
                    ->placeholder(__('teams.placeholders.no_manager')),

                TextColumn::make('members_count')
                    ->label(__('teams.fields.members_count'))
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('teams.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('teams.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->filters([
                TernaryFilter::make('is_active')->label(__('teams.filters.is_active')),
                TrashedFilter::make()->label(__('teams.filters.trashed')),
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
            ->emptyStateHeading(__('teams.empty.heading'))
            ->emptyStateDescription(__('teams.empty.description'));
    }
}
