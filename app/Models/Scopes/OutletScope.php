<?php

namespace App\Models\Scopes;

use App\Enums\RoleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope wajib pada Model Voucher & Transaction.
 * Outlet HANYA boleh melihat data miliknya sendiri — di-enforce di layer query,
 * bukan hanya di UI, sehingga tidak bisa dibypass lewat manipulasi request.
 */
class OutletScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        // PENTING: $user->role di-cast ke enum RoleType (lihat User::casts()),
        // jadi hasilnya OBJECT enum, BUKAN string mentah. Membandingkan
        // dengan RoleType::Outlet->value (string) pakai === SELALU false di
        // PHP walau "sebenarnya sama" — akibatnya scope ini TIDAK PERNAH
        // aktif sama sekali, dan user Outlet manapun bisa lihat voucher
        // outlet lain. Perbaikannya: bandingkan enum dengan enum.
        if ($user->role === RoleType::Outlet) {
            $builder->where($model->getTable().'.outlet_id', $user->outlet_id);
        }
    }
}
