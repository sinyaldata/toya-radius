<?php

namespace App\Enums;

enum LimitType: string
{
    case Unlimited = 'unlimited';
    case Waktu = 'waktu';
    case Kuota = 'kuota';

    public function label(): string
    {
        return match ($this) {
            self::Unlimited => 'Unlimited',
            self::Waktu => 'Batas Waktu',
            self::Kuota => 'Batas Kuota',
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
