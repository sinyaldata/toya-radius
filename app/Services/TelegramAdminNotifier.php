<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim pesan ke ADMIN lewat bot Telegram, dari DALAM aplikasi
 * (bukan dari webhook).
 *
 * Bedanya dengan TelegramWebhookController: di webhook, token bot &
 * database tenant sudah ditentukan dari URL webhook-nya. Di sini
 * kebalikannya — kita sedang melayani request web biasa (mis. kasir
 * menyimpan setoran), tenant-nya sudah aktif, tinggal mencari token bot
 * yang benar untuk tenant itu.
 *
 * SEMUA pengiriman di sini bersifat "kalau bisa" (best effort):
 * kegagalan kirim TIDAK BOLEH menggagalkan pekerjaan aslinya. Setoran
 * kasir yang sudah tersimpan tidak boleh dibatalkan cuma karena
 * Telegram sedang tidak bisa dihubungi.
 */
class TelegramAdminNotifier
{
    /**
     * Daftar Chat ID admin dari Pengaturan > Bot Telegram.
     *
     * Boleh diisi lebih dari satu, dipisah baris baru atau koma —
     * pemisahannya dibuat SAMA PERSIS dengan yang dipakai
     * TelegramWebhookController::isAdminChat(), supaya "siapa yang
     * dianggap admin" tidak pernah berbeda antara yang boleh memberi
     * perintah dan yang menerima pemberitahuan.
     */
    public function chatIds(): array
    {
        $raw = (string) (Setting::get('telegram_admin_chat_id') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []
        )));
    }

    /**
     * Token bot untuk tenant yang SEDANG aktif.
     *
     * Tenant menyimpan tokennya di tabel pusat `tenants` (bukan di
     * settings), karena webhook harus bisa menemukan tenant lewat URL
     * SEBELUM tahu database mana yang dipakai. Instalasi utama memakai
     * tabel Setting, dengan .env sebagai cadangan.
     */
    public function token(): ?string
    {
        if (app()->bound('currentTenant')) {
            return app('currentTenant')->telegram_bot_token ?: null;
        }

        return Setting::get('telegram_bot_token')
            ?: config('services.telegram.bot_token')
            ?: null;
    }

    /**
     * Kirim satu pesan ke SEMUA admin. Mengembalikan jumlah admin yang
     * berhasil dikirimi (0 = tidak ada admin terdaftar, token kosong,
     * atau semuanya gagal).
     */
    public function kirim(string $teks): int
    {
        $token = $this->token();
        $daftar = $this->chatIds();

        if (! $token || empty($daftar)) {
            return 0;
        }

        $telegram = new TelegramService($token);
        $berhasil = 0;

        foreach ($daftar as $chatId) {
            try {
                $telegram->sendMessage($chatId, $teks);
                $berhasil++;
            } catch (\Throwable $e) {
                // Dicatat saja, tidak dilempar ulang — lihat catatan di
                // atas kelas ini soal kenapa kegagalan kirim tidak boleh
                // menjalar ke pekerjaan aslinya.
                Log::warning('Gagal kirim notifikasi Telegram ke admin', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $berhasil;
    }
}
