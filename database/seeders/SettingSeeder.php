<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'isp_name', 'value' => 'Toya Net', 'group' => 'general'],
            ['key' => 'isp_logo', 'value' => null, 'group' => 'general'],
            ['key' => 'isp_address', 'value' => 'Jl. Contoh No. 1', 'group' => 'general'],
            ['key' => 'isp_contact', 'value' => '081234567890', 'group' => 'general'],
            ['key' => 'timezone', 'value' => 'Asia/Jakarta', 'group' => 'general'],
            ['key' => 'voucher_number_format', 'value' => '{PREFIX}{RANDOM}', 'group' => 'voucher'],
            ['key' => 'voucher_prefix', 'value' => 'TYA', 'group' => 'voucher'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
