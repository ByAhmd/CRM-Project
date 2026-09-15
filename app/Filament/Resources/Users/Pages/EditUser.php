<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserStatus;
use App\Exceptions\Access\LastSuperAdminException;
use App\Exceptions\Access\SelfStatusChangeException;
use App\Exceptions\Access\SuperAdminMembershipException;
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
use InvalidArgumentException;
use RuntimeException;

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
     * Roles and status changes go through RoleService, which refuses to switch
     * off or demote the last active super admin, to let anyone switch off
     * their own account, to set an account back to pending and to let a
     * users.manage holder without roles.manage touch super_admin. A refusal
     * aborts the whole save and is shown as a danger notification.
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
        abort_unless($actor instanceof User, 403);

        $roleService = app(RoleService::class);

        try {
            DB::transaction(function () use ($record, $data, $roles, $actor, $roleService): void {
                $submitted = $data['status'] ?? null;
                $newStatus = $submitted instanceof UserStatus ? $submitted : UserStatus::tryFrom((string) $submitted);

                if ($newStatus !== null) {
                    try {
                        $roleService->assertStatusChange($record, $newStatus, $actor);
                    } catch (InvalidArgumentException $exception) {
                        // Only the status guard's own refusal (pending by hand) is
                        // shown; any other invalid argument stays a real error.
                        $this->refuse($exception);
                    }
                }

                $roleService->syncUserRoles($record, $roles, $actor);
                $record->update($data);
            });
        } catch (LastSuperAdminException|SelfStatusChangeException|SuperAdminMembershipException $exception) {
            $this->refuse($exception);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** @throws Halt */
    private function refuse(RuntimeException|InvalidArgumentException $exception): never
    {
        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->send();

        throw new Halt;
    }
}
