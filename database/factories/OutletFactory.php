<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

class OutletFactory extends Factory
{
    protected $model = Outlet::class;

    public function definition(): array
    {
        return [
            'code' => 'OUT-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'name' => 'Outlet '.$this->faker->streetName(),
            'owner_name' => $this->faker->name(),
            'phone' => $this->faker->numerify('08##########'),
            'address' => $this->faker->address(),
            'commission_percent' => $this->faker->randomElement([0, 5, 10, 15]),
            'is_active' => true,
        ];
    }
}
