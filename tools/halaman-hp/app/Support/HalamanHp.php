<?php

namespace App\Support;

/**
 * Pilihan "baris per halaman" tabel untuk HP.
 *
 * Filament MENYIMPAN pilihan jumlah baris per halaman di sesi login. Kalau
 * suatu saat dipilih 500 / 1000 baris, halaman itu akan SELALU memuat ribuan
 * baris di sesi tersebut -- sangat berat di HP (terutama aplikasi Android,
 * yang sesinya terpisah dari browser). Di HP pilihannya dibatasi maksimal 100;
 * pilihan lama yang lebih besar otomatis kembali ke pilihan pertama (20).
 * Di komputer pilihan tetap utuh (mis. 1000 untuk cetak voucher massal).
 */
class HalamanHp
{
    public static function hp(): bool
    {
        $ua = (string) request()->userAgent();

        return (bool) preg_match('/Android|iPhone|iPad|Mobile|ToyaRadiusApp/i', $ua);
    }

    public static function opsi(array $opsi): array
    {
        if (! static::hp()) {
            return $opsi;
        }

        $kecil = array_values(array_filter($opsi, fn ($n) => is_int($n) && $n <= 100));

        return $kecil ?: [20, 50];
    }
}
