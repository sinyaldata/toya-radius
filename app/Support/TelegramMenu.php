<?php

namespace App\Support;

/**
 * Tombol menu PERMANEN di bawah kolom ketik Telegram (ReplyKeyboardMarkup) --
 * permintaan user 2026-10-04, meniru bot "Telegram Center Omah".
 *
 * Tombol tidak punya logika sendiri: menekannya mengirim teks labelnya sebagai
 * pesan biasa, lalu perintahDariTombol() menerjemahkannya ke perintah yang
 * SUDAH ADA (/voucher, /cek, /stok, /saldo, /offline, /tagihan, /pendapatan).
 * Semua pemeriksaan hak akses tetap dilakukan oleh perintah itu sendiri.
 *
 * Perubahan 2026-10-04 (permintaan user): "Generate Voucher" -> "Buat Voucher";
 * Reseller & Outlet mendapat Cek Voucher dan Cek Stok, tanpa Cek Status Offline.
 *
 * Kelas ini sengaja tanpa ketergantungan Laravel supaya mudah diuji.
 */
final class TelegramMenu
{
    public const VOUCHER = '🎫 Buat Voucher';

    public const CEK = '🔍 Cek Voucher';

    public const STOK = '📦 Cek Stok';

    public const SALDO = '💰 Cek Saldo';

    public const OFFLINE = '📴 Cek Status Offline';

    public const TAGIHAN = '🧾 Cek Tagihan';

    public const LAPORAN = '📊 Laporan';

    // SETORAN-KASIR-BOT
    public const SETOR = '💵 Terima Setoran';

    public const REKAP = '📋 Rekap Setoran';

    /** Label tombol => perintah yang dijalankan. */
    public const PERINTAH = [
        self::VOUCHER => '/voucher',
        self::CEK => '/cek',
        self::STOK => '/stok',
        self::SALDO => '/saldo',
        self::OFFLINE => '/offline',
        self::TAGIHAN => '/tagihan',
        self::LAPORAN => '/pendapatan',
        self::SETOR => '/setor',
        self::REKAP => '/rekapsetoran',
    ];

    /**
     * Label LAMA yang masih bisa terkirim dari tombol di HP pengguna yang belum
     * memperbarui menunya. Tetap dikenali supaya tombolnya tidak mati, dan
     * memicu pemasangan menu terbaru (lihat perluDiperbarui()).
     */
    public const LABEL_LAMA = [
        '🎫 Generate Voucher' => '/voucher',
    ];

    /**
     * Tombol yang ditampilkan per peran -- hanya yang memang BISA dipakai peran
     * itu (sama dengan aturan di masing-masing perintah):
     *  - admin    : Buat Voucher, Cek Saldo, Cek Status Offline, Cek Tagihan, Laporan.
     *  - reseller : Buat Voucher, Cek Voucher, Cek Stok, Cek Saldo, Laporan.
     *  - outlet   : Buat Voucher, Cek Voucher, Cek Stok, Laporan (tanpa Cek Saldo:
     *               kebijakan saldo hanya untuk reseller).
     *  - biller   : Cek Tagihan, Cek Status Offline (biller dilarang membuat
     *               voucher; laporannya selalu kosong).
     *  - tamu     : belum terhubung -> tanpa tombol.
     */
    public const TOMBOL_PER_PERAN = [
        'admin' => [self::VOUCHER, self::SALDO, self::OFFLINE, self::TAGIHAN, self::LAPORAN, self::REKAP],
        'reseller' => [self::VOUCHER, self::CEK, self::STOK, self::SALDO, self::LAPORAN],
        'outlet' => [self::VOUCHER, self::CEK, self::STOK, self::LAPORAN],
        'biller' => [self::TAGIHAN, self::OFFLINE],
        'kasir' => [self::SETOR, self::REKAP],
    ];

    /**
     * Sebagian aplikasi Telegram menambahkan "variation selector" tak terlihat
     * (U+FE0F) di belakang emoji -- dibuang supaya tetap cocok.
     */
    public static function normal(string $teks): string
    {
        return trim(str_replace("\u{FE0F}", '', $teks));
    }

    /**
     * Perintah untuk teks yang PERSIS sama dengan label tombol (termasuk
     * emojinya), atau null. Sengaja ketat: kata biasa yang kebetulan sama
     * ("Laporan") yang diketik di tengah alur tanya-jawab TIDAK ikut tertangkap.
     */
    public static function perintahDariTombol(string $teks): ?string
    {
        $t = self::normal($teks);

        foreach (self::PERINTAH + self::LABEL_LAMA as $label => $perintah) {
            if (self::normal($label) === $t) {
                return $perintah;
            }
        }

        return null;
    }

    /**
     * True kalau teks itu tombol menu (baru atau lama) yang TIDAK ada di menu
     * peran ini sekarang -- artinya tombol di HP pengguna masih versi lama dan
     * perlu diganti dengan menu terbaru.
     */
    public static function perluDiperbarui(string $teks, string $peran): bool
    {
        if (self::perintahDariTombol($teks) === null) {
            return false;
        }

        $t = self::normal($teks);

        foreach (self::tombolUntuk($peran) as $label) {
            if (self::normal($label) === $t) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function tombolUntuk(string $peran): array
    {
        return self::TOMBOL_PER_PERAN[$peran] ?? [];
    }

    /**
     * Payload reply_markup untuk Telegram: 2 tombol per baris, tombol terakhir
     * yang sendirian otomatis melebar penuh (seperti contoh). Null kalau tidak
     * ada tombol untuk peran itu.
     *
     * @param  list<string>  $tombol
     */
    public static function keyboard(array $tombol): ?array
    {
        if ($tombol === []) {
            return null;
        }

        return [
            'keyboard' => array_chunk(array_map(fn (string $t) => ['text' => $t], array_values($tombol)), 2),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'Pilih menu di bawah, atau ketik perintah',
        ];
    }

    /** Payload untuk menyembunyikan tombol menu. */
    public static function hapus(): array
    {
        return ['remove_keyboard' => true];
    }
}
