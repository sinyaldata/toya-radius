<?php

namespace Database\Factories;

use App\Enums\VoucherStatus;
use App\Models\Voucher;
use App\Models\VoucherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        $profile = VoucherProfile::inRandomOrder()->first() ?? VoucherProfile::factory()->create();

        return [
            'username' => strtoupper(Str::random(8)),
            'password' => strtoupper(Str::random(6)),
            'batch_code' => 'BATCH-'.now()->format('YmdHis'),
            'voucher_profile_id' => $profile->id,
            'status' => VoucherStatus::BelumTerjual,
            'cost_price' => $profile->cost_price,
            'sale_price' => $profile->sale_price,
        ];
    }

    public function sold(): static
    {
        return $this->state(fn () => [
            'status' => VoucherStatus::Terjual,
            'sold_at' => now(),
        ]);
    }
}
