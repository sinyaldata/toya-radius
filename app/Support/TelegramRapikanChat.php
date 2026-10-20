<?php

namespace App\Support;

use App\Services\TelegramPencatat;
use Illuminate\Support\Facades\Cache;

/**
 * "Rapikan chat otomatis" untuk bot Telegram (permintaan user 2026-09-29):
 * setelah perintah BERHASIL, pesan perintah + menu/pertanyaan perantara
 * dihapus, HASIL AKHIR disisakan.
 *
 * ATURAN (dipanggil SEKALI di akhir setiap update yang sukses diproses):
 *
 *  1. Selama alur tanya-jawab masih berjalan (state *FlowKey di Cache ada),
 *     pesan pengguna & pesan bot di update itu DIKUMPULKAN ke "sampah"
 *     (belum dihapus) -- termasuk pesan validasi "ketik lagi".
 *  2. Begitu alur selesai / perintah biasa berhasil: sampah + pesan
 *     perintah pengguna + pesan proses (⏳ / 🎙️) dihapus sekaligus.
 *     Pesan yang dikirim/diedit di update terakhir itu = HASIL AKHIR ->
 *     TIDAK PERNAH dihapus (kecuali pesan proses).
 *  3. Menu "Pilih ..." yang ditekan: dihapus HANYA kalau hasilnya dikirim
 *     sebagai pesan/berkas BARU. Kalau menunya sendiri diedit menjadi
 *     hasil, pesan itu sudah jadi hasil akhir -> dibiarkan.
 *  4. GAGAL (❌, ⚠️, "tidak ditemukan", "belum terhubung", dst.) ->
 *     TIDAK ADA yang dihapus, supaya pengguna masih melihat apa yang
 *     salah. Kalau ragu, lebih baik TIDAK menghapus.
 *
 * Batas Telegram: pesan > 48 jam tidak bisa dihapus bot (gagal diam-diam);
 * di grup, bot harus admin dengan izin hapus pesan.
 */
class TelegramRapikanChat
{
    protected const AWALAN_GAGAL = [
        '❌', '⚠️', '⚠',
        'Perintah tidak dikenali', 'Perintah ini khusus', 'Akun Telegram ini belum terhubung',
        'Akun ini', 'Tidak ada ', 'Pelanggan tidak ditemukan', 'Outlet tidak ditemukan',
        'Gagal', 'Maaf', 'Sesi ', 'Belum ada', 'Ketik bersama', 'Username tidak boleh',
        'Nama tidak boleh', 'Password minimal', 'Tekan tombol',
    ];

    protected const ISI_GAGAL = ['tidak ditemukan', 'belum terhubung', 'tidak dikenali', 'tidak berlaku'];

    /** Perintah yang jawabannya memang daftar bantuan (diawali "Perintah tidak dikenali") -- itu bukan kegagalan. */
    protected const PERINTAH_BANTUAN = ['/start', '/bantuan', '/help', '/menu'];

    public function __construct(protected string $kunciCache) {}

    public static function untuk(string $bot, string $chatId): self
    {
        return new self('tg_sampah:'.$bot.':'.$chatId);
    }

