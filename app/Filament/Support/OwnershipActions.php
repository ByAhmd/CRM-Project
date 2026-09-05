<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Contracts\OwnedRecord;
use App\Exceptions\Access\UnassignableUserException;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "assign owner" actions for every owned entity (decision D-4).
 *
 * The record action and the bulk action share one form (a Select limited to
 * the users the actor may assign to) and one service call, so tables, view
 * pages and bulk toolbars behave identically. Authorisation is the policy's
 * `assign` verb, checked per record.
 */
final class OwnershipActions
{
    public static function assign(string $permissionGroup): Action
    {
        return Action::make('assign')
            ->label(__('assignment.actions.assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading(__('assignment.actions.assign_heading'))
            ->modalSubmitActionLabel(__('assignment.actions.assign_submit'))
            ->schema([self::ownerSelect($permissionGroup)])
            ->fillForm(fn (Model&OwnedRecord $record): array => ['owner_id' => $record->getAttribute($record::ownerColumn())])
            ->authorize(fn (Model&OwnedRecord $record): bool => auth()->user()?->can('assign', $record) ?? false)
            ->action(function (Model&OwnedRecord $record, array $data): void {
                self::perform($record, $data['owner_id'] ?? null);
            });
    }

    public static function assignBulk(string $permissionGroup): BulkAction
    {
        return BulkAction::make('assign')
            ->label(__('assignment.actions.assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading(__('assignment.actions.assign_heading'))
            ->modalSubmitActionLabel(__('assignment.actions.assign_submit'))
            ->schema([self::ownerSelect($permissionGroup)])
            ->authorizeIndividualRecords('assign')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $record) {
                    if ($record instanceof OwnedRecord) {
                        self::perform($record, $data['owner_id'] ?? null, silent: true);
                    }
                }

                Notification::make()->title(__('assignment.notifications.bulk_done', ['count' => $records->count()]))->success()->send();
            });
    }

    private static function ownerSelect(string $permissionGroup): Select
    {
        return Select::make('owner_id')
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
            ->searchable()
            ->preload()
            ->nullable()
            ->native(false);
    }

    private static function perform(Model&OwnedRecord $record, mixed $ownerId, bool $silent = false): void
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $owner = $ownerId === null || $ownerId === '' ? null : User::query()->find((int) $ownerId);

        try {
            app(RecordAssignmentService::class)->assign($record, $owner, $actor);
        } catch (UnassignableUserException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (! $silent) {
            Notification::make()
                ->title($owner === null ? __('assignment.notifications.unassigned') : __('assignment.notifications.assigned', ['name' => $owner->name]))
                ->success()
                ->send();
        }
    }
}
