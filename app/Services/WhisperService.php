<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ubah pesan suara (voice note) Telegram jadi teks, pakai OpenAI Whisper.
 * Dipakai bot Outlet buat generate voucher lewat suara.
 */
class WhisperService
{
    /**
     * $audioUrl = URL unduhan file suara dari Telegram (hasil
     * TelegramService::getFileUrl()). Balik null kalau gagal.
     */
    public function transcribe(string $audioUrl): ?string
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            Log::warning('Whisper: OPENAI_API_KEY belum diisi.');

            return null;
        }

        try {
            $audioContent = Http::timeout(30)->get($audioUrl)->body();

            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->attach('file', $audioContent, 'voice.ogg')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'id',
                ]);

            if (! $response->successful()) {
                Log::warning('Whisper gagal: '.$response->body());

                return null;
            }

            return trim($response->json('text') ?? '');
        } catch (\Throwable $e) {
            Log::warning('Whisper error: '.$e->getMessage());

            return null;
        }
    }
}
