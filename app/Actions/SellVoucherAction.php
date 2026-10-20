<?php

namespace App\Actions;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\TransactionService;

class SellVoucherAction
{
    public function __construct(protected TransactionService $transactionService) {}

    public function execute(Voucher $voucher, array $payload): Transaction
    {
        $payload['cashier_id'] = $payload['cashier_id'] ?? auth()->id();

        return $this->transactionService->sellVoucher($voucher, $payload);
    }
}
