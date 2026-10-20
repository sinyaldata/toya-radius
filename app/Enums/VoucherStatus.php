<?php

namespace App\Enums;

enum VoucherStatus: string
{
    case BelumTerjual = 'belum_terjual';
    case Terjual = 'terjual';
    case Aktif = 'aktif';
    case Expired = 'expired';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::BelumTerjual => 'Belum Terjual',
            self::Terjual => 'Terjual',
            self::Aktif => 'Aktif',
            self::Expired => 'Expired',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BelumTerjual => 'warning',
            self::Terjual => 'info',
            self::Aktif => 'success',
            self::Expired => 'danger',
            self::Disabled => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::BelumTerjual => 'lucide-archive',
            self::Terjual => 'lucide-shopping-cart',
            self::Aktif => 'lucide-zap',
            self::Expired => 'lucide-clock',
            self::Disabled => 'lucide-ban',
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
