<?php

namespace Database\Seeders;

use App\Enums\RoleType;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@toyaradius.test'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'role' => RoleType::SuperAdmin,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@toyaradius.test'],
            [
                'name' => 'Admin Operasional',
                'password' => 'password',
                'role' => RoleType::Admin,
                'is_active' => true,
            ]
        );

        $outlet = Outlet::first();

        if ($outlet) {
            User::updateOrCreate(
                ['email' => 'outlet@toyaradius.test'],
                [
                    'name' => 'Outlet Demo',
                    'password' => 'password',
                    'role' => RoleType::Outlet,
                    'outlet_id' => $outlet->id,
                    'is_active' => true,
                ]
            );
        }
    }
}
