<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pembungkus tipis Telegram Bot API — dipakai bot Outlet buat generate
 * voucher lewat chat. Token diambil dari config('services.telegram.bot_token').
 */
class TelegramService
{
    protected string $baseUrl;

    public function __construct(?string $token = null)
    {
        $token = $token ?? config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->call('sendMessage', $payload);
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->call('editMessageText', $payload);
    }

    /**
     * Hapus pesan (dipakai alur /wifi untuk menghapus pesan yang berisi
     * kata sandi baru dari riwayat chat). Di chat pribadi bot boleh menghapus
     * pesan yang masuk; hasilnya TIDAK dianggap wajib berhasil.
     */
    public function deleteMessage(string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', ['url' => $url]);
    }

    /**
     * Kirim FILE (dokumen) ke chat -- dipakai buat kirim PDF daftar
     * voucher hasil generate massal lewat bot (permintaan user: jumlah
     * banyak lebih enak diunduh sebagai PDF daripada di-dump jadi teks
     * panjang di chat, yang lagipula bisa kepotong -- pesan Telegram
     * dibatasi 4096 karakter).
     *
     * $fileContents adalah ISI file mentah (bytes), BUKAN path -- supaya
     * pemanggilnya tidak perlu menulis file sementara ke disk kalau tidak
     * mau (walau boleh saja pakai file_get_contents(path) juga).
     */
    public function sendDocument(string $chatId, string $fileContents, string $fileName, ?string $caption = null): array
    {
        try {
            $request = Http::timeout(30)->attach('document', $fileContents, $fileName);

            $payload = array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => $caption ? 'HTML' : null,
            ]);

            $response = $request->post($this->baseUrl.'sendDocument', $payload);

            $result = $response->json() ?? [];

            if (($result['ok'] ?? true) === false) {
                Log::warning('Telegram API menolak (sendDocument)', $result);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Telegram API gagal (sendDocument): '.$e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function call(string $method, array $payload): array
    {
        try {
            $response = Http::asForm()->timeout(10)->post($this->baseUrl.$method, $payload);

            $result = $response->json() ?? [];

            if (($result['ok'] ?? true) === false) {
                Log::warning("Telegram API menolak ({$method})", $result);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning("Telegram API gagal ({$method}): ".$e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    /**
     * Ambil URL unduhan file (voice note, foto, dsb) dari Telegram
     * lewat file_id-nya. Balik null kalau gagal.
     */
    public function getFileUrl(string $fileId): ?string
    {
        $result = $this->call('getFile', ['file_id' => $fileId]);

        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $filePath = $result['result']['file_path'] ?? null;

        if (! $filePath) {
            return null;
        }

        $token = config('services.telegram.bot_token');

        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }
}
