<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses\Tables;

use App\Enums\LeadStatusKind;
use App\Models\LeadStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LeadStatusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('lead_statuses.fields.name'))
                    ->state(fn (LeadStatus $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(LeadStatus::localisedNameColumn(), $direction)),

                TextColumn::make('kind')
                    ->label(__('lead_statuses.fields.kind'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('color')
                    ->label(__('lead_statuses.fields.color'))
                    ->badge()
                    ->color(fn (LeadStatus $record): string => $record->color->value),

                IconColumn::make('is_default')
                    ->label(__('lead_statuses.fields.is_default'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('lead_statuses.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('lead_statuses.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('lead_statuses.filters.kind'))
                    ->options(LeadStatusKind::class),
                TernaryFilter::make('is_active')->label(__('lead_statuses.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('lead_statuses.empty.heading'))
            ->emptyStateDescription(__('lead_statuses.empty.description'));
    }
}
