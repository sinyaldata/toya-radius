<?php

namespace Database\Factories;

use App\Enums\RoleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => RoleType::Admin,
            'is_active' => true,
            'remember_token' => \Illuminate\Support\Str::random(10),
        ];
    }

    public function outlet(): static
    {
        return $this->state(fn () => ['role' => RoleType::Outlet]);
    }
}
