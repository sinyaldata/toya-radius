<?php

namespace Database\Factories;

use App\Models\VoucherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoucherProfileFactory extends Factory
{
    protected $model = VoucherProfile::class;

    public function definition(): array
    {
        $cost = $this->faker->randomElement([1000, 3000, 8000, 15000]);

        return [
            'name' => 'Paket '.$this->faker->word(),
            'mikrotik_profile' => 'profile-'.$this->faker->unique()->numberBetween(1, 999),
            'rate_limit' => $this->faker->randomElement(['2M/2M', '3M/3M', '5M/5M', '10M/10M']),
            'session_timeout' => $this->faker->randomElement([3600, 86400, 259200]),
            'idle_timeout' => 600,
            'shared_users' => 1,
            'validity_days' => $this->faker->randomElement([1, 3, 7, 30]),
            'bandwidth_label' => $this->faker->randomElement(['2 Mbps', '3 Mbps', '5 Mbps', '10 Mbps']),
            'cost_price' => $cost,
            'sale_price' => $cost * 1.6,
            'is_active' => true,
        ];
    }
}
