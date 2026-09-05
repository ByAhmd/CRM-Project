<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Access\RoleService;
use App\Services\Users\UserInvitationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The invite form (D-11). The administrator never sets a password: the
 * invitee does, through the link sent after the record exists.
 */
final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $roles */
        $roles = array_values((array) ($data['roles'] ?? []));
        unset($data['roles']);

        $actor = auth()->user();

        return DB::transaction(function () use ($data, $roles, $actor): User {
            $user = User::query()->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'phone' => isset($data['phone']) && $data['phone'] !== '' ? (string) $data['phone'] : null,
                'locale' => (string) ($data['locale'] ?? config('app.locale')),
                'team_id' => isset($data['team_id']) && $data['team_id'] !== '' ? (int) $data['team_id'] : null,
                'status' => UserStatus::Pending,
                'password' => null,
            ]);

            app(RoleService::class)->syncUserRoles($user, $roles, $actor instanceof User ? $actor : null);

            return $user;
        });
    }

    /**
     * Sent after the record exists, so a delivery failure cannot leave a
     * half-created user behind; an invitation can always be resent from the list.
     */
    protected function afterCreate(): void
    {
        $created = $this->getRecord();
        $actor = auth()->user();

        assert($created instanceof User);

        app(UserInvitationService::class)->invite($created, $actor instanceof User ? $actor : null);

        Notification::make()
            ->title(__('users.invitation.sent_title'))
            ->body(__('users.invitation.sent_body', ['email' => $created->email]))
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
