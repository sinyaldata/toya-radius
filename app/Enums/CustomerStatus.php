<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Isolir = 'isolir';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Suspend (Manual)',
            self::Isolir => 'Isolir (Tunggakan)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Isolir => 'danger',
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
