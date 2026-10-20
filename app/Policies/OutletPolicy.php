<?php

namespace App\Policies;

use App\Enums\RoleType;
use App\Models\Outlet;
use App\Models\User;

class OutletPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $user->role === RoleType::SuperAdmin;
    }
}
