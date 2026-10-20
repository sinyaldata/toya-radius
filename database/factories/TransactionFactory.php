<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $voucher = Voucher::factory()->sold()->create();

        return [
            'voucher_id' => $voucher->id,
            'outlet_id' => $voucher->outlet_id,
            'cashier_id' => User::factory(),
            'price' => $voucher->sale_price,
            'discount_amount' => 0,
            'total_amount' => $voucher->sale_price,
            'payment_method' => PaymentMethod::Tunai,
            'payment_status' => PaymentStatus::Lunas,
            'transaction_date' => now(),
        ];
    }
}
