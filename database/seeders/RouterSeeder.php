<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;

class RouterSeeder extends Seeder
{
    public function run(): void
    {
        Router::factory()->create([
            'name' => 'Router Utama',
            'ip_address' => '192.168.88.1',
            'api_port' => 8728,
            'username' => 'admin',
            'password' => 'admin123',
            'secret' => 'toyaradiussecret',
        ]);
    }
}
