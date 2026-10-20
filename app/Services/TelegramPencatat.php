<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * TelegramService yang MENCATAT setiap pesan yang dikirim/diedit bot selama
 * memproses SATU update Telegram -- dipakai fitur "rapikan chat otomatis"
 * (App\Support\TelegramRapikanChat): setelah perintah berhasil, pesan
 * perintah & menu/pertanyaan perantara dihapus, hasil akhir disisakan.
 *
 * Perilaku kirim/edit SAMA PERSIS dengan TelegramService (semua method
 * memanggil parent) -- kelas ini hanya menambah catatan di sampingnya.
 */
class TelegramPencatat extends TelegramService
{
    /** @var array<int, array{id:int, teks:string, tombol:bool}> pesan BARU yang dikirim bot */
    public array $dikirim = [];

    /** @var array<int, array{teks:string, tombol:bool}> pesan yang DIEDIT, per message_id (isi terakhir) */
    public array $diedit = [];

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $hasil = parent::sendMessage($chatId, $text, $replyMarkup);
        // Pesan pembawa TOMBOL MENU BAWAH (reply keyboard, bukan tombol di dalam
        // pesan) ditandai "menetap": fitur rapikan chat tidak boleh menghapusnya,
        // karena tombol menunya bisa ikut hilang. Lihat TelegramRapikanChat::idSementara().
        $menetap = is_array($replyMarkup) && (isset($replyMarkup['keyboard']) || isset($replyMarkup['remove_keyboard']));
        $this->catatKirim($hasil, $text, $replyMarkup !== null, $menetap);

        return $hasil;
    }

    public function sendDocument(string $chatId, string $fileContents, string $fileName, ?string $caption = null): array
    {
        $hasil = parent::sendDocument($chatId, $fileContents, $fileName, $caption);
        $this->catatKirim($hasil, (string) $caption, false);

        return $hasil;
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        $hasil = parent::editMessageText($chatId, $messageId, $text, $replyMarkup);
        $this->diedit[$messageId] = ['teks' => $text, 'tombol' => $replyMarkup !== null];

        return $hasil;
    }

    /** Kosongkan catatan -- dipanggil sebelum memproses update baru. */
    public function mulaiCatatan(): void
    {
        $this->dikirim = [];
        $this->diedit = [];
    }

    /**
     * Hapus banyak pesan sekaligus, DIAM (tanpa log peringatan): pesan yang
     * sudah terhapus / lebih dari 48 jam / tidak boleh dihapus bot memang
     * wajar gagal dan tidak perlu mengotori log.
     *
     * Pakai deleteMessages (hapus sampai 100 pesan dalam satu panggilan);
     * kalau ditolak (API lama), jatuh ke deleteMessage satu per satu.
     *
     * @param  array<int>  $ids
     */
    public function hapusBanyakDiam(string $chatId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return;
        }

        foreach (array_chunk($ids, 100) as $potong) {
            try {
                $hasil = Http::asForm()->timeout(10)->post($this->baseUrl.'deleteMessages', [
                    'chat_id' => $chatId,
                    'message_ids' => json_encode($potong),
                ])->json() ?? [];

                if (($hasil['ok'] ?? false) === true) {
                    continue;
                }
            } catch (\Throwable) {
                // lanjut ke cara satu per satu
            }

            foreach ($potong as $id) {
                try {
                    Http::asForm()->timeout(5)->post($this->baseUrl.'deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $id,
                    ]);
                } catch (\Throwable) {
                    // sengaja diam
                }
            }
        }
    }

    protected function catatKirim(array $hasil, string $teks, bool $tombol, bool $menetap = false): void
    {
        $id = (int) ($hasil['result']['message_id'] ?? 0);

        if ($id > 0) {
            $this->dikirim[] = ['id' => $id, 'teks' => $teks, 'tombol' => $tombol, 'menetap' => $menetap];
        }
    }
}
