<?php

use App\Enums\PaymentMethod;
use App\Enums\VoucherStatus;
use App\Models\User;
use App\Models\Voucher;
use App\Services\TransactionService;

it('sells a voucher and marks it as terjual', function () {
    $voucher = Voucher::factory()->create(['status' => VoucherStatus::BelumTerjual, 'sale_price' => 10000]);
    $cashier = User::factory()->create();

    $transaction = app(TransactionService::class)->sellVoucher($voucher, [
        'cashier_id' => $cashier->id,
        'payment_method' => PaymentMethod::Tunai->value,
        'discount_amount' => 1000,
    ]);

    expect($transaction->total_amount)->toEqual(9000)
        ->and($voucher->fresh()->status)->toBe(VoucherStatus::Terjual)
        ->and($transaction->transaction_number)->toStartWith('TRX-');
});

it('refuses to sell a voucher that is not belum_terjual', function () {
    $voucher = Voucher::factory()->create(['status' => VoucherStatus::Terjual]);
    $cashier = User::factory()->create();

    app(TransactionService::class)->sellVoucher($voucher, [
        'cashier_id' => $cashier->id,
        'payment_method' => PaymentMethod::Tunai->value,
    ]);
})->throws(RuntimeException::class);
