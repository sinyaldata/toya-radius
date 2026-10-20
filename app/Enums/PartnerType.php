<?php

namespace App\Enums;

enum PartnerType: string
{
    case Reseller = 'reseller';
    case Outlet = 'outlet';
    case Biller = 'biller';

    public function label(): string
    {
        return match ($this) {
            self::Reseller => 'Reseller',
            self::Outlet => 'Outlet',
            self::Biller => 'Biller',
        };
    }

    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $c) => $c->value, self::cases()),
            array_map(fn (self $c) => $c->label(), self::cases()),
        );
    }
}
