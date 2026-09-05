<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserStatus;
use App\Exceptions\Access\LastSuperAdminException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Access\RoleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof User);

        $data['roles'] = $record->getRoleNames()->all();

        return $data;
    }

    /**
     * Roles and status changes go through RoleService so the last active super
     * admin can never be demoted or disabled; a refusal aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof User);

        /** @var list<string> $roles */
        $roles = array_values((array) ($data['roles'] ?? []));
        unset($data['roles']);

        $actor = auth()->user();
        $roleService = app(RoleService::class);

        try {
            DB::transaction(function () use ($record, $data, $roles, $actor, $roleService): void {
                $submitted = $data['status'] ?? null;
                $newStatus = $submitted instanceof UserStatus ? $submitted : UserStatus::tryFrom((string) $submitted);

                $disabling = $newStatus === UserStatus::Disabled
                    && $record->status !== UserStatus::Disabled;

                if ($disabling && $roleService->isLastActiveSuperAdmin($record)) {
                    throw LastSuperAdminException::make();
                }

                $roleService->syncUserRoles($record, $roles, $actor instanceof User ? $actor : null);
                $record->update($data);
            });
        } catch (LastSuperAdminException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
