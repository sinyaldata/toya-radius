<?php

namespace App\Enums;

enum VoucherLoginMethod: string
{
    case UserPass = 'user_pass'; // username + password beda
    case UserOnly = 'user_only'; // username = password (1 kode saja)

    public function label(): string
    {
        return match ($this) {
            self::UserPass => 'Username + Password (2 kode berbeda)',
            self::UserOnly => 'Username = Password (1 kode)',
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
