<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Contracts\OwnedRecord;
use App\Enums\Permission;
use App\Exceptions\Access\UnassignableUserException;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner field on create/edit forms (D-4).
 *
 * Changing the owner is reassignment, so the Select exists only for users
 * holding `{group}.assign`; everyone else sees the current owner as read-only
 * text and the value is never part of what they submit. On create the pages
 * default the owner to the actor.
 *
 * The options are the active users inside the actor's reach, plus the
 * record's current owner whatever their state (disabled, pending, deleted):
 * an unrelated edit keeps validating, but that owner is never offered as a
 * new assignment anywhere else.
 *
 * On edit the owner is not saved as a plain attribute: the page pulls it out
 * of the submitted data (pull()) and hands a change to
 * RecordAssignmentService through reassign(), which audits and notifies.
 */
final class OwnerSelect
{
    public const string FIELD = 'owner_id';

    /**
     * @return list<Component>
     */
    public static function components(string $permissionGroup): array
    {
        $assign = Permission::from($permissionGroup.'.assign');

        return [
            Select::make(self::FIELD)
                ->label(__('assignment.fields.owner'))
                ->helperText(__('assignment.helpers.owner'))
                ->options(function (?Model $record) use ($permissionGroup): array {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    $options = app(RecordVisibilityResolver::class)
                        ->assignableUsers($actor, $permissionGroup)
                        ->pluck('name', 'id')
                        ->all();

                    $currentId = self::currentOwnerId($record);

                    if ($currentId !== null && ! array_key_exists($currentId, $options)) {
                        $current = User::withTrashed()->whereKey($currentId)->value('name');

                        if (is_string($current)) {
                            $options[$currentId] = $current;
                        }
                    }

                    return $options;
                })
                ->default(fn (): ?int => auth()->id() === null ? null : (int) auth()->id())
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(fn (): bool => self::canAssign($assign)),

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
                ->visible(fn (): bool => ! self::canAssign($assign)),
        ];
    }

    /**
     * Removes the submitted owner from the attributes an edit saves. Returns
     * the owner id the form asked for, or false when the form carried no
     * owner (the actor may not reassign, so the Select was not rendered).
     *
     * A change to someone outside the actor's reach is refused here, before
     * anything is written, so a refusal never leaves the other attributes
     * saved without the assignment.
     *
     * @param  array<string, mixed>  $data
     */
    public static function pull(array &$data, Model&OwnedRecord $record): int|false|null
    {
        if (! array_key_exists(self::FIELD, $data)) {
            return false;
        }

        $ownerId = $data[self::FIELD];
        unset($data[self::FIELD]);
        $ownerId = $ownerId === null || $ownerId === '' ? null : (int) $ownerId;

        $actor = auth()->user();

        if ($ownerId !== null && $ownerId !== self::currentOwnerId($record) && (! $actor instanceof User
            || ! app(RecordVisibilityResolver::class)->assignableUsers($actor, $record::permissionGroup())->whereKey($ownerId)->exists())) {
            self::refuse(UnassignableUserException::make());
        }

        return $ownerId;
    }

    /**
     * Hands an owner change made through a form to RecordAssignmentService
     * (audit row + notification, D-4). An unchanged owner is left alone; a
     * target the service refuses becomes a danger notification and halts
     * the save, rolling back its transaction.
     */
    public static function reassign(Model&OwnedRecord $record, int|false|null $ownerId): void
    {
        $actor = auth()->user();

        if ($ownerId === false || ! $actor instanceof User || $ownerId === self::currentOwnerId($record)) {
            return;
        }

        try {
            $newOwner = $ownerId === null ? null : User::query()->find($ownerId);

            if ($ownerId !== null && ! $newOwner instanceof User) {
                throw UnassignableUserException::make();
            }

            app(RecordAssignmentService::class)->assign($record, $newOwner, $actor);
        } catch (UnassignableUserException $exception) {
            self::refuse($exception);
        }
    }

    private static function refuse(UnassignableUserException $exception): never
    {
        Notification::make()
            ->danger()
            ->title($exception->getMessage())
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    private static function currentOwnerId(?Model $record): ?int
    {
        if (! $record instanceof OwnedRecord || ! $record->exists) {
            return null;
        }

        $ownerId = $record->getAttribute($record::ownerColumn());

        return $ownerId === null ? null : (int) $ownerId;
    }

    private static function canAssign(Permission $assign): bool
    {
        return auth()->user()?->can($assign->value) ?? false;
    }
}
