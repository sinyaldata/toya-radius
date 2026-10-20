<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Lunas = 'lunas';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Lunas => 'Lunas',
            self::Pending => 'Pending',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Lunas => 'success',
            self::Pending => 'warning',
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
