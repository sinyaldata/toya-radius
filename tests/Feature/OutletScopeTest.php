<?php

use App\Enums\RoleType;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Voucher;

it('only shows vouchers belonging to the logged-in outlet user', function () {
    $outletA = Outlet::factory()->create();
    $outletB = Outlet::factory()->create();

    Voucher::factory()->count(3)->create(['outlet_id' => $outletA->id]);
    Voucher::factory()->count(2)->create(['outlet_id' => $outletB->id]);

    $userA = User::factory()->create(['role' => RoleType::Outlet, 'outlet_id' => $outletA->id]);

    $this->actingAs($userA);

    expect(Voucher::count())->toBe(3);
});

it('shows all vouchers for super admin regardless of outlet', function () {
    $outletA = Outlet::factory()->create();
    $outletB = Outlet::factory()->create();

    Voucher::factory()->count(3)->create(['outlet_id' => $outletA->id]);
    Voucher::factory()->count(2)->create(['outlet_id' => $outletB->id]);

    $superAdmin = User::factory()->create(['role' => RoleType::SuperAdmin]);

    $this->actingAs($superAdmin);

    expect(Voucher::count())->toBe(5);
});
