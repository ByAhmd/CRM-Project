<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $permissions = RolePermissionsState::flatten($data['permissions'] ?? []);
        unset($data['permissions']);

        $actor = auth()->user();

        return DB::transaction(function () use ($data, $permissions, $actor): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'name' => (string) $data['name'],
                'guard_name' => 'web',
                'name_ar' => (string) $data['name_ar'],
                'name_en' => (string) $data['name_en'],
            ]);

            app(RoleService::class)->syncPermissions($role, $permissions, $actor instanceof User ? $actor : null);

            return $role;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
