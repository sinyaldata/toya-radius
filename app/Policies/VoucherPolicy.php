<?php

namespace App\Policies;

use App\Enums\RoleType;
use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // semua role login bisa melihat list (scoped otomatis via OutletScope utk outlet)
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $user->role !== RoleType::Outlet || $voucher->outlet_id === $user->outlet_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function update(User $user, Voucher $voucher): bool
    {
        if ($user->role === RoleType::Outlet) {
            return false; // outlet tidak boleh ubah voucher, hanya menjual
        }

        return true;
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $user->role === RoleType::SuperAdmin;
    }

    public function sell(User $user, Voucher $voucher): bool
    {
        if ($user->role === RoleType::Outlet) {
            return $voucher->outlet_id === $user->outlet_id;
        }

        return true;
    }
}
