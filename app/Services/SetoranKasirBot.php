<?php

namespace App\Services;

use App\Enums\PartnerType;
use App\Models\CashierDeposit;
use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SETORAN MITRA LEWAT BOT TELEGRAM -- untuk KASIR (permintaan user 2026-10-08).
 *
 * Kasir mencatat uang yang diterimanya dari mitra langsung dari Telegram:
 *   - Outlet   : hasil jual voucher
 *   - Reseller : top up saldo
 * (Biller sengaja tidak dilayani di sini.)
 *
 * Yang dicatat masuk ke tabel `cashier_deposits` YANG SAMA dengan halaman
 * Kasir di aplikasi, jadi:
 *   - CashierDepositObserver otomatis memberi tahu ADMIN lewat Telegram
 *     (tidak ada pemberitahuan kedua dari sini -> tidak dobel),
 *   - kartu "Mitra Sudah Setor" & perintah /outletnunggak ikut terhitung,
 *   - TIDAK ada pesan apa pun ke mitra (permintaan user: "hanya catat dan rekap").
 *
 * Siapa kasir? Setting `telegram_kasir_chat_id` (daftar Chat ID dipisah koma,
 * dikelola admin lewat perintah /kasir). Kasir hanya bisa memakai /setor dan
 * /rekapsetoran -- BUKAN perintah admin. Admin boleh /rekapsetoran dan /kasir.
 *
 * Kelas ini dipanggil dari TelegramWebhookController lewat dua pintu kecil
 * (handlePesan & handleCallback); semua akses data lewat method protected di
 * bagian bawah supaya mudah diuji tanpa database.
 */
class SetoranKasirBot
{
    public const SETTING_KASIR = 'telegram_kasir_chat_id';

    /** '1' = setoran Reseller JUGA menambah saldo reseller (default mati). */
    public const SETTING_SALDO = 'kasir_setor_tambah_saldo_reseller';

    public const PER_HALAMAN = 8;

    public const BATAS_MAKS = 500000000;

    public const MENIT_SESI = 15;

    public function __construct(protected TelegramService $telegram) {}

    // ------------------------------------------------------------------
    // Daftar kasir & kunci alur
    // ------------------------------------------------------------------

