<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Exceptions\Access\LockedRoleException;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Role $record): void {
                    $actor = auth()->user();

                    app(RoleService::class)->delete($record, $actor instanceof User ? $actor : null);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof Role);

        $data['permissions'] = RolePermissionsState::group(
            $record->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all(),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Role);

        $permissions = RolePermissionsState::flatten($data['permissions'] ?? []);
        unset($data['permissions']);

        $actor = auth()->user();

        try {
            DB::transaction(function () use ($record, $data, $permissions, $actor): void {
                app(RoleService::class)->syncPermissions($record, $permissions, $actor instanceof User ? $actor : null);
                $record->update($data);
            });
        } catch (LockedRoleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
