<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            PermissionName::ProductsView->value
        );
    }

    public function view(
        User $user,
        Product $product
    ): bool {
        return $user->can(
            PermissionName::ProductsView->value
        );
    }

    public function create(
        User $user
    ): bool {
        return $user->can(
            PermissionName::ProductsCreate->value
        );
    }

    public function update(
        User $user,
        Product $product
    ): bool {
        return $user->can(
            PermissionName::ProductsUpdate->value
        );
    }

    public function delete(
        User $user,
        Product $product
    ): bool {
        return $user->can(
            PermissionName::ProductsDelete->value
        );
    }
}