    /** @return list<string> */
    public static function daftarKasir(): array
    {
        $raw = (string) (Setting::get(self::SETTING_KASIR) ?? '');

        return array_values(array_unique(preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public static function adalahKasir(string $chatId): bool
    {
        if ($chatId === '') {
            return false;
        }

        foreach (self::daftarKasir() as $id) {
            if (hash_equals($id, $chatId)) {
                return true;
            }
        }

        return false;
    }

    public static function kunciAlur(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_setor_flow:{$namespace}:{$chatId}";
    }

    // ------------------------------------------------------------------
    // Pesan teks
    // ------------------------------------------------------------------

    /**
     * @return bool true = pesan sudah ditangani di sini (controller berhenti).
     */
    public function handlePesan(string $chatId, string $text, array $message, bool $admin): bool
    {
        $kasir = self::adalahKasir($chatId);
        $cmd = mb_strtolower(trim(explode(' ', $text)[0] ?? ''));
        $cmd = (string) preg_replace('/@\w+$/', '', $cmd);

        if ($cmd === '/kasir') {
            if (! $admin) {
                return false;
            }

            $this->perintahKelolaKasir($chatId, $text);

            return true;
        }

        if ($cmd === '/rekapsetoran') {
            if (! $kasir && ! $admin) {
                return false;
            }

            $this->telegram->sendMessage($chatId, 'Pilih periode rekap setoran:', ['inline_keyboard' => $this->tombolPeriode()]);

            return true;
        }

        if ($cmd === '/setor') {
            if (! $kasir) {
                if ($admin) {
                    $this->telegram->sendMessage($chatId, "Perintah ini khusus kasir.\n\nUntuk mendaftarkan Chat ID ini sebagai kasir, kirim:\n<code>/kasir tambah {$chatId}</code>");

                    return true;
                }

                return false;
            }

            $this->mulai($chatId, $message);

            return true;
        }

        if ($kasir && ! $admin && in_array($cmd, ['/bantuan', '/help'], true)) {
            $this->telegram->sendMessage($chatId, $this->teksBantuan());

            return true;
        }

        if (! $kasir) {
            return false;
        }

        $state = Cache::get(self::kunciAlur($chatId));

        if (! is_array($state)) {
            return false;
        }

        if ($cmd === '/batal') {
            Cache::forget(self::kunciAlur($chatId));
            $this->telegram->sendMessage($chatId, 'Pencatatan setoran dibatalkan.');

            return true;
        }

        if (str_starts_with($text, '/')) {
            // Perintah lain = pindah pekerjaan, alur setoran batal diam-diam.
            Cache::forget(self::kunciAlur($chatId));

            return false;
        }

        $this->lanjutkan($chatId, $text, $state);

        return true;
    }

    public function teksBantuan(): string
    {
        return "<b>Perintah Kasir</b>\n"
            ."/setor — catat setoran dari mitra (Outlet: hasil jual voucher, Reseller: top up saldo)\n"
            ."/rekapsetoran — rekap setoran hari ini / kemarin / bulan ini / bulan lalu\n"
            ."/batal — batalkan pencatatan yang sedang berjalan\n"
            ."/menu — tampilkan tombol menu";
    }

    // ------------------------------------------------------------------
    // Admin: /kasir
    // ------------------------------------------------------------------

    protected function perintahKelolaKasir(string $chatId, string $text): void
    {
        $bagian = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $aksi = mb_strtolower($bagian[1] ?? '');
        $nilai = $bagian[2] ?? '';
        $daftar = self::daftarKasir();

        if ($aksi === 'tambah') {
            if (! preg_match('/^-?\d{5,20}$/', $nilai)) {
                $this->telegram->sendMessage($chatId, "⚠️ Chat ID tidak valid. Contoh: <code>/kasir tambah 123456789</code>\n\nMinta calon kasir mengirim /admin ke bot ini untuk melihat Chat ID-nya.");

                return;
            }

            if (in_array($nilai, $daftar, true)) {
                $this->telegram->sendMessage($chatId, "ℹ️ Chat ID <code>{$nilai}</code> sudah terdaftar sebagai kasir.");

                return;
            }

            $daftar[] = $nilai;
            $this->simpanSetting(self::SETTING_KASIR, implode(',', $daftar));
            $this->telegram->sendMessage($chatId, "✅ Kasir ditambahkan: <code>{$nilai}</code>\nTotal kasir: ".count($daftar)."\n\nKasir itu sekarang bisa memakai /setor dan /rekapsetoran. Suruh dia kirim /menu supaya tombolnya muncul.");

            return;
        }

        if ($aksi === 'hapus') {
            if (! in_array($nilai, $daftar, true)) {
                $this->telegram->sendMessage($chatId, "⚠️ Chat ID <code>".$this->h($nilai)."</code> tidak ada di daftar kasir.");

                return;
            }

            $daftar = array_values(array_diff($daftar, [$nilai]));
            $this->simpanSetting(self::SETTING_KASIR, implode(',', $daftar));
            Cache::forget(self::kunciAlur($nilai));
            $this->telegram->sendMessage($chatId, "✅ Kasir dihapus: <code>{$nilai}</code>\nSisa kasir: ".count($daftar));

            return;
        }

        if ($aksi === 'saldo') {
            $pilihan = mb_strtolower($nilai);

            if (! in_array($pilihan, ['on', 'off'], true)) {
                $this->telegram->sendMessage($chatId, "⚠️ Pakai: <code>/kasir saldo on</code> atau <code>/kasir saldo off</code>");

                return;
            }

            $this->simpanSetting(self::SETTING_SALDO, $pilihan === 'on' ? '1' : '0');
            $this->telegram->sendMessage($chatId, $pilihan === 'on'
                ? "✅ Setoran <b>Reseller</b> sekarang juga MENAMBAH saldo reseller (sekali, saat disimpan)."
                : "✅ Setoran <b>Reseller</b> sekarang HANYA dicatat. Saldo reseller tidak berubah (tambah saldo lewat /topup atau aplikasi).");

            return;
        }

        $teks = "<b>Pengaturan Kasir</b>\n\n";
        $teks .= $daftar === []
            ? "Belum ada kasir terdaftar.\n"
            : "Kasir terdaftar:\n".implode("\n", array_map(fn ($id) => "• <code>{$id}</code>", $daftar))."\n";
        $teks .= "\nSetoran Reseller menambah saldo: <b>".($this->saldoResellerAktif() ? 'YA' : 'TIDAK (hanya dicatat)')."</b>\n\n";
        $teks .= "<code>/kasir tambah CHATID</code> — daftarkan kasir\n";
        $teks .= "<code>/kasir hapus CHATID</code> — cabut akses kasir\n";
        $teks .= "<code>/kasir saldo on|off</code> — setoran Reseller ikut menambah saldo\n\n";
        $teks .= "<i>Chat ID didapat dengan mengirim /admin ke bot ini dari akun kasir.</i>";
        $this->telegram->sendMessage($chatId, $teks);
    }

    // ------------------------------------------------------------------
    // Alur /setor
    // ------------------------------------------------------------------

    protected function mulai(string $chatId, array $message): void
    {
        $this->simpanState($chatId, [
            'langkah' => 'jenis',
            'kasir_nama' => $this->namaPengirim($message, $chatId),
        ]);

        $this->telegram->sendMessage($chatId, 'Pilih jenis mitra yang menyetor:', ['inline_keyboard' => [
            [['text' => '🏪 Outlet — hasil jual voucher', 'callback_data' => 'setor:j:outlet']],
            [['text' => '🔁 Reseller — top up saldo', 'callback_data' => 'setor:j:reseller']],
            [['text' => '❌ Batal', 'callback_data' => 'setor:x']],
        ]]);
    }

    protected function lanjutkan(string $chatId, string $text, array $state): void
    {
        $langkah = $state['langkah'] ?? '';

        if ($langkah === 'mitra') {
            $cari = trim($text);
            $hasil = $this->daftarMitra((string) $state['jenis'], $cari);

            if ($hasil === []) {
                $this->telegram->sendMessage($chatId, "Tidak ada ".$this->namaJenis($state['jenis'])." dengan nama mirip \"".$this->h($cari)."\". Ketik nama lain, atau /batal.");

                return;
            }

            if (count($hasil) === 1) {
                $this->pilihMitra($chatId, null, $state, $this->ambilMitra($hasil[0]['id'], (string) $state['jenis']) ?? $hasil[0]);

                return;
            }

            $state['cari'] = $cari;
            $this->simpanState($chatId, $state);
            [$teks, $tombol] = $this->tampilanDaftar($state, 1);
            $this->telegram->sendMessage($chatId, $teks, ['inline_keyboard' => $tombol]);

            return;
        }

        if ($langkah === 'jumlah' || $langkah === 'konfirmasi') {
            $jumlah = self::parseJumlah($text);

            if ($jumlah === null) {
                $this->telegram->sendMessage($chatId, "⚠️ Jumlah tidak valid. Ketik angka saja, contoh: <code>150000</code>, <code>150.000</code> atau <code>150rb</code> (maksimal ".$this->rp(self::BATAS_MAKS).").");

                return;
            }

            $state['jumlah'] = $jumlah;
            $state['langkah'] = 'konfirmasi';
            $this->simpanState($chatId, $state);
            [$teks, $tombol] = $this->tampilanKonfirmasi($state);
            $this->telegram->sendMessage($chatId, $teks, ['inline_keyboard' => $tombol]);

            return;
        }

        $this->telegram->sendMessage($chatId, 'Tekan tombol di pesan di atas untuk melanjutkan, atau ketik /batal.');
    }

    // ------------------------------------------------------------------
    // Tombol (callback)
    // ------------------------------------------------------------------

    /**
     * @return bool true = callback "setor:*" ini sudah ditangani di sini.
     */
    public function handleCallback(string $chatId, int $messageId, string $data, bool $admin): bool
    {
        if (! str_starts_with($data, 'setor:')) {
            return false;
        }

        $bagian = explode(':', $data);
        $aksi = $bagian[1] ?? '';
        $kasir = self::adalahKasir($chatId);

        if ($aksi === 'r') {
            if (! $kasir && ! $admin) {
                $this->telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus kasir.');

                return true;
            }

            $this->tampilkanRekap($chatId, $messageId, (string) ($bagian[2] ?? 'hari'));

            return true;
        }

        if (! $kasir) {
            $this->telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus kasir.');

            return true;
        }

        if ($aksi === 'x') {
            Cache::forget(self::kunciAlur($chatId));
            $this->telegram->editMessageText($chatId, $messageId, 'Pencatatan setoran dibatalkan.');

            return true;
        }

        $state = Cache::get(self::kunciAlur($chatId));

        if (! is_array($state)) {
            $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

            return true;
        }

        switch ($aksi) {
            case 'j':
                $jenis = $bagian[2] ?? '';

                if (! in_array($jenis, ['outlet', 'reseller'], true)) {
                    $this->telegram->editMessageText($chatId, $messageId, 'Jenis mitra tidak dikenal. Ketik /setor untuk mulai lagi.');

                    return true;
                }

                $state = ['langkah' => 'mitra', 'jenis' => $jenis, 'cari' => null, 'kasir_nama' => $state['kasir_nama'] ?? ''];
                $this->simpanState($chatId, $state);
                [$teks, $tombol] = $this->tampilanDaftar($state, 1);
                $this->telegram->editMessageText($chatId, $messageId, $teks, ['inline_keyboard' => $tombol]);

                return true;

            case 'h':
                if (($state['langkah'] ?? '') !== 'mitra') {
                    $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

                    return true;
                }

                [$teks, $tombol] = $this->tampilanDaftar($state, max(1, (int) ($bagian[2] ?? 1)));
                $this->telegram->editMessageText($chatId, $messageId, $teks, ['inline_keyboard' => $tombol]);

                return true;

            case 'm':
                if (! in_array($state['langkah'] ?? '', ['mitra'], true)) {
                    $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

                    return true;
                }

                $mitra = $this->ambilMitra((int) ($bagian[2] ?? 0), (string) $state['jenis']);

                if ($mitra === null) {
                    $this->telegram->editMessageText($chatId, $messageId, '⚠️ Mitra tidak ditemukan atau tidak aktif. Ketik /setor untuk mulai lagi.');

                    return true;
                }

                $this->pilihMitra($chatId, $messageId, $state, $mitra);

                return true;

            case 'u':
                if (($state['langkah'] ?? '') !== 'konfirmasi') {
                    $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

                    return true;
                }

                $state['langkah'] = 'jumlah';
                $this->simpanState($chatId, $state);
                $this->telegram->editMessageText($chatId, $messageId, $this->teksTanyaJumlah($state));

                return true;

            case 's':
                $this->simpan($chatId, $messageId, (string) ($bagian[2] ?? ''));

                return true;
        }

        $this->telegram->editMessageText($chatId, $messageId, 'Tombol tidak dikenal. Ketik /setor untuk mulai lagi.');

        return true;
    }

    /** @param array{id:int,name:string,saldo?:?float} $mitra */
    protected function pilihMitra(string $chatId, ?int $messageId, array $state, array $mitra): void
    {
        $state['langkah'] = 'jumlah';
        $state['mitra_id'] = $mitra['id'];
        $state['mitra_nama'] = $mitra['name'];
        $this->simpanState($chatId, $state);

        $teks = $this->teksTanyaJumlah($state, $mitra);

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $teks);
        } else {
            $this->telegram->sendMessage($chatId, $teks);
        }
    }

    protected function teksTanyaJumlah(array $state, ?array $mitra = null): string
    {
        $nama = $this->h((string) ($state['mitra_nama'] ?? ''));
        $teks = 'Ketik jumlah setoran dari <b>'.$nama.'</b> ('.$this->namaJenis($state['jenis'] ?? 'outlet').", ".$this->untukApa($state['jenis'] ?? 'outlet').").\n";

        if ($mitra !== null && ($state['jenis'] ?? '') === 'reseller' && isset($mitra['saldo'])) {
            $teks .= 'Saldo sekarang: '.$this->rp((float) $mitra['saldo'])."\n";
        }

        if (isset($state['mitra_id'])) {
            [$awal, $akhir] = $this->rentang('bulan');
            $t = $this->totalSetoranMitra((int) $state['mitra_id'], $awal, $akhir);

            if ($t['jumlah'] > 0) {
                $teks .= 'Sudah setor bulan ini: '.$this->rp($t['nominal']).' ('.$t['jumlah']."×)\n";
            }
        }

        return $teks."\nContoh: <code>150000</code>, <code>150.000</code> atau <code>150rb</code>. Batal: /batal";
    }

    protected function tampilanDaftar(array $state, int $halaman): array
    {
        $jenis = (string) $state['jenis'];
        $cari = $state['cari'] ?? null;
        $semua = $this->daftarMitra($jenis, $cari);
        $total = count($semua);
        $maks = max(1, (int) ceil($total / self::PER_HALAMAN));
        $halaman = min(max(1, $halaman), $maks);
        $potong = array_slice($semua, ($halaman - 1) * self::PER_HALAMAN, self::PER_HALAMAN);

        $tombol = [];

        foreach (array_chunk($potong, 2) as $baris) {
            $tombol[] = array_map(fn ($m) => [
                'text' => mb_strimwidth($m['name'], 0, 28, '..'),
                'callback_data' => 'setor:m:'.$m['id'],
            ], $baris);
        }

        $nav = [];

        if ($halaman > 1) {
            $nav[] = ['text' => '◀ Sebelumnya', 'callback_data' => 'setor:h:'.($halaman - 1)];
        }

        if ($halaman < $maks) {
            $nav[] = ['text' => 'Berikutnya ▶', 'callback_data' => 'setor:h:'.($halaman + 1)];
        }

        if ($nav !== []) {
            $tombol[] = $nav;
        }

        $tombol[] = [['text' => '❌ Batal', 'callback_data' => 'setor:x']];

        if ($total === 0) {
            return ['Belum ada '.$this->namaJenis($jenis).' aktif. Tambahkan dulu lewat /add'.$jenis.'.', $tombol];
        }

        $judul = 'Pilih '.$this->namaJenis($jenis).' yang menyetor';
        $judul .= $cari ? " (hasil cari \"".$this->h($cari)."\", {$total} mitra)" : " ({$total} mitra)";
        $judul .= $maks > 1 ? ", halaman {$halaman}/{$maks}" : '';
        $judul .= ", atau ketik sebagian namanya untuk mencari:";

        return [$judul, $tombol];
    }

    protected function tampilanKonfirmasi(array $state): array
    {
        $jenis = (string) $state['jenis'];
        $jumlah = (float) $state['jumlah'];
        $teks = "🧾 <b>Konfirmasi Setoran</b>\n\n";
        $teks .= 'Dari: <b>'.$this->h((string) $state['mitra_nama']).'</b> ('.ucfirst($jenis).")\n";
        $teks .= 'Untuk: '.$this->untukApa($jenis)."\n";
        $teks .= 'Jumlah: <b>'.$this->rp($jumlah)."</b>\n";
        $teks .= 'Tanggal: '.$this->sekarang()->format('d/m/Y')."\n";

        if ($jenis === 'reseller' && $this->saldoResellerAktif()) {
            $teks .= "\n💡 Saldo reseller akan ikut BERTAMBAH ".$this->rp($jumlah).".\n";
        }

        $teks .= "\nSudah benar? Tekan cara bayar untuk menyimpan, atau ketik jumlah baru kalau salah:";

        return [$teks, [
            [
                ['text' => '💵 Simpan — Tunai', 'callback_data' => 'setor:s:tunai'],
                ['text' => '🏦 Simpan — Transfer', 'callback_data' => 'setor:s:transfer'],
            ],
            [
                ['text' => '✏️ Ubah jumlah', 'callback_data' => 'setor:u'],
                ['text' => '❌ Batal', 'callback_data' => 'setor:x'],
            ],
        ]];
    }

    protected function simpan(string $chatId, int $messageId, string $cara): void
    {
        $metode = match ($cara) {
            'tunai' => 'cash',
            'transfer' => 'transfer',
            default => null,
        };

        // Tombol Simpan yang tidak pada tempatnya (langkah belum konfirmasi, cara bayar
        // ngawur) ditolak TANPA menghapus alur yang sedang berjalan.
        $cek = Cache::get(self::kunciAlur($chatId));

        if (! is_array($cek) || ($cek['langkah'] ?? '') !== 'konfirmasi' || $metode === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

            return;
        }

        // pull = ambil SEKALIGUS hapus: tombol yang ditekan dua kali tidak
        // bisa menyimpan dua setoran.
        $state = Cache::pull(self::kunciAlur($chatId));

        if (! is_array($state)) {
            $this->telegram->editMessageText($chatId, $messageId, 'Sesi setoran sudah berakhir atau sudah diproses. Ketik /setor untuk mulai lagi.');

            return;
        }

        $jenis = (string) $state['jenis'];
        $tambahSaldo = $jenis === 'reseller' && $this->saldoResellerAktif();

        try {
            $hasil = $this->buatSetoran([
                'mitra_id' => (int) $state['mitra_id'],
                'jenis' => $jenis,
                'jumlah' => (float) $state['jumlah'],
                'metode' => $metode,
                'tanggal' => $this->sekarang()->format('Y-m-d'),
                'catatan' => 'Dicatat via bot Telegram oleh '.($state['kasir_nama'] ?: 'kasir').' — '.$this->untukApa($jenis),
            ], $tambahSaldo);
        } catch (\Throwable $e) {
            Log::error('Gagal mencatat setoran kasir via bot: '.$e->getMessage());
            $this->telegram->editMessageText($chatId, $messageId, '❌ Setoran GAGAL disimpan, tidak ada yang tercatat. Coba /setor lagi; kalau tetap gagal, catat lewat halaman Kasir di aplikasi.');

            return;
        }

        [$awalBulan, $akhirBulan] = $this->rentang('bulan');
        [$awalHari, $akhirHari] = $this->rentang('hari');
        $bulanIni = $this->totalSetoranMitra((int) $state['mitra_id'], $awalBulan, $akhirBulan);
        $hariIni = $this->ambilSetoran($awalHari, $akhirHari);
        $totalHari = array_sum(array_column($hariIni, 'jumlah'));

        $teks = "✅ <b>Setoran dicatat</b>\n\n";
        $teks .= 'Dari: <b>'.$this->h((string) $state['mitra_nama']).'</b> ('.ucfirst($jenis).")\n";
        $teks .= 'Untuk: '.$this->untukApa($jenis)."\n";
        $teks .= 'Jumlah: <b>'.$this->rp((float) $state['jumlah']).'</b> — '.($metode === 'cash' ? 'Tunai' : 'Transfer')."\n";

        if ($tambahSaldo && isset($hasil['saldo_baru'])) {
            $teks .= 'Saldo reseller sekarang: '.$this->rp((float) $hasil['saldo_baru'])."\n";
        }

        $teks .= "\nSetoran ".$this->h((string) $state['mitra_nama']).' bulan ini: '.$this->rp($bulanIni['nominal']).' ('.$bulanIni['jumlah']."×)\n";
        $teks .= 'Total diterima hari ini: '.$this->rp((float) $totalHari).' ('.count($hariIni)." setoran)\n";
        $teks .= "\nCatat setoran lain: /setor";

        $this->telegram->editMessageText($chatId, $messageId, $teks);
    }

    // ------------------------------------------------------------------
    // Rekap
    // ------------------------------------------------------------------

    protected function tombolPeriode(): array
    {
        return [
            [
                ['text' => 'Hari ini', 'callback_data' => 'setor:r:hari'],
                ['text' => 'Kemarin', 'callback_data' => 'setor:r:kemarin'],
            ],
            [
                ['text' => 'Bulan ini', 'callback_data' => 'setor:r:bulan'],
                ['text' => 'Bulan lalu', 'callback_data' => 'setor:r:bulanlalu'],
            ],
        ];
    }

    /** @return array{0:string,1:string} [awal, akhir] format Y-m-d */
    protected function rentang(string $periode): array
    {
        $kini = $this->sekarang();

        return match ($periode) {
            'kemarin' => [$kini->modify('-1 day')->format('Y-m-d'), $kini->modify('-1 day')->format('Y-m-d')],
            'bulan' => [$kini->modify('first day of this month')->format('Y-m-d'), $kini->modify('last day of this month')->format('Y-m-d')],
            'bulanlalu' => [$kini->modify('first day of last month')->format('Y-m-d'), $kini->modify('last day of last month')->format('Y-m-d')],
            default => [$kini->format('Y-m-d'), $kini->format('Y-m-d')],
        };
    }

    protected function tampilkanRekap(string $chatId, int $messageId, string $periode): void
    {
        if (! in_array($periode, ['hari', 'kemarin', 'bulan', 'bulanlalu'], true)) {
            $periode = 'hari';
        }

        [$awal, $akhir] = $this->rentang($periode);

        try {
            $baris = $this->ambilSetoran($awal, $akhir);
        } catch (\Throwable $e) {
            Log::error('Gagal baca rekap setoran: '.$e->getMessage());
            $this->telegram->editMessageText($chatId, $messageId, '❌ Gagal membaca data setoran. Coba lagi sebentar lagi.');

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $this->susunRekap($periode, $awal, $akhir, $baris), ['inline_keyboard' => $this->tombolPeriode()]);
    }

    /**
     * @param  list<array{tanggal:string,jenis:string,mitra:string,jumlah:float,metode:string}>  $baris
     */
    public function susunRekap(string $periode, string $awal, string $akhir, array $baris): string
    {
        $judul = match ($periode) {
            'kemarin' => 'Kemarin',
            'bulan' => 'Bulan ini',
            'bulanlalu' => 'Bulan lalu',
            default => 'Hari ini',
        };
        $perBulan = in_array($periode, ['bulan', 'bulanlalu'], true);

        $teks = "📋 <b>Rekap Setoran — {$judul}</b>\n";
        $teks .= $perBulan
            ? $this->namaBulan($awal)."\n"
            : $this->namaHari($awal).', '.$this->tanggalPanjang($awal)."\n";

        if ($baris === []) {
            return $teks."\nBelum ada setoran pada periode ini.";
        }

        $total = array_sum(array_column($baris, 'jumlah'));
        $teks .= "\nTotal diterima: <b>".$this->rp($total).'</b> ('.count($baris)." setoran)\n";

        $perJenis = [];
        $perCara = [];
        $perMitra = [];
        $perTanggal = [];

        foreach ($baris as $b) {
            $perJenis[$b['jenis']]['n'] = ($perJenis[$b['jenis']]['n'] ?? 0) + 1;
            $perJenis[$b['jenis']]['total'] = ($perJenis[$b['jenis']]['total'] ?? 0) + $b['jumlah'];
            $cara = $b['metode'] === 'cash' ? 'Tunai' : ucfirst($b['metode']);
            $perCara[$cara] = ($perCara[$cara] ?? 0) + $b['jumlah'];
            $kunciMitra = $b['mitra'].'|'.$b['jenis'];
            $perMitra[$kunciMitra]['nama'] = $b['mitra'];
            $perMitra[$kunciMitra]['jenis'] = $b['jenis'];
            $perMitra[$kunciMitra]['n'] = ($perMitra[$kunciMitra]['n'] ?? 0) + 1;
            $perMitra[$kunciMitra]['total'] = ($perMitra[$kunciMitra]['total'] ?? 0) + $b['jumlah'];
            $perTanggal[$b['tanggal']]['n'] = ($perTanggal[$b['tanggal']]['n'] ?? 0) + 1;
            $perTanggal[$b['tanggal']]['total'] = ($perTanggal[$b['tanggal']]['total'] ?? 0) + $b['jumlah'];
        }

        $teks .= "\n<b>Menurut jenis mitra</b>\n";

        foreach (['outlet', 'reseller', 'biller'] as $j) {
            if (isset($perJenis[$j])) {
                $ket = match ($j) {
                    'outlet' => 'Outlet (hasil jual voucher)',
                    'reseller' => 'Reseller (top up saldo)',
                    default => 'Biller',
                };
                $teks .= " • {$ket}: ".$this->rp($perJenis[$j]['total']).' ('.$perJenis[$j]['n']."×)\n";
            }
        }

        $teks .= "\n<b>Menurut cara bayar</b>\n";
        arsort($perCara);

        foreach ($perCara as $cara => $nominal) {
            $teks .= " • {$cara}: ".$this->rp($nominal)."\n";
        }

        uasort($perMitra, fn ($a, $b) => $b['total'] <=> $a['total']);
        $teks .= "\n<b>Per mitra</b>\n";
        $no = 0;
        $batas = $perBulan ? 3300 : 3700;

        foreach ($perMitra as $m) {
            $no++;
            $label = $m['jenis'] === 'outlet' ? '' : ' ('.ucfirst($m['jenis']).')';
            $baru = ' '.$no.'. '.$this->h($m['nama']).$label.' — '.$this->rp($m['total']).' ('.$m['n']."×)\n";

            if (mb_strlen($teks.$baru) > $batas) {
                $teks .= ' ...dan '.(count($perMitra) - $no + 1)." mitra lain\n";
                break;
            }

            $teks .= $baru;
        }

        if ($perBulan) {
            ksort($perTanggal);
            $teks .= "\n<b>Per tanggal</b>\n";

            foreach ($perTanggal as $tgl => $t) {
                $baru = ' '.substr($tgl, 8, 2).'/'.substr($tgl, 5, 2).' — '.$this->rp($t['total']).' ('.$t['n']."×)\n";

                if (mb_strlen($teks.$baru) > 3950) {
                    $teks .= " ...\n";
                    break;
                }

                $teks .= $baru;
            }
        }

        return rtrim($teks);
    }

    // ------------------------------------------------------------------
    // Pembantu murni (tanpa database)
    // ------------------------------------------------------------------

    /**
     * "150000", "150.000", "Rp 1.500.000", "150rb", "150k", "1,5jt" -> rupiah
     * bulat. null kalau bukan angka yang jelas (mis. "150.5" ambigu), nol,
     * atau lebih dari BATAS_MAKS.
     */
    public static function parseJumlah(string $teks): ?int
    {
        $t = mb_strtolower(trim($teks));
        $t = (string) preg_replace('/^rp\.?\s*/u', '', $t);
        $t = (string) preg_replace('/\s+/u', '', $t);

        if (preg_match('/^(\d+(?:[.,]\d+)?)(rb|ribu|k)$/', $t, $m)) {
            $n = (float) str_replace(',', '.', $m[1]) * 1000;
        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)(jt|juta)$/', $t, $m)) {
            $n = (float) str_replace(',', '.', $m[1]) * 1000000;
        } elseif (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $t)) {
            $n = (float) preg_replace('/[.,]/', '', $t);
        } elseif (preg_match('/^\d+$/', $t)) {
            $n = (float) $t;
        } else {
            return null;
        }

