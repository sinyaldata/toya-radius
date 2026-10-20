<?php

namespace App\Enums;

enum RoleType: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Outlet = 'outlet';
    case Teknisi = 'teknisi';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Outlet => 'Outlet / Reseller',
            self::Teknisi => 'Teknisi',
        };
    }

    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $case) => $case->value, self::cases()),
            array_map(fn (self $case) => $case->label(), self::cases()),
        );
    }
}
