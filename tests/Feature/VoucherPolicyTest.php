<?php

use App\Enums\RoleType;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Voucher;

it('allows outlet user to sell only their own voucher', function () {
    $outletA = Outlet::factory()->create();
    $outletB = Outlet::factory()->create();

    $voucherOwn = Voucher::factory()->create(['outlet_id' => $outletA->id]);
    $voucherOther = Voucher::factory()->create(['outlet_id' => $outletB->id]);

    $userA = User::factory()->create(['role' => RoleType::Outlet, 'outlet_id' => $outletA->id]);

    expect($userA->can('sell', $voucherOwn))->toBeTrue();

    // voucherOther tidak akan bahkan terlihat oleh $userA karena OutletScope,
    // tapi policy sendiri juga harus menolak jika somehow diakses langsung by ID.
    expect($userA->can('sell', $voucherOther))->toBeFalse();
});

it('prevents outlet role from updating or deleting vouchers', function () {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->create(['role' => RoleType::Outlet, 'outlet_id' => $outlet->id]);
    $voucher = Voucher::factory()->create(['outlet_id' => $outlet->id]);

    expect($user->can('update', $voucher))->toBeFalse();
    expect($user->can('delete', $voucher))->toBeFalse();
});

it('only allows super admin to delete vouchers', function () {
    $admin = User::factory()->create(['role' => RoleType::Admin]);
    $superAdmin = User::factory()->create(['role' => RoleType::SuperAdmin]);
    $voucher = Voucher::factory()->create();

    expect($admin->can('delete', $voucher))->toBeFalse();
    expect($superAdmin->can('delete', $voucher))->toBeTrue();
});
