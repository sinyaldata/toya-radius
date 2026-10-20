<?php

namespace Database\Seeders;

use App\Models\Nas;
use App\Models\Router;
use Illuminate\Database\Seeder;

class NasSeeder extends Seeder
{
    public function run(): void
    {
        $router = Router::first();

        Nas::updateOrCreate(
            ['nasname' => $router?->ip_address ?? '192.168.88.1'],
            [
                'shortname' => 'router-utama',
                'type' => 'mikrotik',
                'secret' => $router?->secret ?? 'toyaradiussecret',
                'description' => 'NAS default dari Router Utama',
                'router_id' => $router?->id,
            ]
        );
    }
}
