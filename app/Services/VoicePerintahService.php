<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kenali TRANSKRIP suara (hasil WhisperService) jadi salah satu PERINTAH
 * TEKS bot yang sudah ada (mis. "/bayar Lalu Fathurrahman"), pakai OpenAI
 * (model chat biasa, BUKAN Whisper -- Whisper cuma ubah suara jadi teks,
 * ini yang baca ARTI teksnya).
 *
 * Dipakai TelegramWebhookController::handleVoiceNote() sebagai LAPISAN
 * KEDUA -- lapisan pertama (pencocokan angka paket+jumlah buat generate
 * voucher, sudah lama ada & terbukti akurat) dicoba DULU; service ini
 * cuma dipanggil kalau lapisan pertama itu tidak cocok, supaya SEMUA
 * perintah lain (bayar, isolir, ganti paket, dst) ikut bisa dipakai
 * lewat suara juga -- bukan cuma generate voucher.
 *
 * Hasil dari kenali() itu TEKS PERINTAH APA ADANYA (mis. "/bayar Budi"),
 * yang lalu oleh pemanggilnya dikirim ulang ke handleMessage() PERSIS
 * seolah-olah user mengetik perintah itu sendiri -- jadi SEMUA
 * pengecekan hak akses & alur konfirmasi tiap perintah (yang sudah ada
 * dari dulu) otomatis ikut berlaku, TIDAK ada yang ditulis ulang di
 * sini. Service ini cuma tugas menerjemahkan ucapan jadi teks perintah,
 * bukan mengeksekusi apa pun sendiri.
 */
class VoicePerintahService
{
    /**
     * Daftar perintah yang boleh dikenali dari suara, sinkron dengan
     * teks /bantuan di TelegramWebhookController. Kalau ada perintah
     * BARU ditambahkan ke bot nanti, tambahkan juga satu baris di sini
     * supaya ikut bisa dipakai lewat suara.
     */
    protected const KATALOG_PERINTAH = <<<'TEKS'
        /voucher -- mulai buat voucher baru (sistem akan tanya paket & jumlah lewat tombol setelah ini, JANGAN sisipkan nama paket/jumlah ke perintah ini)
        /cek KODEVOUCHER -- cek status satu voucher, contoh: /cek ABCD1234
        /putusvoucher KODEVOUCHER -- putus paksa voucher yang sedang online
        /stok -- lihat sisa stok voucher
        /batch -- riwayat batch generate voucher
        /saldo -- lihat saldo & riwayat top up outlet
        /topup JUMLAH -- minta top up saldo, contoh: /topup 100000
        /pppoe -- mulai alur tambah pelanggan PPPoE baru (tidak perlu data tambahan di sini, sistem akan tanya satu-satu setelahnya)
        /addstaticip -- mulai alur tambah pelanggan Static IP baru
        /addmember -- mulai alur tambah Member Hotspot (langganan bulanan) baru
        /daftarmember -- lihat daftar Member yang sudah ada
        /pengeluaran -- mulai alur catat pengeluaran outlet
        /offline -- lihat pelanggan PPPoE yang sedang offline
        /pendapatan -- laporan pendapatan ringkas
        /laporanpdf -- laporan pendapatan lengkap dalam bentuk PDF
        /gantipaket NAMA -- ganti paket langganan seorang pelanggan, contoh: /gantipaket Budi Santoso
        /outletnunggak -- daftar outlet yang belum setor bulan ini
        /tagihan -- daftar pelanggan yang tagihannya belum lunas
        /bayar NAMA -- catat pembayaran tagihan seorang pelanggan, contoh: /bayar Lalu Fathurrahman
        /cetakstruk NAMA -- cetak ulang struk pembayaran terakhir seorang pelanggan
        /isolirinfo -- jumlah pelanggan isolir & suspend saat ini
        /isolir NAMA -- isolir manual seorang pelanggan
        /bukaisolir NAMA -- buka isolir / aktifkan kembali seorang pelanggan
        /nonaktifkan NAMA -- nonaktifkan seorang pelanggan secara manual (BUKAN karena nunggak)
        /addoutlet -- mulai alur tambah Outlet baru (tidak perlu data tambahan di sini, sistem akan tanya satu-satu setelahnya: nama, pemilik, whatsapp, area, lalu data login)
        /addreseller -- mulai alur tambah Reseller baru (sama seperti /addoutlet, cuma jenis mitranya beda)
        TEKS;

