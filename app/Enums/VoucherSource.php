<?php

namespace App\Enums;

/**
 * Asal voucher dibuat — dipakai untuk keterangan kecil di tabel Voucher
 * dan rincian kartu "Digenerate Hari Ini".
 */
enum VoucherSource: string
{
    case Aplikasi = 'app';
    case Telegram = 'telegram';
    case Qris = 'qris';

    public function label(): string
    {
        return match ($this) {
            self::Aplikasi => 'Aplikasi',
            self::Telegram => 'Telegram',
            self::Qris => 'QRIS',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Aplikasi => 'primary',
            self::Telegram => 'info',
            self::Qris => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Aplikasi => 'lucide-monitor',
            self::Telegram => 'lucide-send',
            self::Qris => 'lucide-qr-code',
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
