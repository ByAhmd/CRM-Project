<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Tables;

use App\Models\Pipeline;
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

final class PipelinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('pipelines.fields.name'))
                    ->state(fn (Pipeline $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Pipeline::localisedNameColumn(), $direction)),

                TextColumn::make('defaultStage.display_name')
                    ->label(__('pipelines.fields.default_stage'))
                    ->state(fn (Pipeline $record): ?string => $record->defaultStage?->display_name)
                    ->placeholder(__('pipelines.placeholders.no_default_stage')),

                TextColumn::make('stages_count')
                    ->label(__('pipelines.fields.stages_count'))
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label(__('pipelines.fields.is_default'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('pipelines.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('pipelines.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                TernaryFilter::make('is_active')->label(__('pipelines.filters.is_active')),
                TernaryFilter::make('is_default')->label(__('pipelines.filters.is_default')),
                TrashedFilter::make()->label(__('pipelines.filters.trashed')),
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
            ->emptyStateHeading(__('pipelines.empty.heading'))
            ->emptyStateDescription(__('pipelines.empty.description'));
    }
}