        if (abs($n - round($n)) > 0.0001) {
            return null;
        }

        $n = (int) round($n);

        return ($n > 0 && $n <= self::BATAS_MAKS) ? $n : null;
    }

    protected function rp(float|int $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    protected function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function namaJenis(string $jenis): string
    {
        return $jenis === 'reseller' ? 'Reseller' : 'Outlet';
    }

    protected function untukApa(string $jenis): string
    {
        return $jenis === 'reseller' ? 'Top up saldo' : 'Hasil jual voucher';
    }

    protected function namaPengirim(array $message, string $chatId): string
    {
        $dari = $message['from'] ?? [];
        $nama = trim(($dari['first_name'] ?? '').' '.($dari['last_name'] ?? ''));

        if ($nama === '' && ! empty($dari['username'])) {
            $nama = '@'.$dari['username'];
        }

        return $nama !== '' ? mb_substr($nama, 0, 60) : 'chat '.$chatId;
    }

    protected function namaBulan(string $ymd): string
    {
        $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $bulan[(int) substr($ymd, 5, 2)].' '.substr($ymd, 0, 4);
    }

    protected function namaHari(string $ymd): string
    {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        return $hari[(int) (new \DateTimeImmutable($ymd))->format('w')];
    }

    protected function tanggalPanjang(string $ymd): string
    {
        return (int) substr($ymd, 8, 2).' '.$this->namaBulan($ymd);
    }

    protected function simpanState(string $chatId, array $state): void
    {
        Cache::put(self::kunciAlur($chatId), $state, now()->addMinutes(self::MENIT_SESI));
    }

    // ------------------------------------------------------------------
    // Akses data (di-override saat pengujian)
    // ------------------------------------------------------------------

    protected function sekarang(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(now()->format('Y-m-d H:i:s'));
    }

    protected function saldoResellerAktif(): bool
    {
        return (string) (Setting::get(self::SETTING_SALDO, '0') ?? '0') === '1';
    }

    protected function simpanSetting(string $kunci, string $nilai): void
    {
        Setting::set($kunci, $nilai);
    }

    /** @return list<array{id:int,name:string}> */
    protected function daftarMitra(string $jenis, ?string $cari): array
    {
        $q = Outlet::query()
            ->where('partner_type', $jenis === 'reseller' ? PartnerType::Reseller : PartnerType::Outlet)
            ->where('is_active', true);

        if ($cari !== null && $cari !== '') {
            $q->where('name', 'like', '%'.addcslashes($cari, '%_\\').'%');
        }

        return $q->orderBy('name')->limit(300)->get(['id', 'name'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])
            ->all();
    }

    /** @return array{id:int,name:string,saldo:?float}|null */
    protected function ambilMitra(int $id, string $jenis): ?array
    {
        $o = Outlet::query()
            ->where('id', $id)
            ->where('partner_type', $jenis === 'reseller' ? PartnerType::Reseller : PartnerType::Outlet)
            ->where('is_active', true)
            ->first();

        if (! $o) {
            return null;
        }

        return ['id' => (int) $o->id, 'name' => (string) $o->name, 'saldo' => $jenis === 'reseller' ? (float) $o->saldo : null];
    }

    /** @return array{jumlah:int,nominal:float} */
    protected function totalSetoranMitra(int $mitraId, string $awal, string $akhir): array
    {
        $q = CashierDeposit::query()->where('outlet_id', $mitraId)->whereBetween('deposit_date', [$awal, $akhir]);

        return ['jumlah' => (int) $q->count(), 'nominal' => (float) $q->sum('amount')];
    }

    /**
     * Semua setoran dalam rentang -- termasuk yang dicatat lewat aplikasi.
     *
     * @return list<array{tanggal:string,jenis:string,mitra:string,jumlah:float,metode:string}>
     */
    protected function ambilSetoran(string $awal, string $akhir): array
    {
        return CashierDeposit::query()
            ->with('outlet')
            ->whereBetween('deposit_date', [$awal, $akhir])
            ->orderBy('deposit_date')
            ->orderBy('id')
            ->get()
            ->map(function ($d) {
                $tipe = $d->outlet?->partner_type;

                return [
                    'tanggal' => $d->deposit_date->format('Y-m-d'),
                    'jenis' => $tipe instanceof \BackedEnum ? (string) $tipe->value : (string) ($tipe ?: 'outlet'),
                    'mitra' => (string) ($d->outlet?->name ?? 'Mitra dihapus'),
                    'jumlah' => (float) $d->amount,
                    'metode' => (string) ($d->payment_method ?: 'cash'),
                ];
            })
            ->all();
    }

    /**
     * Simpan setoran (dan, kalau diminta, tambah saldo reseller) dalam SATU
     * transaksi. Saldo ditambah DULU, setoran dibuat TERAKHIR: observer
     * (pemberitahuan ke admin) baru jalan setelah semuanya berhasil.
     *
     * @return array{id:int,saldo_baru:?float}
     */
    protected function buatSetoran(array $d, bool $tambahSaldo): array
    {
        return DB::transaction(function () use ($d, $tambahSaldo) {
            $mitra = Outlet::query()->findOrFail($d['mitra_id']);
            $saldoBaru = null;

            if ($tambahSaldo) {
                app(SaldoService::class)->topup($mitra, (float) $d['jumlah'], null, 'Top up saldo via setoran kasir (bot Telegram)');
                $saldoBaru = (float) $mitra->fresh()->saldo;
            }

            $setoran = CashierDeposit::create([
                'outlet_id' => $mitra->id,
                'amount' => $d['jumlah'],
                'deposit_date' => $d['tanggal'],
                'payment_method' => $d['metode'],
                'notes' => $d['catatan'],
            ]);

            return ['id' => (int) $setoran->id, 'saldo_baru' => $saldoBaru];
        });
    }
}
