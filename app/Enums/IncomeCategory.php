<?php

namespace App\Enums;

enum IncomeCategory: string
{
    case Instalasi = 'instalasi';
    case PenjualanAlat = 'penjualan_alat';
    case Jasa = 'jasa';
    case Sewa = 'sewa';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Instalasi => 'Biaya Pasang Baru',
            self::PenjualanAlat => 'Penjualan Alat',
            self::Jasa => 'Jasa / Perbaikan',
            self::Sewa => 'Sewa Perangkat',
            self::Lainnya => 'Lainnya',
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