    /**
     * @return string|null Teks perintah yang dikenali (mis. "/bayar
     *                      Budi"), atau null kalau tidak ada satu pun
     *                      perintah yang cocok / API-nya gagal.
     */
    public function kenali(string $transkrip): ?string
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            Log::warning('VoicePerintahService: OPENAI_API_KEY belum diisi.');

            return null;
        }

        $sistemPrompt = <<<PROMPT
            Kamu penerjemah perintah suara untuk bot Telegram aplikasi billing ISP "Toya Radius". Baca TRANSKRIP ucapan pengguna (Bahasa Indonesia, kadang tidak baku/terpotong/typo dari hasil transkripsi), lalu tentukan SATU perintah teks yang PALING SESUAI dari daftar di bawah ini.

            DAFTAR PERINTAH YANG TERSEDIA:
            {$this->katalogPerintah()}

            ATURAN JAWABAN (WAJIB DIIKUTI PERSIS):
            - Balas HANYA dengan teks perintahnya saja (contoh: "/bayar Lalu Fathurrahman"). JANGAN tambahkan tanda kutip, penjelasan, atau kata lain apa pun di luar itu.
            - Kalau perintah itu butuh NAMA (mis. /bayar, /isolir, /bukaisolir, /nonaktifkan, /gantipaket, /cetakstruk), tulis NAMA itu APA ADANYA persis seperti disebutkan di transkrip (jangan disingkat, jangan diubah ejaannya, jangan ditambah "An"/"Bapak"/"Ibu" dsb), langsung setelah nama perintahnya dengan satu spasi.
            - "bayar", "sudah bayar", "pembayaran masuk", "lunas" + nama orang → /bayar NAMA (nominal yang disebut di ucapan TIDAK perlu dan TIDAK BOLEH dimasukkan ke perintah -- sistem akan menampilkan tagihan aslinya sendiri untuk dikonfirmasi).
            - "isolir", "putus", "blokir" (BUKAN "buka isolir") + nama orang → /isolir NAMA.
            - "buka isolir", "aktifkan", "sambungkan lagi", "nyalakan lagi" + nama orang → /bukaisolir NAMA.
            - "nonaktifkan", "berhenti langganan", "stop" (bukan karena nunggak) + nama orang → /nonaktifkan NAMA.
            - "ganti paket", "upgrade", "downgrade", "naikkan paket", "turunkan paket" + nama orang → /gantipaket NAMA.
            - "cetak struk", "cetak ulang", "print invoice" + nama orang → /cetakstruk NAMA.
            - Kalau TIDAK ADA satu pun perintah di atas yang cocok, atau maksudnya ambigu/tidak jelas, balas PERSIS teks ini: TIDAK_JELAS

            Transkrip: "{$transkrip}"
            PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0,
                    'max_tokens' => 40,
                    'messages' => [
                        ['role' => 'system', 'content' => $sistemPrompt],
                        ['role' => 'user', 'content' => $transkrip],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('VoicePerintahService gagal: '.$response->body());

                return null;
            }

            $hasil = trim((string) $response->json('choices.0.message.content'));

            if ($hasil === '' || $hasil === 'TIDAK_JELAS' || ! str_starts_with($hasil, '/')) {
                return null;
            }

            return $hasil;
        } catch (\Throwable $e) {
            Log::warning('VoicePerintahService error: '.$e->getMessage());

            return null;
        }
    }

    protected function katalogPerintah(): string
    {
        return self::KATALOG_PERINTAH;
    }
}
