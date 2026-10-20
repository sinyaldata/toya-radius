<?php

namespace App\Policies;

use App\Enums\RoleType;
use App\Models\Router;
use App\Models\User;

class RouterPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function view(User $user, Router $router): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function create(User $user): bool
    {
        return $user->role === RoleType::SuperAdmin;
    }

    public function update(User $user, Router $router): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function delete(User $user, Router $router): bool
    {
        return $user->role === RoleType::SuperAdmin;
    }
}
