<?php

namespace App\Enums;

enum TimeUnit: string
{
    case Menit = 'menit';
    case Jam = 'jam';
    case Hari = 'hari';
    case Bulan = 'bulan';

    public function label(): string
    {
        return match ($this) {
            self::Menit => 'Menit',
            self::Jam => 'Jam',
            self::Hari => 'Hari',
            self::Bulan => 'Bulan',
        };
    }

    /** Konversi ke detik, dipakai utk hitung Session-Timeout radius. */
    public function toSeconds(int $value): int
    {
        return match ($this) {
            self::Menit => $value * 60,
            self::Jam => $value * 3600,
            self::Hari => $value * 86400,
            self::Bulan => $value * 2592000,
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
