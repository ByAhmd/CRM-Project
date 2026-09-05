<?php

declare(strict_types=1);

namespace App\Filament\Resources\Competitors\Tables;

use App\Models\Competitor;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class CompetitorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('competitors.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('website')
                    ->label(__('competitors.fields.website'))
                    ->placeholder(__('competitors.placeholders.no_website'))
                    ->url(fn (Competitor $record): ?string => $record->website)
                    ->openUrlInNewTab()
                    ->extraAttributes(['dir' => 'ltr'])
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('competitors.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label(__('competitors.filters.is_active')),
                TrashedFilter::make()->label(__('competitors.filters.trashed')),
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
            ->emptyStateHeading(__('competitors.empty.heading'))
            ->emptyStateDescription(__('competitors.empty.description'));
    }
}
