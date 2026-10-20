<?php

namespace App\Support;

/**
 * Kolom "dibekukan" (sticky) di kiri tabel saat digeser mendatar --
 * dipakai di tabel mana pun yang kolomnya banyak (>7-an), supaya kolom
 * identitas baris (No, Nama/Username, dst.) tidak hilang dari layar
 * begitu digeser ke kanan untuk lihat kolom lain.
 *
 * WAJIB LEWAT GAYA INLINE (style=) DENGAN !important, BUKAN cuma kelas
 * CSS -- compiled Filament CSS (Tailwind v4) memakai @layer, dan
 * !important di stylesheet BIASA (atau gaya inline TANPA !important)
 * SELALU KALAH lawan !important di dalam @layer manapun, apa pun
 * urutannya di HTML. Sudah dibuktikan lewat uji render nyata (kasus
 * pertama: OutletResource/ResellerResource/BillerResource).
 *
 * Kelas 'tya-sticky-col' (lihat global-table-style.blade.php) dipakai
 * HANYA untuk background & garis pemisah -- dua hal itu TIDAK kena
 * masalah @layer, aman lewat class biasa.
 *
 * CARA PAKAI di table(): panggil untuk kolom PERTAMA (biasanya 'index'
 * a.k.a. "No"), lalu kolom kedua/ketiga, dengan $kiriRem KUMULATIF
 * (lebar kolom sebelumnya dijumlahkan) -- lihat contoh di
 * OutletResource::table() atau PppoeUserResource::table().
 *
 *   Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()
 *       ->extraHeaderAttributes(StickyColumn::header(0, 2.75, terakhir: false))
 *       ->extraCellAttributes(StickyColumn::cell(0, 2.75, terakhir: false)),
 *   Tables\Columns\TextColumn::make('name')->label('Nama')
 *       ->extraHeaderAttributes(StickyColumn::header(2.75, 9, terakhir: true))
 *       ->extraCellAttributes(StickyColumn::cell(2.75, 9, terakhir: true)),
 */
class StickyColumn
{
    public static function header(float $kiriRem, float $lebarRem, bool $terakhir = false): array
    {
        return ['class' => 'tya-sticky-col', 'style' => static::gaya($kiriRem, $lebarRem, 3, $terakhir)];
    }

    public static function cell(float $kiriRem, float $lebarRem, bool $terakhir = false): array
    {
        return ['class' => 'tya-sticky-col', 'style' => static::gaya($kiriRem, $lebarRem, 2, $terakhir)];
    }

    protected static function gaya(float $kiriRem, float $lebarRem, int $z, bool $terakhir): string
    {
        $gaya = "position:sticky !important;left:{$kiriRem}rem !important;z-index:{$z} !important;"
            ."min-width:{$lebarRem}rem !important;max-width:{$lebarRem}rem !important;"
            .'overflow:hidden !important;text-overflow:ellipsis !important;white-space:nowrap !important;';

        if ($terakhir) {
            // Garis pemisah tipis di kolom sticky TERAKHIR -- penanda visual
            // "sampai sini yang dibekukan". Ditulis LENGKAP (bukan cuma
            // -width) supaya tidak butuh warna/gaya dari CSS lain --
            // abu-abu netral yang cukup terlihat di mode gelap MAUPUN
            // terang tanpa perlu menyamakan token warna persis.
            $gaya .= 'border-inline-end:1px solid rgba(128,128,128,.35) !important;';
        }

        return $gaya;
    }
}
