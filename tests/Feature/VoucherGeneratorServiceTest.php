<?php

use App\DTO\VoucherGenerationData;
use App\Enums\VoucherStatus;
use App\Models\Radcheck;
use App\Models\Radreply;
use App\Models\Radusergroup;
use App\Models\VoucherProfile;
use App\Services\VoucherGeneratorService;

it('generates the requested quantity of vouchers and provisions freeradius tables', function () {
    $profile = VoucherProfile::factory()->create([
        'rate_limit' => '5M/5M',
        'mikrotik_profile' => 'test-profile',
    ]);

    $data = new VoucherGenerationData(
        voucherProfileId: $profile->id,
        quantity: 5,
        mode: 'random',
    );

    $vouchers = app(VoucherGeneratorService::class)->generate($data);

    expect($vouchers)->toHaveCount(5);

    foreach ($vouchers as $voucher) {
        expect($voucher->status)->toBe(VoucherStatus::BelumTerjual);
        expect(Radcheck::where('username', $voucher->username)->where('attribute', 'Cleartext-Password')->exists())->toBeTrue();
        expect(Radreply::where('username', $voucher->username)->where('attribute', 'Mikrotik-Rate-Limit')->value('value'))->toBe('5M/5M');
        expect(Radusergroup::where('username', $voucher->username)->value('groupname'))->toBe('test-profile');
    }
});

it('rejects generating more than 1000 vouchers at once', function () {
    $profile = VoucherProfile::factory()->create();

    $data = new VoucherGenerationData(voucherProfileId: $profile->id, quantity: 1001);

    app(VoucherGeneratorService::class)->generate($data);
})->throws(RuntimeException::class);

it('generates unique usernames even in the same batch', function () {
    $profile = VoucherProfile::factory()->create();

    $vouchers = app(VoucherGeneratorService::class)->generate(
        new VoucherGenerationData(voucherProfileId: $profile->id, quantity: 50)
    );

    expect($vouchers->pluck('username')->unique())->toHaveCount(50);
});
