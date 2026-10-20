<?php

use App\Models\Radcheck;
use App\Models\Voucher;
use App\Models\VoucherProfile;
use App\Services\FreeRadiusService;

it('provisions and deprovisions a voucher in freeradius tables', function () {
    $profile = VoucherProfile::factory()->create(['session_timeout' => 3600, 'idle_timeout' => 300]);
    $voucher = Voucher::factory()->create(['voucher_profile_id' => $profile->id]);

    $service = app(FreeRadiusService::class);
    $service->provisionVoucher($voucher, $profile);

    expect(Radcheck::where('username', $voucher->username)->count())->toBeGreaterThan(0);

    $service->deprovisionVoucher($voucher);

    expect(Radcheck::where('voucher_id', $voucher->id)->count())->toBe(0);
});

it('disables a voucher by injecting Auth-Type Reject and re-enables by removing it', function () {
    $profile = VoucherProfile::factory()->create();
    $voucher = Voucher::factory()->create(['voucher_profile_id' => $profile->id]);

    $service = app(FreeRadiusService::class);
    $service->provisionVoucher($voucher, $profile);
    $service->disableVoucher($voucher);

    expect(Radcheck::where('username', $voucher->username)->where('attribute', 'Auth-Type')->value('value'))->toBe('Reject');

    $service->enableVoucher($voucher);

    expect(Radcheck::where('username', $voucher->username)->where('attribute', 'Auth-Type')->exists())->toBeFalse();
});
