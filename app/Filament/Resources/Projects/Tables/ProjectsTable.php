<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The projects list (decision D-30): the name as a badge in the project's
 * colour, the two flags and the order, draggable into the order the switcher
 * and the «المشاريع» cards follow. Deleting is left to the policy: the default
 * and a project in use are never offered it.
 */
final class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('projects.fields.name'))
                    ->state(fn (Project $record): string => $record->display_name)
                    ->badge()
                    ->color(fn (Project $record): string => $record->color->value)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(Project::localisedNameColumn(), $direction)),

                TextColumn::make('color')
                    ->label(__('projects.fields.color'))
                    ->badge()
                    ->color(fn (Project $record): string => $record->color->value)
                    ->visibleFrom('md'),

                IconColumn::make('is_active')
                    ->label(__('projects.fields.is_active'))
                    ->boolean(),

                IconColumn::make('is_default')
                    ->label(__('projects.fields.is_default'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('projects.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->label(__('projects.actions.reorder')))
            ->filters([
                TernaryFilter::make('is_active')->label(__('projects.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('projects.empty.heading'))
            ->emptyStateDescription(__('projects.empty.description'));
    }
}
