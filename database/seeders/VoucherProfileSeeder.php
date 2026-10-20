<?php

namespace Database\Seeders;

use App\Models\Router;
use App\Models\VoucherProfile;
use Illuminate\Database\Seeder;

class VoucherProfileSeeder extends Seeder
{
    public function run(): void
    {
        $router = Router::first();

        $profiles = [
            ['name' => 'Paket 1 Jam', 'mikrotik_profile' => 'toya-1jam', 'rate_limit' => '2M/2M', 'session_timeout' => 3600, 'idle_timeout' => 300, 'shared_users' => 1, 'validity_days' => 1, 'bandwidth_label' => '2 Mbps', 'cost_price' => 1000, 'sale_price' => 3000],
            ['name' => 'Paket 1 Hari', 'mikrotik_profile' => 'toya-1hari', 'rate_limit' => '3M/3M', 'session_timeout' => 86400, 'idle_timeout' => 600, 'shared_users' => 1, 'validity_days' => 1, 'bandwidth_label' => '3 Mbps', 'cost_price' => 3000, 'sale_price' => 5000],
            ['name' => 'Paket 3 Hari', 'mikrotik_profile' => 'toya-3hari', 'rate_limit' => '5M/5M', 'session_timeout' => 259200, 'idle_timeout' => 600, 'shared_users' => 2, 'validity_days' => 3, 'bandwidth_label' => '5 Mbps', 'cost_price' => 8000, 'sale_price' => 12000],
            ['name' => 'Paket 7 Hari', 'mikrotik_profile' => 'toya-7hari', 'rate_limit' => '5M/5M', 'session_timeout' => 604800, 'idle_timeout' => 600, 'shared_users' => 2, 'validity_days' => 7, 'bandwidth_label' => '5 Mbps', 'cost_price' => 15000, 'sale_price' => 25000],
            ['name' => 'Paket 30 Hari', 'mikrotik_profile' => 'toya-30hari', 'rate_limit' => '10M/10M', 'session_timeout' => 2592000, 'idle_timeout' => 900, 'shared_users' => 3, 'validity_days' => 30, 'bandwidth_label' => '10 Mbps', 'cost_price' => 50000, 'sale_price' => 80000],
        ];

        foreach ($profiles as $profile) {
            VoucherProfile::updateOrCreate(
                ['name' => $profile['name']],
                array_merge($profile, ['router_id' => $router?->id, 'is_active' => true])
            );
        }
    }
}
