<?php

namespace App\Policies;

use App\Enums\RoleType;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->role !== RoleType::Outlet || $transaction->outlet_id === $user->outlet_id;
    }

    public function create(User $user): bool
    {
        return true; // semua role bisa menjual voucher (kasir bisa admin/outlet)
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return in_array($user->role, [RoleType::SuperAdmin, RoleType::Admin], true);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->role === RoleType::SuperAdmin;
    }
}
