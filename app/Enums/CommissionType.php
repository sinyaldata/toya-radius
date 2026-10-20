<?php

namespace App\Enums;

/**
 * BARU (permintaan user: "buatkan fitur bagaimana menerapkan komisi
 * pada outlet/reseller jika tidak menggunakan persentase") -- kolom
 * commission_flat_amount SEBENARNYA sudah ada dari sebelumnya di
 * database (terlihat di $fillable Outlet.php, dipakai di Import/
 * Export CSV), tapi tidak pernah benar-benar dipakai di perhitungan
 * komisi manapun -- cuma "tertanam" tanpa fungsi. Enum ini yang
 * menentukan MANA dari dua kolom (commission_percent ATAU
 * commission_flat_amount) yang benar-benar dipakai buat outlet
 * tersebut, supaya tidak ambigu (misalnya kalau dua-duanya kebetulan
 * terisi angka).
 */
enum CommissionType: string
{
    case Percent = 'percent';
    case Markup = 'markup';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Persentase (%)',
            self::Markup => 'Selisih Harga Jual - Harga Beli Voucher',
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
