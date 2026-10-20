<?php

namespace App\Enums;

enum RadiusUserType: string
{
    case Voucher = 'voucher';
    case Hotspot = 'hotspot';
    case Pppoe = 'pppoe';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Voucher => 'Voucher',
            self::Hotspot => 'User Hotspot',
            self::Pppoe => 'PPPoE',
            self::Unknown => 'Tidak Dikenal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Voucher => 'warning',
            self::Hotspot => 'info',
            self::Pppoe => 'primary',
            self::Unknown => 'gray',
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