    public static function polos(string $teks): string
    {
        return trim(html_entity_decode(strip_tags($teks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function isGagal(string $teks): bool
    {
        $p = self::polos($teks);

        if ($p === '') {
            return false;
        }

        foreach (self::AWALAN_GAGAL as $awal) {
            if (str_starts_with($p, $awal)) {
                return true;
            }
        }

        $kecil = mb_strtolower($p);

        foreach (self::ISI_GAGAL as $isi) {
            if (str_contains($kecil, $isi)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ID pesan terkirim yang BOLEH masuk daftar hapus: semua kecuali yang
     * "menetap" (pembawa tombol menu bawah -- lihat TelegramPencatat), supaya
     * tombol menu tidak ikut hilang saat sisa alur dibersihkan.
     *
     * @param  array<int, array{id:int, menetap?:bool}>  $dikirim
     * @return array<int, int>
     */
    public static function idSementara(array $dikirim): array
    {
        return array_values(array_column(array_filter($dikirim, fn ($m) => empty($m['menetap'])), 'id'));
    }

    public static function isProses(string $teks): bool
    {
        $p = self::polos($teks);

        return str_starts_with($p, '⏳') || str_starts_with($p, '🎙');
    }

    public static function isMenu(string $teks): bool
    {
        $p = self::polos($teks);
        $kecil = mb_strtolower($p);
        $akhir = mb_substr(rtrim($p), -1);

        return ($akhir === ':' || $akhir === '?')
            && (str_contains($kecil, 'pilih') || str_starts_with($kecil, 'mau '));
    }

    /** @return array<int> */
    public function sampah(): array
    {
        return (array) Cache::get($this->kunciCache, []);
    }

    /** @param array<int> $ids */
    protected function simpanSampah(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            Cache::forget($this->kunciCache);

            return;
        }

        // < 48 jam: lewat dari itu Telegram memang tidak mengizinkan hapus.
        Cache::put($this->kunciCache, $ids, now()->addHours(47));
    }

    /**
     * Setelah PESAN pengguna (teks/suara) diproses.
     *
     * @return array<int> id yang dihapus (untuk pengujian/log)
     */
    public function setelahPesan(TelegramPencatat $tg, string $chatId, int $pesanPengguna, string $teksPengguna, bool $alurAktif): array
    {
        $adaKeluaran = $tg->dikirim !== [] || $tg->diedit !== [];

        if ($alurAktif) {
            $this->simpanSampah(array_merge($this->sampah(), [$pesanPengguna], self::idSementara($tg->dikirim)));

            return [];
        }

        if (! $adaKeluaran) {
            return [];
        }

        $bantuan = in_array(mb_strtolower(trim(explode(' ', $teksPengguna)[0] ?? '')), self::PERINTAH_BANTUAN, true);

        if (! $bantuan && $this->adaGagal($tg)) {
            // Salah ketik perintah -> jawabannya daftar bantuan panjang.
            // Dibiarkan terlihat dulu, tapi dicatat sebagai sampah supaya
            // ikut bersih begitu perintah berikutnya berhasil. Kegagalan
            // LAIN (❌, tidak ditemukan, dst.) tetap dibiarkan permanen.
            $salahKetik = collect($tg->dikirim)->contains(fn ($m) => str_starts_with(self::polos($m['teks']), 'Perintah tidak dikenali'));

            if ($salahKetik) {
                $this->simpanSampah(array_merge($this->sampah(), [$pesanPengguna], self::idSementara($tg->dikirim)));
            }

            return [];
        }

        return $this->bersihkan($tg, $chatId, [$pesanPengguna]);
    }

    /**
     * Setelah TOMBOL (callback) ditekan pada pesan $pesanDitekan.
     *
     * @return array<int> id yang dihapus (untuk pengujian/log)
     */
    public function setelahTombol(TelegramPencatat $tg, string $chatId, int $pesanDitekan, string $teksDitekan, bool $alurAktif): array
    {
        if ($tg->dikirim === [] && $tg->diedit === []) {
            return [];
        }

        $ditekanDiedit = array_key_exists($pesanDitekan, $tg->diedit);
        $menuDiganti = ! $ditekanDiedit && $tg->dikirim !== [] && self::isMenu($teksDitekan);

        if ($alurAktif) {
            $tambah = self::idSementara($tg->dikirim);

            if ($ditekanDiedit || $menuDiganti) {
                $tambah[] = $pesanDitekan;
            }

            $this->simpanSampah(array_merge($this->sampah(), $tambah));

            return [];
        }

        if ($this->adaGagal($tg)) {
            return [];
        }

        return $this->bersihkan($tg, $chatId, $menuDiganti ? [$pesanDitekan] : []);
    }

    protected function adaGagal(TelegramPencatat $tg): bool
    {
        foreach ($tg->dikirim as $m) {
            if (self::isGagal($m['teks'])) {
                return true;
            }
        }

        foreach ($tg->diedit as $m) {
            if (self::isGagal($m['teks'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hapus sampah + $tambahan + pesan proses; pesan hasil akhir di update
     * ini SELALU dilindungi.
     *
     * @param  array<int>  $tambahan
     * @return array<int>
     */
    protected function bersihkan(TelegramPencatat $tg, string $chatId, array $tambahan): array
    {
        $hapus = array_merge($this->sampah(), $tambahan);
        $lindungi = [];

        foreach ($tg->dikirim as $m) {
            self::isProses($m['teks']) ? $hapus[] = $m['id'] : $lindungi[] = $m['id'];
        }

        foreach ($tg->diedit as $id => $m) {
            self::isProses($m['teks']) ? $hapus[] = (int) $id : $lindungi[] = (int) $id;
        }

        $hapus = array_values(array_diff(array_unique(array_map('intval', $hapus)), $lindungi));

        Cache::forget($this->kunciCache);
        $tg->hapusBanyakDiam($chatId, $hapus);

        return $hapus;
    }
}
