<?php

namespace App\Enums;

enum VoucherCodeCombination: string
{
    case AlnumLower = 'alnum_lower'; // angka + huruf kecil
    case AlnumUpper = 'alnum_upper'; // angka + huruf besar
    case MixedCase = 'mixed_case'; // huruf besar + huruf kecil
    case NumericOnly = 'numeric_only'; // hanya angka
    case UpperOnly = 'upper_only'; // hanya huruf besar
    case LowerOnly = 'lower_only'; // hanya huruf kecil

    public function label(): string
    {
        return match ($this) {
            self::AlnumLower => 'Angka + Huruf Kecil',
            self::AlnumUpper => 'Angka + Huruf Besar',
            self::MixedCase => 'Huruf Besar dan Huruf Kecil',
            self::NumericOnly => 'Hanya Angka',
            self::UpperOnly => 'Hanya Huruf Besar',
            self::LowerOnly => 'Hanya Huruf Kecil',
        };
    }

    public function charset(): string
    {
        return match ($this) {
            self::AlnumLower => '0123456789abcdefghijklmnopqrstuvwxyz',
            self::AlnumUpper => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            self::MixedCase => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            self::NumericOnly => '0123456789',
            self::UpperOnly => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            self::LowerOnly => 'abcdefghijklmnopqrstuvwxyz',
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
