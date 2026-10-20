<?php

namespace App\Services;

use App\Enums\VoucherStatus;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransactionService
{
    public function __construct(
        protected TransactionRepositoryInterface $transactionRepository,
        protected VoucherGeneratorService $voucherGeneratorService,
    ) {}

    public function sellVoucher(Voucher $voucher, array $payload): Transaction
    {
        if ($voucher->status !== VoucherStatus::BelumTerjual) {
            throw new RuntimeException('Voucher ini sudah tidak berstatus "Belum Terjual".');
        }

        return DB::transaction(function () use ($voucher, $payload) {
            $discount = (float) ($payload['discount_amount'] ?? 0);
            $price = (float) $voucher->sale_price;
            $total = max(0, $price - $discount);

            $transaction = $this->transactionRepository->create([
                'voucher_id' => $voucher->id,
                'outlet_id' => $voucher->outlet_id,
                'cashier_id' => $payload['cashier_id'],
                'price' => $price,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_method' => $payload['payment_method'],
                'payment_status' => $payload['payment_status'] ?? 'lunas',
                'transaction_date' => now(),
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->voucherGeneratorService->sell($voucher);

            return $transaction;
        });
    }
}
