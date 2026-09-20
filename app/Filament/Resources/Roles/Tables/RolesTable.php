<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Support\LtrText;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Phone budget: name and both counts stay at every width; the
            // technical key and the seeded flag step in from `md`.
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('roles.fields.name'))
                    ->state(fn (Role $record): string => $record->display_name)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy(Role::localisedNameColumn(), $direction)),

                LtrText::column(
                    TextColumn::make('name')
                        ->label(__('roles.fields.key'))
                        ->fontFamily('mono')
                        ->sortable()
                        ->visibleFrom('md'),
                ),

                IconColumn::make('seeded')
                    ->label(__('roles.fields.seeded'))
                    ->state(fn (Role $record): bool => $record->isSeeded())
                    ->boolean()
                    ->visibleFrom('md'),

                TextColumn::make('permissions_count')
                    ->label(__('roles.fields.permissions_count'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('users_count')
                    ->label(__('roles.fields.users_count'))
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('name')
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->using(function (Role $record): void {
                            $actor = auth()->user();

                            app(RoleService::class)->delete($record, $actor instanceof User ? $actor : null);
                        }),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('roles.empty.heading'))
            ->emptyStateDescription(__('roles.empty.description'));
    }
}
