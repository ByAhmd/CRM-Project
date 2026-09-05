<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

/**
 * Products are catalogue data (decision D-8): every sales role may read the
 * catalogue to build deal lines, while changing it is a separate set of
 * product.* permissions. Products are not owned records, so there is no
 * visibility scope. Permanent deletion is never offered (D-13).
 */
final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductViewAny->value);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductCreate->value);
    }

    public function update(User $user, ?Product $product = null): bool
    {
        return $user->can(Permission::ProductUpdate->value);
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        return $user->can(Permission::ProductDelete->value);
    }

    public function restore(User $user, ?Product $product = null): bool
    {
        return $this->update($user, $product);
    }

    public function forceDelete(User $user, ?Product $product = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->restore($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
