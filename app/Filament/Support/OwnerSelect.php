<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner field on create/edit forms (D-4).
 *
 * Changing the owner is reassignment, so the Select exists only for users
 * holding `{group}.assign`; everyone else sees the current owner as read-only
 * text and the value is never part of what they submit. On create the pages
 * default the owner to the actor.
 */
final class OwnerSelect
{
    /**
     * @return list<Component>
     */
    public static function components(string $permissionGroup): array
    {
        return [
            Select::make('owner_id')
                ->label(__('assignment.fields.owner'))
                ->helperText(__('assignment.helpers.owner'))
                ->options(function () use ($permissionGroup): array {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    return app(RecordVisibilityResolver::class)
                        ->assignableUsers($actor, $permissionGroup)
                        ->pluck('name', 'id')
                        ->all();
                })
                ->default(fn (): ?int => auth()->id() === null ? null : (int) auth()->id())
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(fn (): bool => self::canAssign($permissionGroup)),

            Placeholder::make('owner_display')
                ->label(__('assignment.fields.owner'))
                ->content(function (?Model $record): string {
                    $owner = $record?->getRelationValue('owner');

                    if ($owner instanceof User) {
                        return $owner->name;
                    }

                    $actor = auth()->user();

                    return $record === null && $actor instanceof User ? $actor->name : __('assignment.placeholders.unassigned');
                })
                ->visible(fn (): bool => ! self::canAssign($permissionGroup)),
        ];
    }

    private static function canAssign(string $permissionGroup): bool
    {
        return auth()->user()?->can($permissionGroup.'.assign') ?? false;
    }
}
