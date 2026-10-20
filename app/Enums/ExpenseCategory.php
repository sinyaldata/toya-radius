<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Gaji = 'gaji';
    case Internet = 'internet';
    case Barang = 'barang';
    case Listrik = 'listrik';
    case Sewa = 'sewa';
    case Perawatan = 'perawatan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Gaji => 'Gaji Karyawan',
            self::Internet => 'Bayar Internet / Upstream',
            self::Barang => 'Pembelian Barang / Perangkat',
            self::Listrik => 'Listrik',
            self::Sewa => 'Sewa Tempat / Tiang',
            self::Perawatan => 'Perawatan & Perbaikan',
            self::Lainnya => 'Lainnya',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Gaji => 'info',
            self::Internet => 'primary',
            self::Barang => 'warning',
            self::Listrik => 'danger',
            self::Sewa => 'gray',
            self::Perawatan => 'success',
            self::Lainnya => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Gaji => 'lucide-users-round',
            self::Internet => 'lucide-globe',
            self::Barang => 'lucide-box',
            self::Listrik => 'lucide-zap',
            self::Sewa => 'lucide-home',
            self::Perawatan => 'lucide-wrench',
            self::Lainnya => 'lucide-more-horizontal',
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
