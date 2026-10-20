<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            OutletSeeder::class,
            UserSeeder::class,
            RouterSeeder::class,
            NasSeeder::class,
            VoucherProfileSeeder::class,
        ]);
    }
}
