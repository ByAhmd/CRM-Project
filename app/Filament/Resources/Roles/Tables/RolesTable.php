<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
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
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('roles.fields.name'))
                    ->state(fn (Role $record): string => $record->display_name)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")),

                TextColumn::make('name')
                    ->label(__('roles.fields.key'))
                    ->fontFamily('mono')
                    ->extraAttributes(['dir' => 'ltr'])
                    ->sortable(),

                IconColumn::make('seeded')
                    ->label(__('roles.fields.seeded'))
                    ->state(fn (Role $record): bool => $record->isSeeded())
                    ->boolean(),

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
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(function (Role $record): void {
                        $actor = auth()->user();

                        app(RoleService::class)->delete($record, $actor instanceof User ? $actor : null);
                    }),
            ])
            ->emptyStateHeading(__('roles.empty.heading'))
            ->emptyStateDescription(__('roles.empty.description'));
    }
}
