<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityTypes\Tables;

use App\Enums\ActivityKind;
use App\Models\ActivityType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ActivityTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('icon')
                    ->label(__('activity_types.fields.icon'))
                    ->icon(fn (ActivityType $record): ?Heroicon => $record->heroicon())
                    ->color(fn (ActivityType $record): string => $record->color->value),

                TextColumn::make('display_name')
                    ->label(__('activity_types.fields.name'))
                    ->state(fn (ActivityType $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(ActivityType::localisedNameColumn(), $direction)),

                TextColumn::make('kind')
                    ->label(__('activity_types.fields.kind'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('color')
                    ->label(__('activity_types.fields.color'))
                    ->badge()
                    ->color(fn (ActivityType $record): string => $record->color->value),

                IconColumn::make('is_system')
                    ->label(__('activity_types.fields.is_system'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('activity_types.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('activity_types.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                TernaryFilter::make('is_active')->label(__('activity_types.filters.is_active')),
                SelectFilter::make('kind')
                    ->label(__('activity_types.filters.kind'))
                    ->options(ActivityKind::class),
                TernaryFilter::make('is_system')->label(__('activity_types.filters.is_system')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('activity_types.empty.heading'))
            ->emptyStateDescription(__('activity_types.empty.description'));
    }
}
