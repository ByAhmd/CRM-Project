<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons\Tables;

use App\Enums\CloseReasonKind;
use App\Models\DealCloseReason;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DealCloseReasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label(__('close_reasons.fields.kind'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('display_name')
                    ->label(__('close_reasons.fields.name'))
                    ->state(fn (DealCloseReason $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(DealCloseReason::localisedNameColumn(), $direction)),

                IconColumn::make('is_active')
                    ->label(__('close_reasons.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('close_reasons.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('close_reasons.filters.kind'))
                    ->options(CloseReasonKind::class),
                TernaryFilter::make('is_active')->label(__('close_reasons.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('close_reasons.empty.heading'))
            ->emptyStateDescription(__('close_reasons.empty.description'));
    }
}
