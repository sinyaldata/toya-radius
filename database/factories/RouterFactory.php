<?php

namespace Database\Factories;

use App\Enums\RouterConnectionStatus;
use App\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

class RouterFactory extends Factory
{
    protected $model = Router::class;

    public function definition(): array
    {
        return [
            'name' => 'Router '.$this->faker->word(),
            'ip_address' => $this->faker->localIpv4(),
            'api_port' => 8728,
            'username' => 'admin',
            'password' => 'admin123',
            'secret' => $this->faker->password(12, 20),
            'use_ssl' => false,
            'connection_status' => RouterConnectionStatus::Unknown,
            'is_active' => true,
        ];
    }
}
