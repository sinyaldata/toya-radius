<?php

namespace App\Http\Controllers;

use App\Actions\GenerateVoucherBatchAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use App\Services\TelegramService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Telegram — SETIAP tenant punya bot & URL webhook SENDIRI:
 * instalasi utama = /telegram/webhook (token dari .env), tenant lain
 * = /telegram/webhook/{subdomain} (token dari kolom tenants.telegram_bot_token
 * di database pusat). Karena tenant SUDAH DIKETAHUI dari URL, tidak perlu
 * lagi "cari-cari" ke semua tenant seperti versi awal.
 */
class TelegramWebhookController extends Controller
{
    /**
     * Ambang jumlah voucher: di atas ini hasil generate/lihat-batch
     * dikirim sebagai FILE PDF, bukan di-dump jadi teks di chat
     * (permintaan user, generate massal mis. 2000 voucher) --
     * pesan Telegram dibatasi 4096 karakter, jadi teks sepanjang itu
     * pasti kepotong/gagal terkirim kalau tetap dipaksakan jadi teks.
     */
    protected const VOUCHER_PDF_THRESHOLD = 30;

    /**
     * Nama database 'mysql' SEBELUM dialihkan ke tenant mana pun --
     * direkam di awal __invoke() (lihat di bawah), dipakai
     * resetConnection() buat balik ke database yang BENAR di akhir,
     * bukan cuma balik ke apa pun yang KEBETULAN sedang aktif saat itu.
     */
    protected ?string $originalDatabase = null;

    public function __invoke(Request $request, ?string $subdomain = null)
    {
        // WAJIB direkam DI SINI, sebelum resolveTelegramService() sempat
        // mengalihkan koneksi ke database tenant -- kalau direkam
        // belakangan, yang kerekam malah database tenant itu sendiri.
        $this->originalDatabase = config('database.connections.mysql.database');

        $telegram = $this->resolveTelegramService($subdomain);

        if (! $telegram) {
            return response()->json(['ok' => false, 'error' => 'Bot belum dikonfigurasi untuk tenant ini'], 404);
        }

        $update = $request->all();

        // PENTING (mencegah duplikat): kalau server ini telat/gagal
        // membalas Telegram dengan cepat (koneksi lambat, proses yang
        // makan waktu, dll), Telegram akan MENGIRIM ULANG update yang
        // SAMA PERSIS beberapa kali -- tanpa penanda di bawah ini,
        // pengiriman ulang itu diproses lagi dari nol dan bot jadi
        // kirim balasan dobel/berkali-kali padahal user cuma kirim
        // SATU perintah/pesan. Cache key disertai $subdomain karena
        // update_id itu bernomor PER BOT (beda tenant = beda bot =
        // bisa kebetulan punya nomor update_id yang sama).
        $updateId = $update['update_id'] ?? null;

        if ($updateId !== null) {
            $dedupKey = 'telegram_update_seen:'.($subdomain ?? 'central').':'.$updateId;

            if (Cache::has($dedupKey)) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }

            Cache::put($dedupKey, true, now()->addMinutes(30));
        }

        try {
            if ($telegram instanceof \App\Services\TelegramPencatat) {
                $telegram->mulaiCatatan();
            }

            if (isset($update['message'])) {
                $this->handleMessage($update['message'], $telegram);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query'], $telegram);
            }

            // Rapikan chat HANYA kalau pemrosesan sukses (exception -> tidak
            // menghapus apa pun). Lihat App\Support\TelegramRapikanChat.
            $this->rapikanChat($update, $telegram, $subdomain);
        } catch (\Throwable $e) {
            // PENTING: sebelum ini, exception apa pun di dalam webhook
            // membuat Laravel menjawab 500 dan bot DIAM TOTAL -- pengguna
            // menekan tombol lalu tidak terjadi apa-apa, tanpa satu pun
            // petunjuk. Itu jenis kegagalan yang paling mahal waktunya
            // untuk dilacak.
            //
            // Sekarang: selalu dicatat ke log, DAN kalau yang menekan
            // adalah admin, pesan galatnya dikirim balik ke chat-nya.
            // Sengaja hanya ke admin -- isi pesan exception bisa memuat
            // nama tabel/kolom, tidak pantas dilempar ke chat outlet.
            Log::error('Webhook Telegram gagal', [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $chatGagal = (string) ($update['message']['chat']['id']
                ?? $update['callback_query']['message']['chat']['id']
                ?? '');

            if ($chatGagal !== '') {
                try {
                    if ($this->isAdminChat($chatGagal)) {
                        $telegram->sendMessage(
                            $chatGagal,
                            "⚠️ <b>Terjadi galat di bot</b>\n\n<code>".
                            htmlspecialchars($e->getMessage(), ENT_QUOTES).
                            "</code>\n\n<i>".
                            htmlspecialchars(basename($e->getFile()).':'.$e->getLine(), ENT_QUOTES).
                            '</i>'
                        );
                    } else {
                        $telegram->sendMessage($chatGagal, 'Maaf, terjadi galat di sistem. Sudah dicatat, coba lagi sebentar lagi.');
                    }
                } catch (\Throwable $lagi) {
                    // Gagal mengabari pun tidak boleh menjatuhkan webhook.
                    Log::warning('Gagal mengirim pesan galat ke Telegram: '.$lagi->getMessage());
                }
            }
        } finally {
            $this->resetConnection();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Alihkan koneksi 'mysql' ke database tenant ini (kalau ada subdomain),
     * dan buat TelegramService pakai token bot tenant ini. NULL kalau
     * subdomain tidak dikenal, atau tenant itu belum isi token bot-nya.
     *
     * BUG YANG DIPERBAIKI DI SINI (penyebab /cek selalu "belum aktif"
     * untuk bot TENANT, bukan instalasi utama): App\Support\RadiusUsername
     * (dipakai buat baca/tulis ke tabel radacct/radcheck FreeRADIUS) baca
     * awalan username tenant dari binding container "currentTenant" --
     * BUKAN dari koneksi 'mysql' yang dialihkan di atas. Binding itu
     * NORMALNYA otomatis terisi lewat AppServiceProvider::boot() waktu
     * request datang ke SUBDOMAIN tenant (mis. sinyalta.sinyaldata.web.id)
     * -- tapi webhook Telegram tenant diakses lewat domain PUSAT dengan
     * subdomain di URL PATH (/telegram/webhook/{subdomain}), BUKAN lewat
     * subdomain asli, jadi binding itu TIDAK PERNAH otomatis terisi di
     * sini. Akibatnya RadiusUsername::forRadius() selalu mengira "tidak
     * ada awalan" (instalasi utama) walau sedang proses tenant lain --
     * voucher yang dibuat/dicek lewat bot tenant jadi salah cocok
     * usernamenya ke radacct (awalan tidak sama dengan yang beneran
     * dipakai FreeRADIUS). Command terjadwal (lihat RunsPerTenant) sudah
     * lama mengisi binding ini secara manual justru karena masalah
     * persis yang sama -- sekarang webhook Telegram ikut diisi juga.
     */
    /**
     * "Rapikan chat otomatis" (permintaan user 2026-09-29): setelah perintah
     * BERHASIL, hapus pesan perintah + menu/pertanyaan perantara, sisakan
     * hasil akhir. Aturan lengkapnya di App\Support\TelegramRapikanChat.
     *
     * Bisa dimatikan per instalasi/tenant: Setting telegram_hapus_otomatis = '0'.
     * Tidak pernah boleh menggagalkan webhook -- semua galat ditelan & dicatat.
     */
    protected function rapikanChat(array $update, TelegramService $telegram, ?string $subdomain): void
    {
        if (! $telegram instanceof \App\Services\TelegramPencatat) {
            return;
        }

        try {
            if (\App\Models\Setting::get('telegram_hapus_otomatis', '1') !== '1') {
                return;
            }

            $bot = $subdomain ?? 'central';

            if (isset($update['message'])) {
                $pesan = $update['message'];
                $chatId = (string) ($pesan['chat']['id'] ?? '');
                $idPesan = (int) ($pesan['message_id'] ?? 0);

                if ($chatId === '' || $idPesan <= 0) {
                    return;
                }

                \App\Support\TelegramRapikanChat::untuk($bot, $chatId)->setelahPesan(
                    $telegram, $chatId, $idPesan, (string) ($pesan['text'] ?? ''), $this->adaAlurAktif($chatId)
                );
            } elseif (isset($update['callback_query']['message'])) {
                $pesan = $update['callback_query']['message'];
                $chatId = (string) ($pesan['chat']['id'] ?? '');
                $idPesan = (int) ($pesan['message_id'] ?? 0);

                if ($chatId === '' || $idPesan <= 0) {
                    return;
                }

                \App\Support\TelegramRapikanChat::untuk($bot, $chatId)->setelahTombol(
                    $telegram, $chatId, $idPesan, (string) ($pesan['text'] ?? $pesan['caption'] ?? ''), $this->adaAlurAktif($chatId)
                );
            }
        } catch (\Throwable $e) {
            Log::notice('Rapikan chat Telegram dilewati: '.$e->getMessage());
        }
    }

    /**
     * Apakah chat ini sedang di tengah alur tanya-jawab (menunggu ketikan)?
     * WAJIB diperbarui kalau ada alur *FlowKey baru -- kalau terlewat, pesan
     * di tengah alur itu dianggap "hasil akhir" dan tidak ikut dirapikan
     * (aman, hanya kurang bersih).
     */
    protected function adaAlurAktif(string $chatId): bool
    {
        foreach ([
            $this->pppoeFlowKey($chatId),
            $this->mitraFlowKey($chatId),
            $this->voucherQtyFlowKey($chatId),
            $this->bayarFlowKey($chatId),
            $this->staticIpFlowKey($chatId),
            $this->memberFlowKey($chatId),
            $this->pengeluaranFlowKey($chatId),
            $this->wifiFlowKey($chatId),
            $this->cekVoucherFlowKey($chatId),
            \App\Services\SetoranKasirBot::kunciAlur($chatId), // SETORAN-KASIR-BOT
        ] as $kunci) {
            if (Cache::has($kunci)) {
                return true;
            }
        }

        return false;
    }

    protected function cekVoucherFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_cek_voucher_flow:{$namespace}:{$chatId}";
    }

    /**
     * Semua alur tanya-jawab yang dibatalkan saat tombol menu ditekan.
     * Nilai = nama alur untuk pemberitahuan, atau null (batal diam-diam).
     *
     * @return array<string, ?string>
     */
    protected function kunciAlurTanyaJawab(string $chatId): array
    {
        return [
            $this->pppoeFlowKey($chatId) => 'tambah pelanggan PPPoE',
            $this->mitraFlowKey($chatId) => 'tambah Outlet/Reseller',
            $this->voucherQtyFlowKey($chatId) => null,
            $this->bayarFlowKey($chatId) => null,
            $this->staticIpFlowKey($chatId) => null,
            $this->memberFlowKey($chatId) => null,
            $this->pengeluaranFlowKey($chatId) => null,
            $this->wifiFlowKey($chatId) => null,
            $this->cekVoucherFlowKey($chatId) => null,
            \App\Services\SetoranKasirBot::kunciAlur($chatId) => null, // SETORAN-KASIR-BOT
        ];
    }

    protected function resolveTelegramService(?string $subdomain): ?TelegramService
    {
        if (! $subdomain) {
            // Instalasi utama — token dari .env, koneksi default (tidak
            // perlu dialihkan). Lepas dulu binding "currentTenant" kalau
            // ada SISA dari request tenant sebelumnya (jaga-jaga proses
            // PHP-nya dipakai ulang, mis. lewat Octane) -- instalasi
            // utama TIDAK PUNYA tenant/awalan sama sekali.
            if (app()->bound('currentTenant')) {
                app()->forgetInstance('currentTenant');
            }

            $token = Setting::get('telegram_bot_token') ?: config('services.telegram.bot_token');

            return $token ? new \App\Services\TelegramPencatat($token) : null;
        }

        $tenant = Tenant::where('subdomain', $subdomain)->where('status', 'active')->first();

        if (! $tenant || ! $tenant->telegram_bot_token) {
            return null;
        }

        Config::set('database.connections.mysql.database', $tenant->database_name);
        DB::purge('mysql');
        DB::reconnect('mysql');

        // WAJIB diisi juga di sini (bukan cuma waktu request web biasa
        // lewat subdomain) -- lihat catatan panjang di atas kenapa ini
        // penting untuk RadiusUsername::forRadius() bisa dapat awalan
        // tenant yang benar.
        app()->instance('currentTenant', $tenant);

        return new \App\Services\TelegramPencatat($tenant->telegram_bot_token);
    }

    protected function handleMessage(array $message, TelegramService $telegram): void
    {
        $chatId = (string) $message['chat']['id'];

        if (isset($message['voice'])) {
            $this->handleVoiceNote($chatId, $message['voice']['file_id'], $telegram);

            return;
        }
        $text = trim($message['text'] ?? '');

        // TOMBOL MENU (2026-10-04): tombol di bawah kolom ketik mengirim teks
        // labelnya ("🎫 Generate Voucher" dst). Diterjemahkan ke perintah yang
        // sudah ada DI SINI, sebelum alur tanya-jawab di bawah sempat
        // menangkapnya sebagai jawaban (mis. jadi nama pelanggan).
        // Enam alur lain sudah mendahulukan perintah ("/...") dengan sendirinya;
        // dua alur yang menangkap SEMUA pesan (tambah PPPoE, tambah
        // Outlet/Reseller) dibatalkan dulu di sini. Lihat App\Support\TelegramMenu.
        $dariTombol = \App\Support\TelegramMenu::perintahDariTombol($text);

        if ($dariTombol !== null) {
            // Tombol di HP pengguna masih versi lama (label diganti/dihapus
            // untuk perannya) -> pasang menu terbaru dulu, lalu tetap jalankan
            // perintahnya supaya tekanannya tidak sia-sia.
            $peranTombol = $this->peranMenu($chatId);

            if ($peranTombol !== 'tamu' && \App\Support\TelegramMenu::perluDiperbarui($text, $peranTombol)) {
                $this->kirimMenu($chatId, $telegram, '🔄 Tombol menu sudah diperbarui 👇');
            }

            // Menekan tombol = pindah pekerjaan: SEMUA alur tanya-jawab yang
            // sedang menunggu dibatalkan. Dua alur yang biasanya menangkap
            // semua pesan diberi tahu; sisanya batal diam-diam, sama seperti
            // saat perintah "/..." diketik.
            foreach ($this->kunciAlurTanyaJawab($chatId) as $kunciAlur => $namaAlur) {
                if (Cache::has($kunciAlur)) {
                    Cache::forget($kunciAlur);

                    if ($namaAlur !== null) {
                        $telegram->sendMessage($chatId, "Alur {$namaAlur} dibatalkan karena Anda memilih menu lain.");
                    }
                }
            }

            $text = $dariTombol;
        }

        // Alur Cek Voucher (dimulai oleh tombol "Cek Voucher" atau /cek tanpa
        // kode): pesan berikutnya adalah KODE vouchernya.
        if (Cache::has($this->cekVoucherFlowKey($chatId))) {
            Cache::forget($this->cekVoucherFlowKey($chatId));

            if ($text === '/batal') {
                $telegram->sendMessage($chatId, 'Cek voucher dibatalkan.');

                return;
            }

            if ($text !== '' && ! str_starts_with($text, '/')) {
                $this->handleCekVoucher($chatId, $text, $telegram);

                return;
            }
            // Perintah lain: alur cek batal, perintahnya dijalankan seperti biasa.
        }

        // SETORAN-KASIR-BOT (2026-10-08): /setor & /rekapsetoran (kasir), /kasir (admin), dan
        // jawaban kasir di tengah alur setoran. Ditaruh SEBELUM alur lain supaya
        // angka jumlah setoran tidak tertangkap alur lain. Lihat App\Services\SetoranKasirBot.
        if ((new \App\Services\SetoranKasirBot($telegram))->handlePesan($chatId, $text, $message, $this->isAdminChat($chatId))) {
            return;
        }

        // BARU: kalau chat ini SEDANG di tengah alur /pppoe (isi Nama,
        // Alamat, dst SATU PER SATU lewat pesan biasa -- bukan tombol),
        // tangkap DULU pesan ini sebagai jawaban langkah itu, SEBELUM
        // dicocokkan ke perintah mana pun -- kecuali /batal, buat
        // membatalkan alurnya kapan saja. Lihat startPppoeFlow() &
        // handlePppoeFlowInput() di bawah.
        $pppoeState = Cache::get($this->pppoeFlowKey($chatId));

        if ($pppoeState) {
            if ($text === '/batal') {
                Cache::forget($this->pppoeFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Alur tambah pelanggan PPPoE dibatalkan.');

                return;
            }

            $this->handlePppoeFlowInput($chatId, $text, $pppoeState, $telegram);

            return;
        }

        // BARU: sama seperti $pppoeState di atas, tapi buat alur
        // /addoutlet & /addreseller (satu state machine dipakai
        // bersama, dibedakan lewat 'partner_type' di dalam state --
        // lihat startMitraFlow()/handleMitraFlowInput()).
        $mitraState = Cache::get($this->mitraFlowKey($chatId));

        if ($mitraState) {
            if ($text === '/batal') {
                $labelBatal = ($mitraState['partner_type'] ?? 'outlet') === 'outlet' ? 'Outlet' : 'Reseller';
                Cache::forget($this->mitraFlowKey($chatId));
                $telegram->sendMessage($chatId, "Alur tambah {$labelBatal} dibatalkan.");

                return;
            }

            $this->handleMitraFlowInput($chatId, $text, $mitraState, $telegram);

            return;
        }

        // Alur /bayar yang sedang MENUNGGU angka jumlah bulan. Ditangkap
        // di sini, SEBELUM pencocokan perintah -- kalau tidak, angka "4"
        // yang diketik admin akan jatuh ke "Perintah tidak dikenali".
        //
        // Ditaruh SETELAH pemeriksaan alur /pppoe di atas supaya dua alur
        // tidak berebut satu pesan; keduanya sama-sama bisa dibatalkan
        // dengan /batal.
        $bayarState = Cache::get($this->bayarFlowKey($chatId));

        if ($bayarState) {
            if ($text === '/batal') {
                Cache::forget($this->bayarFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Pencatatan pembayaran dibatalkan.');

                return;
            }

            // Perintah lain tetap didahulukan: admin boleh langsung
            // beralih ke pekerjaan lain tanpa harus membatalkan dulu.
            if (! str_starts_with($text, '/')) {
                $this->handleBayarFlowInput($chatId, $text, $bayarState, $telegram);

                return;
            }

            Cache::forget($this->bayarFlowKey($chatId));
        }

        // Alur /wifi yang sedang MENUNGGU SSID/password baru diketik.
        // Ditangkap di sini SEBELUM pencocokan perintah (kalau tidak, teks
        // yang diketik jatuh ke "Perintah tidak dikenali"). Perintah lain
        // (diawali "/") membatalkan alurnya, /batal membatalkan dengan
        // pesan. State-nya di Cache (kedaluwarsa sendiri), berisi id
        // pelanggan & jalur antarmuka -- BUKAN kata sandi (kata sandi baru
        // disimpan terenkripsi hanya setelah lolos validasi).
        $wifiState = Cache::get($this->wifiFlowKey($chatId));

        if ($wifiState) {
            if ($text === '/batal') {
                Cache::forget($this->wifiFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Perubahan Wi-Fi dibatalkan.');

                return;
            }

            if (! str_starts_with($text, '/')) {
                if (($wifiState['tahap'] ?? '') === 'nilai') {
                    $this->handleWifiFlowInput($chatId, $text, (int) ($message['message_id'] ?? 0), $wifiState, $telegram);
                } else {
                    $telegram->sendMessage($chatId, 'Tekan tombol ✅ Terapkan atau ❌ Batal pada pesan konfirmasi di atas (atau ketik /batal).');
                }

                return;
            }

            Cache::forget($this->wifiFlowKey($chatId));
        }

        // Alur "✍️ Jumlah lain..." (permintaan user: generate voucher
        // dalam jumlah banyak, mis. 2000, yang tidak ada di tombol
        // cepat 1/5/10/.../1000) -- lihat qtycustom: di handleCallback().
        // Sama pola-nya dengan alur /bayar & /pppoe di atas: state
        // disimpan sebentar di Cache sambil menunggu admin/outlet
        // mengetik angkanya.
        $qtyState = Cache::get($this->voucherQtyFlowKey($chatId));

        if ($qtyState) {
            if ($text === '/batal') {
                Cache::forget($this->voucherQtyFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Generate voucher dibatalkan.');

                return;
            }

            if (! str_starts_with($text, '/')) {
                $this->handleVoucherQtyFlowInput($chatId, $text, $qtyState, $telegram);

                return;
            }

            Cache::forget($this->voucherQtyFlowKey($chatId));
        }

        // Alur /addstaticip (tanya-jawab tambah pelanggan Static IP) --
        // pola PERSIS sama dengan alur /pppoe di atas.
        $staticIpState = Cache::get($this->staticIpFlowKey($chatId));

        if ($staticIpState) {
            if ($text === '/batal') {
                Cache::forget($this->staticIpFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Alur tambah pelanggan Static IP dibatalkan.');

                return;
            }

            if (! str_starts_with($text, '/')) {
                $this->handleStaticIpFlowInput($chatId, $text, $staticIpState, $telegram);

                return;
            }

            Cache::forget($this->staticIpFlowKey($chatId));
        }

        // Alur /addmember (tanya-jawab tambah Member Hotspot langganan
        // bulanan) -- pola PERSIS sama dengan alur /pppoe di atas.
        $memberState = Cache::get($this->memberFlowKey($chatId));

        if ($memberState) {
            if ($text === '/batal') {
                Cache::forget($this->memberFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Alur tambah Member dibatalkan.');

                return;
            }

            if (! str_starts_with($text, '/')) {
                $this->handleMemberFlowInput($chatId, $text, $memberState, $telegram);

                return;
            }

            Cache::forget($this->memberFlowKey($chatId));
        }

        // Alur /pengeluaran (tanya-jawab catat pengeluaran outlet) --
        // Judul -> Jumlah -> Catatan.
        $pengeluaranState = Cache::get($this->pengeluaranFlowKey($chatId));

        if ($pengeluaranState) {
            if ($text === '/batal') {
                Cache::forget($this->pengeluaranFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Pencatatan pengeluaran dibatalkan.');

                return;
            }

            if (! str_starts_with($text, '/')) {
                $this->handlePengeluaranFlowInput($chatId, $text, $pengeluaranState, $telegram);

                return;
            }

            Cache::forget($this->pengeluaranFlowKey($chatId));
        }

        // Tampilkan / sembunyikan tombol menu (lihat kirimMenu()).
        if (in_array(mb_strtolower($text), ['/menu', '/start'], true)) {
            $this->kirimMenu($chatId, $telegram);

            return;
        }

        if (mb_strtolower($text) === '/tutupmenu') {
            $telegram->sendMessage($chatId, 'Tombol menu disembunyikan. Ketik /menu untuk menampilkannya lagi.', \App\Support\TelegramMenu::hapus());

            return;
        }

        // HAPUS-PENJUALAN: /hapusjual KODE -- khusus super admin (lihat App\Services\HapusPenjualanBot)
        if ($text === '/hapusjual' || str_starts_with($text, '/hapusjual ')) {
            \App\Services\HapusPenjualanBot::perintah($chatId, trim(substr($text, strlen('/hapusjual'))), $telegram);

            return;
        }

        if (str_starts_with($text, '/link')) {
            $code = trim(str_replace('/link', '', $text));
            $this->handleLink($chatId, $code, $telegram);

            return;
        }
        if ($text === '/admin') {
            $telegram->sendMessage($chatId, "Chat ID Telegram kamu:\n<code>{$chatId}</code>\n\nSalin ID ini ke Pengaturan &gt; Bot Telegram, kolom \"Chat ID Admin\" — biar bisa terima notifikasi permintaan top up saldo.");

            return;
        }
        if ($text === '/pendapatan') {
            // Admin (lihat isAdminChat()) boleh lanjut TANPA terhubung
            // ke outlet manapun -- nanti laporannya otomatis digabung
            // SEMUA outlet (lihat showRevenueReport()).
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");
                return;
            }
            $telegram->sendMessage($chatId, 'Pilih periode laporan:', ['inline_keyboard' => $this->tombolPeriodeLaporan('rev')]);
            return;
        }

        // KHUSUS ADMIN: laporan pendapatan OUTLET KESELURUHAN versi FILE
        // PDF (permintaan user) -- ringkas, cuma 4 angka (Jumlah
        // Transaksi, Pendapatan Total, Komisi, Profit), dihitung lewat
        // ReportService::summarize() yang SAMA dipakai aplikasi & versi
        // ringkas di chat (/pendapatan), cuma sekarang jadi file PDF +
        // BOLEH disaring per AREA/WILAYAH (master data di menu Mitra >
        // Area / Wilayah). Lihat kirimLaporanPendapatanPdf().
        if ($text === '/laporanpdf') {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $telegram->sendMessage($chatId, 'Pilih periode laporan PDF:', ['inline_keyboard' => $this->tombolPeriodeLaporan('lappdf')]);

            return;
        }

        // BARU (2026-10-01, permintaan user): LAPORAN PROFIT untuk dibagikan
        // ke grup WhatsApp outlet per wilayah -- per outlet hanya Jumlah
        // Transaksi dan Profit (pendapatan SETELAH komisi). Pendapatan kotor
        // dan Komisi tidak ditampilkan karena selisihnya membuka persentase
        // komisi, yang berbeda-beda tiap outlet. Alurnya sama seperti
        // /laporanpdf: pilih periode (termasuk "Pilih Bulan...") -> pilih
        // wilayah / satu PDF per wilayah. Lihat kirimLaporanPendapatanPdf().
        if (in_array($text, ['/laporanoutlet', '/laporanprofit'], true)) {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $telegram->sendMessage($chatId, 'Pilih periode laporan profit outlet:', ['inline_keyboard' => $this->tombolPeriodeLaporan('lapout')]);

            return;
        }

        // CEK SALDO (permintaan user). Ditaruh SEBELUM /topup karena
        // keduanya sama-sama soal saldo -- urutannya tidak berpengaruh
        // di sini (dua-duanya perbandingan yang berbeda), tapi
        // dikelompokkan supaya gampang dibaca.
        //
        // Reseller       -> langsung lihat saldonya sendiri.
        // Outlet/Biller  -> fitur saldo TIDAK BERLAKU lagi untuk mereka
        //                   (kebijakan: yang pakai saldo hanya Reseller)
        //                   -- dikasih tahu saja, bukan dianggap belum
        //                   terhubung.
        // Admin          -> muncul daftar Reseller untuk dipilih, ATAU
        //                   ringkasan saldo SEMUA Reseller sekaligus.
        if ($text === '/saldo') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if ($outlet && $outlet->partner_type !== \App\Enums\PartnerType::Reseller && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Fitur saldo tidak berlaku untuk outlet ini -- kebijakan saat ini: generate voucher lewat Telegram TIDAK memerlukan saldo untuk Outlet, jadi tidak ada saldo yang perlu dilihat/di-top up.");

                return;
            }

            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->showSaldoReport($chatId, $outlet, $telegram);

                return;
            }

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            // Admin: pilih dulu Reseller mana yang mau dilihat.
            $this->showOutletPickerForSaldo($chatId, $telegram);

            return;
        }

        if (str_starts_with($text, '/topup')) {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            if ($outlet->partner_type !== \App\Enums\PartnerType::Reseller) {
                $telegram->sendMessage($chatId, "Fitur top up saldo tidak berlaku untuk outlet ini -- kebijakan saat ini: generate voucher lewat Telegram TIDAK memerlukan saldo untuk Outlet, jadi tidak perlu top up.");

                return;
            }

            $amount = (float) trim(str_replace(['/topup', '.', ','], '', $text));

            if ($amount <= 0) {
                $telegram->sendMessage($chatId, "Format salah. Contoh: /topup 100000");

                return;
            }

            $adminChatId = \App\Models\Setting::get('telegram_admin_chat_id');

            if (! $adminChatId) {
                $telegram->sendMessage($chatId, "Admin belum siap menerima permintaan top up. Hubungi admin langsung.");

                return;
            }

            $formatted = 'Rp'.number_format($amount, 0, ',', '.');

            $sent = $telegram->sendMessage($adminChatId,
                "Permintaan Top Up Saldo\n\n".
                "Outlet: {$outlet->name}\n".
                "Jumlah: {$formatted}\n".
                "Saldo saat ini: Rp".number_format($outlet->saldo, 0, ',', '.'),
                ['inline_keyboard' => [[
                    ['text' => 'Setuju', 'callback_data' => "topup_approve:PLACEHOLDER"],
                    ['text' => 'Tolak', 'callback_data' => "topup_reject:PLACEHOLDER"],
                ]]]
            );

            $request = \App\Models\SaldoTopupRequest::create([
                'outlet_id' => $outlet->id,
                'amount' => $amount,
                'status' => 'pending',
                'admin_chat_id' => $adminChatId,
                'admin_message_id' => $sent['result']['message_id'] ?? null,
            ]);

            if (! empty($sent['result']['message_id'])) {
                $telegram->editMessageText($adminChatId, $sent['result']['message_id'],
                    "Permintaan Top Up Saldo\n\n".
                    "Outlet: {$outlet->name}\n".
                    "Jumlah: {$formatted}\n".
                    "Saldo saat ini: Rp".number_format($outlet->saldo, 0, ',', '.'),
                    ['inline_keyboard' => [[
                        ['text' => 'Setuju', 'callback_data' => "topup_approve:{$request->id}"],
                        ['text' => 'Tolak', 'callback_data' => "topup_reject:{$request->id}"],
                    ]]]
                );
            }

            $telegram->sendMessage($chatId, "Permintaan top up {$formatted} terkirim ke admin. Menunggu persetujuan.");

            return;
        }

        if ($text === '/voucher') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet) {
                // BARU (permintaan user): admin (bukan Outlet/Reseller)
                // sekarang BOLEH generate voucher juga, tapi harus pilih
                // dulu MAU JADI OUTLET SIAPA -- beda dari sebelumnya yang
                // langsung ditolak. Lihat showOutletPickerForVoucher()
                // & 'vchout:' di handleCallback().
                if ($this->isAdminChat($chatId)) {
                    $this->showOutletPickerForVoucher($chatId, $telegram);

                    return;
                }

                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            // BARU (permintaan user): Biller TIDAK BOLEH generate voucher
            // sendiri lewat bot -- akun Biller cuma buat bantu tagih
            // pembayaran pelanggan, bukan jualan/generate voucher
            // sendiri. Cek partner_type-nya di sini, sebelum masuk ke
            // pemilihan paket sama sekali.
            if ($outlet->partner_type === \App\Enums\PartnerType::Biller) {
                $telegram->sendMessage($chatId, '❌ Akun Biller tidak bisa generate voucher lewat bot ini.');

                return;
            }

            $this->showPackageMenu($chatId, $outlet, $telegram);

            return;
        }

        // BARU (permintaan user): cek status aktif/belum aktif satu
        // voucher lewat bot, tanpa perlu buka aplikasi. Formatnya sama
        // seperti /link -- perintah diikuti kode vouchernya, dipisah
        // spasi: "/cek KODEVOUCHER".
        if (str_starts_with($text, '/cek')) {
            $kode = trim(str_replace('/cek', '', $text));
            $this->handleCekVoucher($chatId, $kode, $telegram);

            return;
        }

        // BARU (permintaan user): putuskan (kick) SESSION ONLINE voucher
        // langsung dari router lewat bot -- dipakai kalau ada voucher
        // yang "nyangkut" online (lupa logout, dipakai lebih dari 1
        // perangkat, dst) dan mau diputus paksa tanpa buka aplikasi.
        // Bisa dipakai OUTLET (cuma voucher miliknya sendiri) maupun
        // ADMIN (voucher outlet manapun) -- akses & pencarian vouchernya
        // pakai pola PERSIS sama dengan /cek di atas.
        if (str_starts_with($text, '/putusvoucher')) {
            $kode = trim(str_replace('/putusvoucher', '', $text));
            $this->handlePutusVoucherSession($chatId, $kode, $telegram);

            return;
        }

        // BARU (permintaan user): cek SISA STOK voucher yang belum
        // terjual/belum aktif per paket -- supaya outlet tahu masih
        // punya stok berapa sebelum generate/cetak voucher baru lagi
        // (menghindari cetak kebanyakan/mubazir). Alurnya (permintaan
        // user): OUTLET biasa -> langsung pilih PAKET (cuma outletnya
        // sendiri, tidak perlu pilih outlet lagi). ADMIN (lihat
        // isAdminChat()) -> pilih OUTLET dulu, BARU pilih PAKET.
        if ($text === '/stok') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            // Outlet biasa (atau admin yang chat-nya KEBETULAN juga
            // ter-link ke satu outlet) langsung ditawari pilih PAKET buat
            // outletnya sendiri -- tidak perlu pilih outlet lagi (cuma
            // satu pilihan). Admin SELALU ditawari pilih OUTLET dulu
            // (termasuk opsi "Semua Outlet"), baru pilih PAKET setelahnya
            // (lihat handleCallback(), "stokout:").
            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->sendStockPackagePicker($chatId, $outlet, $telegram);
            } else {
                $this->showOutletPickerForStock($chatId, $telegram);
            }

            return;
        }

        // BARU (permintaan user): riwayat BATCH generate voucher, supaya
        // bisa di-scroll cari batch tertentu (mis. "Voucher 2000") tanpa
        // buka aplikasi -- lihat batch_label di VoucherGeneratorService.
        // Alurnya sama seperti /stok: outlet biasa langsung lihat daftar
        // batch miliknya sendiri, admin pilih outlet dulu.
        if ($text === '/batch') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->sendBatchList($chatId, $outlet, $telegram);
            } else {
                $this->showOutletPickerForBatch($chatId, $telegram);
            }

            return;
        }

        // BARU (permintaan user): bikin pelanggan PPPoE langsung dari bot,
        // isi Nama, Alamat, No HP, Paket Langganan, Username PPPoE,
        // Password PPPoE -- satu per satu lewat alur tanya-jawab (lihat
        // startPppoeFlow()), karena field-field ini teks bebas, tidak
        // bisa dikemas jadi tombol seperti perintah lain. Sama seperti
        // /pendapatan & /stok: outlet biasa langsung jalan (outletnya
        // sendiri), admin ditawari pilih outlet/biller dulu.
        if ($text === '/pppoe') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->startPppoeFlow($chatId, $outlet, $telegram);
            } else {
                $this->showOutletPickerForPppoe($chatId, $telegram);
            }

            return;
        }

        // BARU (permintaan user: "samakan kayak pppoe saja") -- tambah
        // Outlet/Reseller langsung dari bot, gaya tanya-jawab satu per
        // satu persis /pppoe (lihat startMitraFlow()). KHUSUS ADMIN --
        // beda dengan /pppoe dkk yang boleh dipakai outlet buat
        // pelanggannya sendiri, bikin Outlet/Reseller baru itu memang
        // keputusan admin/owner, bukan sesuatu yang outlet lakukan buat
        // diri sendiri.
        if ($text === '/addoutlet' || $text === '/addreseller') {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $this->startMitraFlow($chatId, $text === '/addoutlet' ? 'outlet' : 'reseller', $telegram);

            return;
        }

        // BARU (permintaan user): tambah pelanggan STATIC IP lewat bot --
        // TIDAK ada langkah pilih "Outlet" seperti /pppoe (lihat catatan
        // panjang di startStaticIpFlow() kenapa) -- langsung mulai
        // tanya-jawab datanya, lalu di langkah TERAKHIR baru optional
        // pilih Biller.
        if ($text === '/addstaticip') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            $this->startStaticIpFlow($chatId, $telegram);

            return;
        }

        // BARU (permintaan user): tambah MEMBER Hotspot (langganan
        // bulanan, lihat HotspotMemberResource di aplikasi) lewat bot --
        // pola SAMA PERSIS dengan /pppoe & /addstaticip di atas.
        if ($text === '/addmember') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->startMemberFlow($chatId, $outlet, $telegram);
            } else {
                $this->showOutletPickerForMember($chatId, $telegram);
            }

            return;
        }

        // BARU (permintaan user): daftar Member Hotspot yang SUDAH ada --
        // Outlet biasa lihat member outletnya sendiri, admin lihat
        // gabungan SEMUA outlet. Dipaginasi 10 per halaman lewat tombol
        // "Berikutnya" (lihat showDaftarMember()).
        if ($text === '/daftarmember') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            $this->showDaftarMember($chatId, null, $this->isAdminChat($chatId) ? null : $outlet, 1, $telegram);

            return;
        }

        // BARU (permintaan user): catat PENGELUARAN yang dilakukan
        // outlet sendiri (beli barang, bayar listrik toko, dst) lewat
        // bot -- tersimpan ke tabel yang SAMA dengan menu Pengeluaran di
        // aplikasi (lihat model Expense), cuma kolom outlet_id-nya
        // terisi supaya jelas pengeluaran itu punya siapa. KHUSUS
        // OUTLET -- admin tidak punya outlet sendiri jadi tidak relevan
        // pakai perintah ini (pengeluaran pusat tetap dicatat lewat
        // menu Pengeluaran di aplikasi seperti biasa).
        if ($text === '/pengeluaran') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet) {
                $telegram->sendMessage($chatId, "Perintah ini khusus outlet yang sudah terhubung.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            $this->showKategoriPengeluaran($chatId, $outlet, $telegram);

            return;
        }

        // BARU (permintaan user): cek pelanggan PPPoE yang sedang OFFLINE.
        //
        // BEDA dari /stok, /saldo & /pppoe: perintah ini SENGAJA TIDAK
        // menanyakan outlet dulu (permintaan user) -- langsung tampil.
        // Alasannya masuk akal: ini perintah darurat, dipakai saat ada
        // laporan gangguan, jadi satu klik tambahan cuma memperlambat.
        // Admin langsung dapat gabungan SEMUA outlet (nama outletnya
        // ditulis di tiap baris), outlet biasa dapat outletnya sendiri.
        if ($text === '/offline') {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            // Admin -> null = gabungan semua outlet.
            $this->showPppoeOfflineReport(
                $chatId,
                $this->isAdminChat($chatId) ? null : $outlet,
                $telegram
            );

            return;
        }

        // /redaman NAMA (alias /onu NAMA) -- cek redaman ONU/ONT satu
        // pelanggan langsung dari GenieACS. Admin: semua pelanggan.
        // Outlet/Reseller: hanya pelanggannya sendiri. Biller: hanya
        // pelanggan tanggungannya. Lihat cariPelangganUntukRedaman() dan
        // pelangganBolehDilihat().
        if ($text === '/redaman' || str_starts_with($text, '/redaman ')
            || $text === '/onu' || str_starts_with($text, '/onu ')) {
            $kunci = trim(preg_replace('#^/(redaman|onu)#', '', $text));

            if (! Outlet::where('telegram_chat_id', $chatId)->exists() && ! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                return;
            }

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/redaman supeni</code>");

                return;
            }

            $this->cariPelangganUntukRedaman($chatId, $kunci, $telegram);

            return;
        }

        // /wifi NAMA -- lihat & ubah SSID / password Wi-Fi ONU pelanggan.
        // Admin: semua pelanggan. Outlet/Reseller: hanya pelanggannya sendiri.
        // Biller TIDAK boleh (kata sandi Wi-Fi bukan urusan penagihan). Lihat
        // cariPelangganUntukWifi() dan pelangganBolehUbahWifi().
        if ($text === '/wifi' || str_starts_with($text, '/wifi ')) {
            $kunci = trim(substr($text, strlen('/wifi')));

            if (! $this->isAdminChat($chatId)) {
                $outletWifi = Outlet::where('telegram_chat_id', $chatId)->first();

                if (! $outletWifi) {
                    $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

                    return;
                }

                if ($outletWifi->partner_type === \App\Enums\PartnerType::Biller) {
                    $telegram->sendMessage($chatId, 'Perintah ini khusus admin dan outlet/reseller.');

                    return;
                }
            }

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/wifi supeni</code>");

                return;
            }

            $this->cariPelangganUntukWifi($chatId, $kunci, $telegram);

            return;
        }

        // ============================================================
        // PERINTAH KHUSUS ADMIN (permintaan user).
        //
        // Semuanya DIJAGA isAdminChat(): perintah-perintah ini mengubah
        // data pelanggan & uang, jadi tidak boleh bisa dipanggil outlet
        // biasa yang kebetulan tahu nama perintahnya.
        // ============================================================

        // 1) Ganti paket langganan pelanggan.
        if ($text === '/gantipaket' || str_starts_with($text, '/gantipaket ')) {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $kunci = trim(substr($text, strlen('/gantipaket')));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/gantipaket supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksi($chatId, $kunci, 'gpcust', 'ganti paket', $telegram);

            return;
        }

        // 2) Outlet yang belum setor bulan ini.
        if ($text === '/outletnunggak') {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $this->showOutletBelumSetor($chatId, $telegram);

            return;
        }

        // 4) Pelanggan yang tagihannya belum lunas.
        //
        // Admin  -> lihat SEMUA pelanggan yang menunggak (lintas outlet).
        // Biller -> lihat tunggakan HANYA pelanggan yang jadi tanggungan
        //           biller ini (langsung lewat "Kelola Pelanggan &
        //           Komisi", atau ikut lewat outlet yang ditugaskan ke
        //           biller ini lewat "Kelola Outlet & Komisi") -- supaya
        //           biller bisa lihat sendiri siapa yang perlu ditagih
        //           tanpa harus buka aplikasi/tanya admin.
        // Selain itu (Outlet/Reseller biasa) -> tetap khusus admin,
        // seperti semula.
        if ($text === '/tagihan') {
            if ($this->isAdminChat($chatId)) {
                $this->showPelangganBelumBayar($chatId, $telegram);

                return;
            }

            $biller = Outlet::where('telegram_chat_id', $chatId)
                ->where('partner_type', \App\Enums\PartnerType::Biller)
                ->first();

            if ($biller) {
                $this->showTagihanUntukBiller($biller, $chatId, $telegram);

                return;
            }

            $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

            return;
        }

        // 5) Catat pembayaran tagihan pelanggan.
        //
        // Sama seperti /tagihan (permintaan user): Admin bebas untuk
        // semua pelanggan, Biller boleh TAPI dibatasi cuma pelanggan
        // tanggungannya sendiri, selain itu (Outlet/Reseller) tetap
        // khusus admin.
        if ($text === '/bayar' || str_starts_with($text, '/bayar ')) {
            $biller = null;

            if (! $this->isAdminChat($chatId)) {
                $biller = Outlet::where('telegram_chat_id', $chatId)
                    ->where('partner_type', \App\Enums\PartnerType::Biller)
                    ->first();

                if (! $biller) {
                    $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                    return;
                }
            }

            $kunci = trim(substr($text, strlen('/bayar')));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/bayar supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksi($chatId, $kunci, 'baycust', 'catat pembayaran', $telegram, $biller);

            return;
        }

        // BARU (permintaan user): cetak ULANG struk pembayaran yang
        // SUDAH tercatat -- buat kasus struk tidak sempat tercetak
        // (printer mati/kertas habis dsb) pas transaksi lunas
        // sebelumnya. Alias "/cetakinvoice" & "/cetakstruk" jalan
        // PERSIS sama. Akses & pembatasan pelanggan SAMA seperti
        // /bayar di atas (Biller cuma pelanggan tanggungannya sendiri).
        if ($text === '/cetakstruk' || str_starts_with($text, '/cetakstruk ')
            || $text === '/cetakinvoice' || str_starts_with($text, '/cetakinvoice ')) {
            $biller = null;

            if (! $this->isAdminChat($chatId)) {
                $biller = Outlet::where('telegram_chat_id', $chatId)
                    ->where('partner_type', \App\Enums\PartnerType::Biller)
                    ->first();

                if (! $biller) {
                    $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                    return;
                }
            }

            $kunci = trim(preg_replace('#^/cetak(struk|invoice)#', '', $text));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/cetakstruk supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksi($chatId, $kunci, 'cetakcust', 'cetak ulang struknya', $telegram, $biller);

            return;
        }

        // 6) Jumlah pelanggan Isolir (tunggakan) & Suspend (manual) saat ini.
        if ($text === '/isolirinfo') {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $this->showRingkasanIsolir($chatId, $telegram);

            return;
        }

        // 7) Isolir manual satu pelanggan (tunggakan, atau kasus mendesak
        //    lain) — dipakai terutama kalau kebijakan "Isolir Otomatis"
        //    (Pengaturan > Tagihan Otomatis) sedang dimatikan, jadi
        //    isolirnya memang harus diputuskan manual satu-satu.
        if ($text === '/isolir' || str_starts_with($text, '/isolir ')) {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $kunci = trim(substr($text, strlen('/isolir')));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/isolir supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksiStatus($chatId, $kunci, 'iso', 'isolir', $telegram);

            return;
        }

        // 8) Buka isolir/suspend satu pelanggan. "/aktifkan" adalah ALIAS
        //    dari "/bukaisolir" (permintaan user, nama perintah lebih
        //    familiar) -- keduanya jalan persis sama, tidak ada
        //    perbedaan logic sama sekali.
        if ($text === '/bukaisolir' || str_starts_with($text, '/bukaisolir ')
            || $text === '/aktifkan' || str_starts_with($text, '/aktifkan ')) {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $prefixDipakai = str_starts_with($text, '/aktifkan') ? '/aktifkan' : '/bukaisolir';
            $kunci = trim(substr($text, strlen($prefixDipakai)));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>{$prefixDipakai} supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksiStatus($chatId, $kunci, 'bka', 'buka isolir', $telegram);

            return;
        }

        // 9) Nonaktifkan (suspend manual, BUKAN karena tunggakan) satu
        //    pelanggan — mis. berhenti berlangganan sementara/permanen.
        if ($text === '/nonaktifkan' || str_starts_with($text, '/nonaktifkan ')) {
            if (! $this->isAdminChat($chatId)) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin.');

                return;
            }

            $kunci = trim(substr($text, strlen('/nonaktifkan')));

            if ($kunci === '') {
                $telegram->sendMessage($chatId, "Ketik bersama nama/username pelanggannya.\n\nContoh: <code>/nonaktifkan supeni</code>");

                return;
            }

            $this->cariPelangganUntukAksiStatus($chatId, $kunci, 'non', 'nonaktifkan', $telegram);

            return;
        }

        // Daftar perintah dipisah dua: yang umum (semua outlet) dan
        // yang khusus admin. Bagian admin cuma ditampilkan ke admin --
        // outlet biasa tidak perlu tahu perintah yang tidak bisa mereka
        // pakai, dan menampilkannya cuma mengundang percobaan.
        $bantuan = "Perintah tidak dikenali.\n\n";
        $bantuan .= "<b>Umum</b>\n";
        $bantuan .= "/menu — tampilkan tombol menu di bawah kolom ketik (/tutupmenu untuk menyembunyikan)\n";
        $bantuan .= "/voucher — buat voucher baru\n";
        $bantuan .= "/cek KODEVOUCHER — cek status voucher\n";
        $bantuan .= "/putusvoucher KODEVOUCHER — putus paksa voucher yang sedang online\n";
        $bantuan .= "/stok — sisa stok voucher\n";
        $bantuan .= "/batch — riwayat batch generate voucher (cari &amp; unduh PDF)\n";
        $bantuan .= "/saldo — saldo &amp; riwayat top up\n";
        $bantuan .= "/topup JUMLAH — minta top up saldo\n";
        $bantuan .= "/pppoe — tambah pelanggan PPPoE\n";
        $bantuan .= "/addstaticip — tambah pelanggan Static IP\n";
        $bantuan .= "/addmember — tambah Member Hotspot (langganan bulanan)\n";
        $bantuan .= "/daftarmember — daftar Member yang sudah ada\n";
        $bantuan .= "/pengeluaran — catat pengeluaran outlet\n";
        $bantuan .= "/offline — pelanggan PPPoE yang sedang offline\n";
        $bantuan .= "/redaman NAMA — cek redaman ONU/ONT pelanggan\n";

        $outletBantuanWifi = Outlet::where('telegram_chat_id', $chatId)->first();

        if ($this->isAdminChat($chatId) || ($outletBantuanWifi && $outletBantuanWifi->partner_type !== \App\Enums\PartnerType::Biller)) {
            // /pendapatan dipindah ke sini dari "Khusus Admin" (2026-09-28,
            // permintaan user): perintahnya SUDAH bisa dipakai outlet
            // (lihat handler /pendapatan), tapi tidak pernah tertulis di
            // daftar bantuan outlet sehingga mereka tidak tahu ada.
            // Biller tidak ditampilkan -- mereka tidak memegang voucher,
            // jadi laporannya selalu kosong.
            $bantuan .= "/pendapatan — laporan pendapatan &amp; komisi (hari ini / bulan ini)\n";
            $bantuan .= "/wifi NAMA — lihat &amp; ubah SSID/password Wi-Fi ONU pelanggan\n";
        }


        if ($this->isAdminChat($chatId)) {
            $bantuan .= "\n<b>Khusus Admin</b>\n";
            $bantuan .= "/laporanpdf — laporan pendapatan outlet keseluruhan (file PDF, bisa difilter per Wilayah)\n";
            $bantuan .= "/laporanoutlet — laporan PROFIT per outlet (tanpa pendapatan kotor &amp; komisi) untuk grup WA outlet; pilih Semua Wilayah = 1 wilayah 1 PDF\n";
            $bantuan .= "/gantipaket NAMA — ganti paket langganan pelanggan\n";
            $bantuan .= "/outletnunggak — outlet yang belum setor bulan ini\n";
            $bantuan .= "/rekapsetoran — rekap setoran mitra yang diterima kasir (hari ini / kemarin / bulan ini / bulan lalu)\n";
            $bantuan .= "/kasir — kelola daftar Chat ID kasir &amp; pengaturan setoran Reseller\n";
            $bantuan .= "/tagihan — pelanggan yang tagihannya belum lunas\n";
            $bantuan .= "/bayar NAMA — catat pembayaran tagihan pelanggan\n";
            $bantuan .= "/cetakstruk NAMA — cetak ulang struk/invoice pembayaran terakhir (pilih format: POS 58mm/80mm/A4-F4)\n";
            $bantuan .= "/isolirinfo — jumlah pelanggan isolir &amp; suspend saat ini\n";
            $bantuan .= "/isolir NAMA — isolir manual satu pelanggan\n";
            $bantuan .= "/bukaisolir NAMA (atau /aktifkan NAMA) — buka isolir/suspend satu pelanggan\n";
            $bantuan .= "/nonaktifkan NAMA — nonaktifkan (suspend manual, bukan tunggakan)\n";
            $bantuan .= "/addoutlet — tambah Outlet baru\n";
            $bantuan .= "/addreseller — tambah Reseller baru\n";
        } else {
            // Biller (bukan admin) juga boleh pakai /tagihan, tapi
            // dibatasi HANYA lihat pelanggan tanggungannya sendiri --
            // lihat showTagihanUntukBiller(). Ditampilkan cuma untuk
            // Biller supaya Outlet/Reseller biasa tidak lihat perintah
            // yang memang bukan buat mereka.
            $outletChat = Outlet::where('telegram_chat_id', $chatId)->first();

            if ($outletChat && $outletChat->partner_type === \App\Enums\PartnerType::Biller) {
                $bantuan .= "\n<b>Khusus Biller</b>\n";
                $bantuan .= "/tagihan — tagihan belum lunas milik pelanggan Anda\n";
                $bantuan .= "/bayar NAMA — catat pembayaran pelanggan Anda\n";
            }
        }

        $telegram->sendMessage($chatId, $bantuan);
    }

    /**
     * Cek status aktif/belum aktif SATU voucher, dicari lewat kode/
     * username-nya. SENGAJA dibatasi cuma boleh cek voucher milik
     * outlet yang terhubung ke chat Telegram ini (bukan sembarang
     * voucher outlet lain) -- konsisten dengan aturan isolasi data per
     * outlet yang dipakai di seluruh aplikasi (lihat OutletScope).
     *
     * PENGECUALIAN buat ADMIN (permintaan user): chat ID yang sama
     * dengan "Chat ID Admin" di Pengaturan > Bot Telegram (kolom
     * telegram_admin_chat_id, dipakai juga untuk notifikasi top up
     * saldo -- lihat /admin di atas untuk cara dapat Chat ID-nya) boleh
     * cek voucher MILIK OUTLET MANAPUN, tidak dibatasi satu outlet
     * saja -- cocok buat CS/troubleshooting. Chat ID ini TIDAK perlu
     * terhubung ke Outlet manapun (beda dari outlet biasa yang wajib
     * /link dulu).
     *
     * "Aktif" di sini patokan UTAMANYA kolom status = VoucherStatus::Aktif
     * (sama seperti badge "Aktif" yang tampil di tabel Voucher di
     * aplikasi) -- BUKAN cuma activated_at kosong/tidak. Awalnya cuma
     * patokan activated_at, tapi ternyata voucher hasil IMPORT (menu
     * Voucher > Import) bisa saja statusnya sudah diisi Aktif langsung
     * dari file Excel-nya tanpa kolom Aktivasi Pertama terisi -- kalau
     * tetap patokan activated_at, voucher begitu selamanya kebaca
     * "belum aktif" walau di aplikasi sudah kelihatan Aktif.
     *
     * PENTING soal keterlambatan (penyebab laporan "sudah aktif tapi
     * bot bilang belum"): kolom activated_at TIDAK diisi seketika saat
     * pelanggan login pertama kali -- itu baru kedeteksi lewat tugas
     * terjadwal "vouchers:sync-activation" yang jalan tiap 5 MENIT
     * (baca tabel radacct punya FreeRADIUS). Jadi ada jeda sampai 5
     * menit antara voucher BENERAN aktif vs kolomnya ke-update. Supaya
     * /cek selalu akurat SAAT ITU JUGA (tidak perlu nunggu jadwal 5
     * menitan), kalau ternyata activated_at MASIH kosong, di sini
     * dipaksa jalankan sinkronisasinya DULU (persis logika yang sama
     * dipakai tugas terjadwal itu -- bukan dicek manual/beda logika),
     * baru voucher-nya dibaca ulang. Kalau memang belum ada login sama
     * sekali di radacct, tetap kebaca "Belum aktif" seperti seharusnya. */
    protected function handleCekVoucher(string $chatId, string $kode, TelegramService $telegram): void
    {
        // Chat ID admin (diisi di Pengaturan > Bot Telegram) boleh cek
        // voucher outlet MANAPUN -- lihat catatan panjang di atas
        // method ini, dan lihat isAdminChat() di bawah (dipakai bareng
        // dengan /pendapatan juga).
        $isAdmin = $this->isAdminChat($chatId);

        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        if (! $outlet && ! $isAdmin) {
            $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

            return;
        }

        if ($kode === '') {
            // Dari tombol "Cek Voucher" (atau /cek tanpa kode): tanyakan kodenya,
            // pesan berikutnya dipakai sebagai kode -- lihat handleMessage().
            Cache::put($this->cekVoucherFlowKey($chatId), true, now()->addMinutes(10));
            $telegram->sendMessage($chatId, "🔍 Ketik kode voucher yang mau dicek.\n\n(Atau langsung: <code>/cek KODEVOUCHER</code>. Ketik /batal untuk membatalkan.)");

            return;
        }

        $query = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->with('outlet')
            ->where('username', $kode);

        // Outlet biasa tetap dibatasi punya sendiri saja. Admin (Chat ID
        // cocok dengan Pengaturan) SENGAJA TIDAK dibatasi -- boleh lihat
        // voucher outlet mana pun.
        if (! $isAdmin) {
            $query->where('outlet_id', $outlet->id);
        }

        $voucher = $query->first();

        if (! $voucher) {
            $keterangan = $isAdmin ? '' : ' (atau bukan milik outlet ini)';
            $telegram->sendMessage($chatId, "❌ Voucher <code>{$kode}</code> tidak ditemukan{$keterangan}.");

            return;
        }

        // Khusus jawaban ke admin: sertakan juga nama outlet pemiliknya,
        // karena admin bisa cek voucher outlet mana pun (tidak otomatis
        // tahu ini punya outlet siapa seperti kalau dicek outlet sendiri).
        $labelOutlet = $isAdmin ? ' — Outlet: '.($voucher->outlet?->name ?? 'Stok Pusat') : '';

        // Cuma paksa sinkron kalau statusnya MEMANG masih "wajar" belum
        // aktif (Belum Terjual/Terjual) DAN activated_at kosong --
        // persis kandidat yang diproses syncActivations() juga (lihat
        // filter di dalamnya). Kalau dipaksa jalan padahal tidak perlu,
        // cuma buang-buang query tanpa hasil.
        if (! $voucher->activated_at && in_array($voucher->status, [
            \App\Enums\VoucherStatus::BelumTerjual,
            \App\Enums\VoucherStatus::Terjual,
        ], true)) {
            // Belum kebaca aktif di kolomnya -- paksa sinkron SEKARANG
            // (bukan tunggu jadwal 5 menitan), siapa tahu sebenarnya
            // SUDAH login tapi belum sempat kedeteksi tugas terjadwal.
            // syncActivations() otomatis hanya memproses voucher yang
            // memang belum ada activated_at-nya (Belum Terjual/Terjual),
            // jadi aman & murah dipanggil kapan saja -- ini FUNGSI YANG
            // SAMA PERSIS dipakai tugas terjadwalnya, bukan logika
            // tiruan, supaya hasilnya (transaksi otomatis, expired_at,
            // sinkron FreeRADIUS, dst) tetap konsisten dengan yang lain.
            try {
                app(\App\Services\VoucherGeneratorService::class)->syncActivations();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Gagal sinkron aktivasi voucher saat /cek bot Telegram', [
                    'username' => $voucher->username,
                    'error' => $e->getMessage(),
                ]);
            }

            $voucher->refresh();
        }

        // PENTING: patokan utamanya kolom STATUS (VoucherStatus::Aktif),
        // BUKAN cuma activated_at kosong/tidak. Awalnya /cek cuma lihat
        // activated_at -- ternyata voucher hasil IMPORT (menu Voucher >
        // Import, App\Imports\VoucherImport) bisa saja file Excel-nya
        // sudah mengisi kolom "Status" = Aktif langsung (mis. migrasi
        // data lama), TANPA kolom "Aktivasi Pertama" terisi -- jadi
        // activated_at-nya tetap kosong walau statusnya sudah Aktif.
        // syncActivations() juga TIDAK PERNAH menyentuh voucher seperti
        // ini (dia cuma memproses status Belum Terjual/Terjual), jadi
        // kalau tetap patokan activated_at, voucher begini SELAMANYA
        // kebaca "belum aktif" walau sebenarnya sudah Aktif di tabel
        // aplikasi. Sekarang status yang jadi acuan utama (sama seperti
        // badge "Aktif" yang tampil di tabel Voucher di aplikasi) --
        // activated_at/expired_at cuma dipakai buat detail tanggalnya,
        // dengan teks pengganti kalau kebetulan kosong (data lama/impor).
        // CEK-VOUCHER-EXPIRED (2026-10-16): voucher kadaluarsa / dinonaktifkan
        // dulu dilaporkan "Belum aktif" karena semua status selain Aktif jatuh
        // ke jawaban itu. Tanggal expired yang sudah lewat juga dihitung
        // kadaluarsa walau statusnya belum sempat diubah oleh penjadwal.
        $tyaSudahLewat = $voucher->expired_at && $voucher->expired_at->isPast();

        if ($voucher->status === \App\Enums\VoucherStatus::Expired || ($tyaSudahLewat && $voucher->activated_at)) {
            $tyaAktif = $voucher->activated_at ? $voucher->activated_at->format('d-m-Y H:i') : 'tidak tercatat';
            $tyaExpired = $voucher->expired_at ? $voucher->expired_at->format('d-m-Y H:i') : 'tidak tercatat';
            $telegram->sendMessage($chatId, "⚫ Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Sudah kadaluarsa (expired)\nAktif: {$tyaAktif}\nExpired: {$tyaExpired}");

            return;
        }

        if ($voucher->status === \App\Enums\VoucherStatus::Disabled) {
            $telegram->sendMessage($chatId, "⛔ Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Dinonaktifkan");

            return;
        }

        if ($voucher->status !== \App\Enums\VoucherStatus::Aktif) {
            $telegram->sendMessage($chatId, "🔴 Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Belum aktif");

            return;
        }

        $tglAktif = $voucher->activated_at ? $voucher->activated_at->format('d-m-Y H:i') : 'tidak tercatat (voucher lama/hasil impor)';
        $tglExpired = $voucher->expired_at ? $voucher->expired_at->format('d-m-Y H:i') : 'belum ditentukan';

        $telegram->sendMessage($chatId, "🟢 Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Voucher sudah aktif Tgl {$tglAktif}, Expired Tgl {$tglExpired}");
    }

    /**
     * BARU (permintaan user): putuskan (kick) SESSION ONLINE voucher
     * hotspot LANGSUNG dari router lewat bot -- fungsi backend-nya
     * (resolve router via NAS IP, lalu MikrotikService::disconnectUser())
     * PERSIS SAMA dengan tombol "Kick Koneksi" di menu Hotspot > User
     * Online aplikasi (lihat HotspotOnlineResource, DisconnectUserAction).
     * Perbedaannya cuma cara mencari sesinya: di sini lewat kode voucher
     * yang diketik user, bukan pilih baris dari tabel.
     *
     * Akses & pencarian vouchernya pakai pola PERSIS sama dengan /cek
     * (lihat handleCekVoucher() di atas) -- Outlet biasa cuma boleh
     * voucher miliknya sendiri, Admin boleh voucher outlet manapun.
     */
    protected function handlePutusVoucherSession(string $chatId, string $kode, TelegramService $telegram): void
    {
        $isAdmin = $this->isAdminChat($chatId);
        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        if (! $outlet && ! $isAdmin) {
            $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

            return;
        }

        if ($kode === '') {
            $telegram->sendMessage($chatId, "Kirim kode vouchernya, format: /putusvoucher KODEVOUCHER\n\nContoh: /putusvoucher ABCD1234\n\nDipakai buat memutus paksa voucher yang lagi online (mis. lupa logout / dipakai bareng beberapa perangkat) langsung dari router.");

            return;
        }

        $query = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('username', $kode);

        // Sama seperti /cek: Outlet biasa dibatasi vouchernya sendiri
        // saja, Admin tidak dibatasi sama sekali.
        if (! $isAdmin) {
            $query->where('outlet_id', $outlet->id);
        }

        $voucher = $query->first();

        if (! $voucher) {
            $keterangan = $isAdmin ? '' : ' (atau bukan milik outlet ini)';
            $telegram->sendMessage($chatId, "❌ Voucher <code>{$kode}</code> tidak ditemukan{$keterangan}.");

            return;
        }

        // Username di tabel `voucher` TIDAK memakai awalan tenant, tapi
        // di radacct (yang dipakai bersama semua tenant) PAKAI --
        // lihat App\Support\RadiusUsername untuk alasan lengkapnya. Kalau
        // langsung dicocokkan tanpa dikonversi dulu, sesinya TIDAK AKAN
        // pernah ketemu di instalasi multi-tenant.
        $usernameRadius = \App\Support\RadiusUsername::forRadius($voucher->username);

        $session = \App\Models\Radacct::where('username', $usernameRadius)
            ->online()
            ->first();

        if (! $session) {
            $telegram->sendMessage($chatId, "ℹ️ Voucher <code>{$voucher->username}</code> sedang <b>TIDAK online</b> (tidak ada sesi aktif di radacct). Tidak ada yang perlu diputus.");

            return;
        }

        $router = app(\App\Services\RouterResolver::class)->resolveForNasIp($session->nasipaddress);

        if (! $router) {
            $telegram->sendMessage($chatId, "❌ Router tidak ditemukan untuk NAS <code>{$session->nasipaddress}</code>.\n\nDaftarkan IP ini di menu NAS aplikasi dan hubungkan ke Router yang sesuai, lalu coba lagi.");

            return;
        }

        $hasil = app(\App\Actions\DisconnectUserAction::class)->execute($router, $session->username);

        if ($hasil['success'] ?? false) {
            $telegram->sendMessage($chatId, "✅ Sesi voucher <code>{$voucher->username}</code> berhasil diputus dari router <b>{$router->name}</b>.\n\nUser harus login ulang untuk connect lagi.");
        } else {
            $telegram->sendMessage($chatId, "❌ Gagal memutus sesi voucher <code>{$voucher->username}</code>.\n\n".($hasil['error'] ?? 'Sebab tidak diketahui.'));
        }
    }

    protected function handleLink(string $chatId, string $code, TelegramService $telegram): void
    {
        if (! $code) {
            $telegram->sendMessage($chatId, "Kirim kode link dari admin, format: /link KODE");

            return;
        }

        $outlet = Outlet::where('telegram_link_code', $code)->first();

        // BARU (permintaan user): outlet/reseller/biller yang sudah
        // diblokir TIDAK BOLEH terhubung lagi lewat kode link apa pun --
        // biar konsisten walau mereka masih pegang kode lama atau minta
        // kode baru ke admin.
        if ($outlet && $outlet->telegram_blocked) {
            $telegram->sendMessage($chatId, '❌ Akun ini sudah diblokir dari bot Telegram. Hubungi admin kalau ini keliru.');

            return;
        }

        if ($outlet) {
            $outlet->update(['telegram_chat_id' => $chatId, 'telegram_link_code' => null]);
        }

        if (! $outlet) {
            $telegram->sendMessage($chatId, "❌ Kode link tidak valid atau sudah terpakai. Minta admin buat kode baru.");

            return;
        }

        // Tombol menu langsung dipasang begitu terhubung (sesuai jenis mitranya).
        $tombol = \App\Support\TelegramMenu::keyboard(\App\Support\TelegramMenu::tombolUntuk($this->peranMenu($chatId)));
        $telegram->sendMessage(
            $chatId,
            "✅ Berhasil terhubung sebagai outlet <b>{$outlet->name}</b>!\n\n".($tombol ? 'Tombol menu sudah muncul di bawah 👇' : 'Ketik /bantuan untuk daftar perintah.'),
            $tombol
        );
    }

    /**
     * Peran chat ini untuk menentukan tombol menu: admin / reseller / outlet /
     * biller, atau 'tamu' kalau belum terhubung ke mitra mana pun.
     */
    protected function peranMenu(string $chatId): string
    {
        if ($this->isAdminChat($chatId)) {
            return 'admin';
        }

        // SETORAN-KASIR-BOT: kasir (bukan admin) -> menu kasir.
        if (\App\Services\SetoranKasirBot::adalahKasir($chatId)) {
            return 'kasir';
        }

        $mitra = Outlet::where('telegram_chat_id', $chatId)->first();

        if (! $mitra) {
            return 'tamu';
        }

        return match ($mitra->partner_type) {
            \App\Enums\PartnerType::Biller => 'biller',
            \App\Enums\PartnerType::Reseller => 'reseller',
            default => 'outlet',
        };
    }

    /**
     * Kirim pesan pembawa tombol menu permanen (reply keyboard). Pesan ini
     * SENGAJA hanya dikirim lewat /menu, /start, dan /link -- bukan ditempel ke
     * pesan "Perintah tidak dikenali", karena pesan itu bisa dihapus otomatis
     * oleh fitur rapikan chat, dan tombolnya bisa ikut hilang.
     */
    protected function kirimMenu(string $chatId, TelegramService $telegram, ?string $judul = null): void
    {
        $tombol = \App\Support\TelegramMenu::keyboard(\App\Support\TelegramMenu::tombolUntuk($this->peranMenu($chatId)));

        if ($tombol === null) {
            $telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke outlet manapun.\n\nMinta admin buat kode link, lalu kirim: /link KODE");

            return;
        }

        $telegram->sendMessage(
            $chatId,
            $judul ?? "⌨️ <b>Menu siap</b> -- tekan tombol di bawah.\n\nKetik /bantuan untuk daftar perintah lengkap, atau /tutupmenu untuk menyembunyikan tombol.",
            $tombol
        );
    }

    protected function handleVoiceNote(string $chatId, string $fileId, TelegramService $telegram): void
    {
        $telegram->sendMessage($chatId, '🎙️ Memproses pesan suara...');

        $fileUrl = $telegram->getFileUrl($fileId);

        if (! $fileUrl) {
            $telegram->sendMessage($chatId, '❌ Gagal mengambil file suara dari Telegram.');

            return;
        }

        $teks = app(\App\Services\WhisperService::class)->transcribe($fileUrl);

        if (! $teks) {
            $telegram->sendMessage($chatId, '❌ Gagal memproses suara. Coba lagi atau ketik perintahnya langsung.');

            return;
        }

        // LANGKAH 1 (logic LAMA, dipertahankan APA ADANYA -- sudah lama
        // terbukti akurat, sengaja TIDAK disentuh): coba dulu cocokkan
        // sebagai permintaan GENERATE VOUCHER lewat pencocokan angka
        // paket+jumlah langsung dari ucapan. Cuma berlaku buat
        // Outlet/Reseller yang memang boleh generate voucher (Biller
        // tidak, sama seperti sebelumnya).
        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        if ($outlet && $outlet->partner_type !== \App\Enums\PartnerType::Biller) {
            preg_match_all('/\d+/', $teks, $angkaMatches);
            $angkaDiTeks = $angkaMatches[0] ?? [];

            // is_member_only excluded (permintaan user) -- sama seperti
            // showPackageMenu(), paket "Khusus Member" tidak boleh ikut
            // dicocokkan dari perintah suara untuk generate voucher.
            $paketTersedia = \App\Models\ServicePackage::where('is_active', true)
                ->where('is_member_only', false)
                ->whereIn('type', ['hotspot', 'both'])
                ->get()
                ->filter(fn ($p) => $p->isAvailableForOutlet($outlet->id))
                ->values();

            $paketCocok = null;

            foreach ($paketTersedia as $paket) {
                preg_match_all('/\d+/', $paket->name, $angkaPaket);

                foreach (($angkaPaket[0] ?? []) as $angka) {
                    if (in_array($angka, $angkaDiTeks)) {
                        $paketCocok = $paket;

                        break 2;
                    }
                }
            }

            if ($paketCocok) {
                $jumlah = 1;

                foreach ($angkaDiTeks as $angka) {
                    if (! str_contains($paketCocok->name, $angka) && (int) $angka > 0 && (int) $angka < 1000) {
                        $jumlah = (int) $angka;

                        break;
                    }
                }

                $telegram->sendMessage($chatId, "🎙️ Saya dengar: \"{$teks}\"");

                // BARU (permintaan user): jangan langsung generate dari
                // hasil suara, tampilkan konfirmasi dulu -- lihat
                // showGenerateConfirmation(). Paket/jumlah hasil
                // tebakan dari ucapan bisa saja salah tangkap, jadi
                // WAJIB dikonfirmasi dulu sebelum voucher benar-benar
                // dibuat.
                $this->showGenerateConfirmation($chatId, null, $paketCocok->id, $jumlah, $outlet, $telegram);

                return;
            }
        }

        // LANGKAH 2 (BARU, permintaan user): bukan permintaan generate
        // voucher (atau chat ini bukan outlet yang boleh generate
        // voucher sama sekali, mis. Admin/Biller) -- coba kenali
        // sebagai salah satu PERINTAH TEKS lain yang ada (bayar,
        // isolir, ganti paket, dst) lewat AI, lalu jalankan PERSIS lewat
        // jalur perintah teks biasa (handleMessage()) supaya OTOMATIS
        // mewarisi semua pengecekan hak akses & alur konfirmasi masing-
        // masing perintah itu -- tidak ada logic aksi yang ditulis
        // ulang di sini, service ini murni menerjemahkan ucapan jadi
        // teks perintah saja.
        $perintah = app(\App\Services\VoicePerintahService::class)->kenali($teks);

        if (! $perintah) {
            $telegram->sendMessage($chatId, "🎙️ Saya dengar: \"{$teks}\"\n\nMaaf, saya tidak berhasil mengenali perintah apa yang dimaksud. Coba ketik perintahnya langsung, atau kirim /bantuan untuk lihat daftar lengkap.");

            return;
        }

        $telegram->sendMessage($chatId, "🎙️ Saya dengar: \"{$teks}\"\n➡️ Menjalankan: <code>{$perintah}</code>");

        $this->handleMessage(['chat' => ['id' => $chatId], 'text' => $perintah], $telegram);
    }

    /**
     * Daftar outlet buat ADMIN pilih dulu sebelum generate voucher --
     * beda dari showPackageMenu() biasa yang langsung dipakai kalau
     * yang minta itu si outlet sendiri. Biller DIKECUALIKAN dari daftar
     * ini (partner_type Biller memang tidak boleh generate voucher sama
     * sekali, lihat pengecekan di /voucher & handleVoiceNote()).
     */
    protected function showOutletPickerForVoucher(string $chatId, TelegramService $telegram): void
    {
        $outlets = Outlet::where('is_active', true)
            ->where('partner_type', '!=', \App\Enums\PartnerType::Biller)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        if ($outlets->isEmpty()) {
            $telegram->sendMessage($chatId, 'Belum ada outlet/reseller aktif.');

            return;
        }

        $buttons = [];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "vchout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Mau generate voucher atas nama outlet/reseller mana?', ['inline_keyboard' => $buttons]);
    }

    protected function showPackageMenu(string $chatId, Outlet $outlet, TelegramService $telegram): void
    {

        // where('is_member_only', false) (permintaan user): paket yang
        // ditandai "Khusus Member" di Profile Hotspot TIDAK BOLEH
        // muncul di sini -- itu paket langganan bulanan yang dikelola
        // lewat menu Member, bukan buat digenerate jadi voucher.
        $packages = ServicePackage::where('is_active', true)
            ->where('is_member_only', false)
            ->whereIn('type', ['hotspot', 'both'])
            ->get()
            ->filter(fn ($p) => $p->isAvailableForOutlet($outlet->id))
            ->values();


        if ($packages->isEmpty()) {
            $telegram->sendMessage($chatId, 'Belum ada paket hotspot aktif.');

            return;
        }

        $buttons = $packages->map(fn ($p) => [[
            'text' => $p->name,
            'callback_data' => "pkg:{$p->id}",
        ]])->toArray();


        $telegram->sendMessage($chatId, 'Pilih paket voucher:', ['inline_keyboard' => $buttons]);

    }


    protected function handleCallback(array $callback, TelegramService $telegram): void
    {
        $chatId = (string) $callback['message']['chat']['id'];
        $messageId = (int) $callback['message']['message_id'];
        $data = $callback['data'] ?? '';
        $telegram->answerCallbackQuery($callback['id']);

        // HAPUS-PENJUALAN: tombol konfirmasi /hapusjual
        if (str_starts_with($data, 'hpsjual:') || $data === 'hpsjual_batal') {
            \App\Services\HapusPenjualanBot::callback($chatId, $messageId, $data, $telegram);

            return;
        }

        // SETORAN-KASIR-BOT: tombol "setor:*" (alur /setor & rekap).
        if (str_starts_with($data, 'setor:')
            && (new \App\Services\SetoranKasirBot($telegram))->handleCallback($chatId, $messageId, $data, $this->isAdminChat($chatId))) {
            return;
        }

        if (str_starts_with($data, 'topup_approve:') || str_starts_with($data, 'topup_reject:')) {
            $approve = str_starts_with($data, 'topup_approve:');
            $requestId = (int) substr($data, strpos($data, ':') + 1);
            $topupRequest = \App\Models\SaldoTopupRequest::find($requestId);

            if (! $topupRequest || $topupRequest->status !== 'pending') {
                $telegram->editMessageText($chatId, $messageId, 'Permintaan ini sudah diproses sebelumnya.');

                return;
            }

            $outletTopup = $topupRequest->outlet;
            $formatted = 'Rp'.number_format($topupRequest->amount, 0, ',', '.');

            if ($approve) {
                try {
                    app(\App\Services\SaldoService::class)->topup($outletTopup, (float) $topupRequest->amount, null, 'Top up via Telegram (disetujui admin)');
                    $topupRequest->update(['status' => 'approved', 'reviewed_at' => now()]);

                    $telegram->editMessageText($chatId, $messageId, "Disetujui\n\nOutlet: {$outletTopup->name}\nJumlah: {$formatted}\nSaldo baru: Rp".number_format($outletTopup->fresh()->saldo, 0, ',', '.'));

                    if ($outletTopup->telegram_chat_id) {
                        $telegram->sendMessage($outletTopup->telegram_chat_id, "Top up {$formatted} disetujui admin!\n\nSaldo baru: Rp".number_format($outletTopup->fresh()->saldo, 0, ',', '.'));
                    }
                } catch (\Throwable $e) {
                    $telegram->editMessageText($chatId, $messageId, 'Gagal memproses: '.$e->getMessage());
                }
            } else {
                $topupRequest->update(['status' => 'rejected', 'reviewed_at' => now()]);

                $telegram->editMessageText($chatId, $messageId, "Ditolak\n\nOutlet: {$outletTopup->name}\nJumlah: {$formatted}");

                if ($outletTopup->telegram_chat_id) {
                    $telegram->sendMessage($outletTopup->telegram_chat_id, "Permintaan top up {$formatted} ditolak admin.");
                }
            }

            return;
        }
        // Tombol alur /wifi: wfv:ID (lihat/cek lagi), wfr:ID (muat data Wi-Fi
        // dari ONU), wfs:ID:N (ganti SSID WLAN N), wfp:ID:N (ganti password
        // WLAN N), wfok (terapkan yang sudah dikonfirmasi), wfno (batal).
        // Izin dicek ULANG di setiap tombol (pelangganBolehUbahWifi) -- id di
        // tombol lama tidak boleh dipercaya.
        if (preg_match('/^(wfv|wfr|wfs|wfp):(\d+)(?::(\d+))?$/', $data, $mWf) || $data === 'wfok' || $data === 'wfno') {
            $this->handleWifiCallback($chatId, $messageId, $data, $mWf ?? [], $telegram);

            return;
        }

        // Tombol alur /redaman: "rdmcust:ID" (tampilkan/cek lagi) dan
        // "rdmref:ID" (minta ONU mengirim data terbaru). Id pelanggan
        // SELALU dicek ulang terhadap akun yang menekan tombol
        // (pelangganBolehDilihat) -- jangan percaya id dari tombol lama.
        if (str_starts_with($data, 'rdmcust:') || str_starts_with($data, 'rdmref:')) {
            [$aksiRdm, $idRdm] = array_pad(explode(':', $data, 2), 2, '');

            $pelangganRdm = Customer::query()
                ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->find((int) $idRdm);

            if (! $pelangganRdm || ! $this->pelangganBolehDilihat($chatId, $pelangganRdm)) {
                $telegram->editMessageText($chatId, $messageId, 'Pelanggan tidak ditemukan atau bukan tanggungan akun ini.');

                return;
            }

            $catatanRdm = '';

            if ($aksiRdm === 'rdmref' && $pelangganRdm->acs_device_id) {
                $hasilRdm = app(\App\Services\GenieAcsService::class)->tugas($pelangganRdm->acs_device_id, 'refresh');

                $catatanRdm = $hasilRdm['ok']
                    ? '♻️ Permintaan data terbaru sudah dikirim ke ONU. Tunggu 1-2 menit lalu tekan 🔄 Cek lagi.'
                    : '❌ Gagal meminta data terbaru: '.htmlspecialchars((string) $hasilRdm['pesan'], ENT_QUOTES, 'UTF-8');
            }

            $this->kirimRedaman($chatId, $messageId, $pelangganRdm, $telegram, $catatanRdm);

            return;
        }

        // ============================================================
        // Tombol untuk perintah KHUSUS ADMIN. Ditaruh SEBELUM pencarian
        // outlet di bawah, karena admin tidak perlu terhubung ke outlet
        // manapun untuk memakainya.
        //
        // Semua id dititipkan di callback_data (mis. "gppkg:12:3"),
        // BUKAN di Cache. Alasannya: state di Cache bisa kedaluwarsa
        // atau hilang saat cache dibersihkan, dan tombol yang sudah
        // terkirim jadi mati tanpa penjelasan. Dengan id di dalam
        // tombolnya sendiri, pesan lama tetap bisa ditekan kapan saja.
        // Batas callback_data 64 byte, dan "prefix:id:id" masih jauh di
        // bawah itu.
        // ============================================================
        // "baycust"/"bayinv"/"bayok"/"bmuka"/"bmukaok" (alur /bayar --
        // catat pembayaran) DIKECUALIKAN dari gerbang khusus-admin di
        // bawah: Biller sekarang juga boleh, TAPI dibatasi cuma untuk
        // pelanggan tanggungannya sendiri (idPelangganMilikBiller()) --
        // dicek satu-satu di masing-masing aksi sebelum lanjut, supaya
        // Biller tidak bisa mencatat pembayaran pelanggan biller lain
        // walau tahu/menebak ID-nya. Prefix lain (ganti paket, isolir,
        // buka isolir, nonaktifkan) TETAP khusus admin seperti semula.
        $prefixBayar = ['baycust', 'bayinv', 'bayok', 'bmuka', 'bmukaok', 'cetakcust', 'cetakprn'];
        $prefixAdminSaja = ['gpcust', 'gppkg', 'isocust', 'isook', 'bkacust', 'bkaok', 'noncust', 'nonok'];

        if (str_starts_with($data, 'gpcust:') || str_starts_with($data, 'gppkg:')
            || str_starts_with($data, 'baycust:') || str_starts_with($data, 'bayinv:')
            || str_starts_with($data, 'bayok:') || str_starts_with($data, 'bmuka:')
            || str_starts_with($data, 'bmukaok:') || str_starts_with($data, 'cetakcust:') || str_starts_with($data, 'cetakprn:')
            || str_starts_with($data, 'isocust:') || str_starts_with($data, 'isook:')
            || str_starts_with($data, 'bkacust:') || str_starts_with($data, 'bkaok:')
            || str_starts_with($data, 'noncust:') || str_starts_with($data, 'nonok:')) {

            $bagian = explode(':', $data);
            $aksi = $bagian[0];

            $biller = null;

            if (! $this->isAdminChat($chatId)) {
                if (in_array($aksi, $prefixAdminSaja, true)) {
                    $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                    return;
                }

                // Jaga-jaga (defensif): kalau suatu saat ada prefix baru
                // ditambahkan ke kondisi if besar di atas tapi lupa
                // didaftarkan ke salah satu dari $prefixAdminSaja/
                // $prefixBayar, jangan pernah anggap otomatis boleh --
                // tetap ditolak.
                if (! in_array($aksi, $prefixBayar, true)) {
                    $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                    return;
                }

                // Coba resolve sebagai Biller.
                $biller = Outlet::where('telegram_chat_id', $chatId)
                    ->where('partner_type', \App\Enums\PartnerType::Biller)
                    ->first();

                if (! $biller) {
                    $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                    return;
                }

                // Ownership check: pelanggan/invoice yang ditunjuk ID di
                // callback_data harus benar tanggungan biller ini.
                $customerId = match ($aksi) {
                    'baycust', 'bmuka', 'bmukaok', 'cetakcust', 'cetakprn' => (int) ($bagian[1] ?? 0),
                    'bayinv', 'bayok' => (int) (\App\Models\Invoice::find((int) ($bagian[1] ?? 0))?->customer_id ?? 0),
                    default => 0,
                };

                if (! $customerId || ! $this->idPelangganMilikBiller($biller)->contains($customerId)) {
                    $telegram->editMessageText($chatId, $messageId, 'Pelanggan ini bukan tanggungan Anda.');

                    return;
                }
            }

            if ($aksi === 'gpcust') {
                $this->showPilihanPaketBaru($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'gppkg') {
                $this->terapkanGantiPaket($chatId, $messageId, (int) ($bagian[1] ?? 0), (int) ($bagian[2] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'baycust') {
                $this->showTagihanPelanggan($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'cetakcust') {
                $this->showPilihanPrinterCetakUlang($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'cetakprn') {
                $this->cetakUlangStruk($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram, (string) ($bagian[2] ?? 'pos58'));

                return;
            }

            if ($aksi === 'bayinv') {
                $this->showKonfirmasiBayar($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'bayok') {
                $this->terapkanPembayaran($chatId, $messageId, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'bmuka') {
                $this->showKonfirmasiBayarMuka($chatId, $messageId, (int) ($bagian[1] ?? 0), (int) ($bagian[2] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'bmukaok') {
                $this->terapkanBayarMuka($chatId, $messageId, (int) ($bagian[1] ?? 0), (int) ($bagian[2] ?? 0), $telegram);

                return;
            }

            // "isocust"/"bkacust"/"noncust" = dari daftar pilihan (banyak
            // hasil pencarian) -> tampilkan layar konfirmasi. "isook"/
            // "bkaok"/"nonok" = tombol "Ya" di layar konfirmasi itu ->
            // benar-benar dieksekusi.
            if ($aksi === 'isocust' || $aksi === 'bkacust' || $aksi === 'noncust') {
                $prefix = substr($aksi, 0, -4); // buang akhiran "cust"
                $this->showKonfirmasiAksiStatus($chatId, $messageId, $prefix, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }

            if ($aksi === 'isook' || $aksi === 'bkaok' || $aksi === 'nonok') {
                $prefix = substr($aksi, 0, -2); // buang akhiran "ok"
                $this->terapkanAksiStatus($chatId, $messageId, $prefix, (int) ($bagian[1] ?? 0), $telegram);

                return;
            }
        }

        $telegram->answerCallbackQuery($callback['id']);

        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        // BARU (permintaan user): admin generate voucher ATAS NAMA
        // outlet yang dipilihnya sendiri (lewat 'vchout:' di bawah) --
        // kalau chat ini admin DAN tidak punya outlet sendiri, coba
        // pakai outlet yang tersimpan di cache "acting as" (diisi pas
        // admin memilih outlet di showOutletPickerForVoucher()). Dengan
        // ini, pkg:/qty:/qtycustom:/gengo: di bawah OTOMATIS ikut jalan
        // buat admin juga tanpa perlu diubah satu-satu -- semuanya baca
        // dari variabel $outlet yang sama ini.
        if (! $outlet && $this->isAdminChat($chatId)) {
            $actingOutletId = Cache::get($this->adminActingOutletKey($chatId));

            if ($actingOutletId) {
                $outlet = Outlet::find($actingOutletId);
            }
        }

        // "rev:"/"revout:" (laporan pendapatan), "stokout:"/"stokpkg:"
        // (cek stok) & "pppoeout:"/"pppoepkg:" (tambah pelanggan PPPoE,
        // lihat di bawah) BOLEH lanjut tanpa outlet kalau yang minta
        // admin -- "vchout:" (pilih outlet buat generate voucher) juga
        // termasuk, karena justru itu langkah admin MEMILIH outletnya.
        $bolehTanpaOutlet = (str_starts_with($data, 'rev:') || str_starts_with($data, 'revout:') || str_starts_with($data, 'stokout:') || str_starts_with($data, 'stokpkg:') || str_starts_with($data, 'stokpdf:') || str_starts_with($data, 'pppoeout:') || str_starts_with($data, 'pppoepkg:') || str_starts_with($data, 'pppoebill:') || str_starts_with($data, 'saldoout:') || str_starts_with($data, 'batchout:') || str_starts_with($data, 'batchpdf:') || str_starts_with($data, 'vchout:')
            // BARU: langkah-langkah admin buat /laporanpdf, /addstaticip,
            // /addmember & /daftarmember -- pola sama dengan yang di atas
            // (admin belum tentu terhubung ke outlet manapun, tapi tetap
            // boleh memilih outlet/wilayah dari daftar).
            || str_starts_with($data, 'lappdf:') || str_starts_with($data, 'lappdfarea:')
            || str_starts_with($data, 'lapout:') || str_starts_with($data, 'lapoutarea:')
            || str_starts_with($data, 'sippkg:') || str_starts_with($data, 'sipbill:')
            || str_starts_with($data, 'membout:') || str_starts_with($data, 'membpkg:') || str_starts_with($data, 'membbill:')
            || str_starts_with($data, 'daftarmember:')
            // BARU: langkah pilih Area di alur /addoutlet & /addreseller.
            || str_starts_with($data, 'mitraarea:')) && $this->isAdminChat($chatId);

        if (! $outlet && ! $bolehTanpaOutlet) {
            $telegram->editMessageText($chatId, $messageId, 'Sesi berakhir — akun tidak lagi terhubung.');

            return;
        }

        if (str_starts_with($data, 'vchout:')) {
            $outletDipilih = Outlet::find((int) substr($data, strlen('vchout:')));

            if (! $outletDipilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            // Simpan pilihan outlet-nya 15 menit -- cukup buat admin
            // menyelesaikan alur pilih paket -> jumlah -> konfirmasi.
            Cache::put($this->adminActingOutletKey($chatId), $outletDipilih->id, now()->addMinutes(15));

            $this->showPackageMenu($chatId, $outletDipilih, $telegram);

            return;
        }

        if (str_starts_with($data, 'pkg:')) {
            $pkgId = (int) substr($data, 4);
            $package = ServicePackage::find($pkgId);

            if (! $package) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            // Preset 1/5/10/20 buat kebutuhan harian biasa, 50-1000 buat
            // generate sekali banyak, dan "Jumlah lain..." (permintaan
            // user) buat angka berapa pun di luar preset ini, mis. 2000
            // -- lihat qtycustom: di bawah & handleVoucherQtyFlowInput().
            $buttons = [
                [
                    ['text' => '1', 'callback_data' => "qty:{$pkgId}:1"],
                    ['text' => '5', 'callback_data' => "qty:{$pkgId}:5"],
                    ['text' => '10', 'callback_data' => "qty:{$pkgId}:10"],
                    ['text' => '20', 'callback_data' => "qty:{$pkgId}:20"],
                ],
                [
                    ['text' => '50', 'callback_data' => "qty:{$pkgId}:50"],
                    ['text' => '100', 'callback_data' => "qty:{$pkgId}:100"],
                    ['text' => '500', 'callback_data' => "qty:{$pkgId}:500"],
                    ['text' => '1000', 'callback_data' => "qty:{$pkgId}:1000"],
                ],
                [
                    ['text' => '✍️ Jumlah lain...', 'callback_data' => "qtycustom:{$pkgId}"],
                ],
            ];

            $telegram->editMessageText($chatId, $messageId, "Paket: <b>{$package->name}</b>\n\nPilih jumlah voucher:", ['inline_keyboard' => $buttons]);

            return;
        }

        if (str_starts_with($data, 'qty:')) {
            [, $pkgId, $qty] = explode(':', $data);
            // BARU (permintaan user): jangan langsung generate, tampilkan
            // konfirmasi dulu -- lihat showGenerateConfirmation().
            $this->showGenerateConfirmation($chatId, $messageId, (int) $pkgId, (int) $qty, $outlet, $telegram);

            return;
        }

        if (str_starts_with($data, 'gengo:')) {
            [, $pkgId, $qty] = explode(':', $data);
            $this->generateAndReply($chatId, $messageId, (int) $pkgId, (int) $qty, $outlet, $telegram);

            return;
        }

        if ($data === 'gencancel') {
            $telegram->editMessageText($chatId, $messageId, '❌ Dibatalkan. Tidak ada voucher yang dibuat.');

            return;
        }

        if (str_starts_with($data, 'qtycustom:')) {
            $pkgId = (int) substr($data, strlen('qtycustom:'));

            Cache::put($this->voucherQtyFlowKey($chatId), [
                'pkg_id' => $pkgId,
                'outlet_id' => $outlet->id,
            ], now()->addMinutes(10));

            $telegram->editMessageText($chatId, $messageId,
                'Ketik jumlah voucher yang mau dibuat (angka saja, maksimal '.\App\Services\VoucherGeneratorService::MAX_QUANTITY.").\nKirim /batal buat membatalkan."
            );

            return;
        }

        if (str_starts_with($data, 'batchout:')) {
            $outletId = substr($data, strlen('batchout:'));
            $batchOutlet = $outletId === 'all' ? null : Outlet::find((int) $outletId);
            $this->editBatchList($chatId, $messageId, $batchOutlet, $telegram);

            return;
        }

        if (str_starts_with($data, 'batchpdf:')) {
            $batchCode = substr($data, strlen('batchpdf:'));
            $this->kirimPdfBatchByCode($chatId, $messageId, $batchCode, $telegram);

            return;
        }
        if (str_starts_with($data, 'rev:')) {
            $period = substr($data, 4);

            if ($this->tanganiNavigasiPeriode('rev', $period, 'Pilih periode laporan:', $chatId, $messageId, $telegram)) {
                return;
            }

            // Outlet biasa: langsung tampilkan laporan punya sendiri,
            // tidak perlu pilih apa-apa lagi (cuma ada satu pilihan).
            // Admin: SELALU tampilkan dulu daftar outlet buat DIPILIH
            // (termasuk opsi "Semua Outlet" buat laporan gabungan) --
            // walaupun chat Telegram admin ini KEBETULAN juga ter-link ke
            // salah satu outlet (mis. bekas testing /link sebelumnya).
            // Sebelumnya kalau chat admin ter-link ke outlet, $outlet
            // langsung terisi dan menu pilih outlet ini KELEWATAN --itulah
            // yang bikin "belum muncul outletnya".
            if ($outlet && ! $this->isAdminChat($chatId)) {
                $this->showRevenueReport($chatId, $messageId, $outlet, $period, $telegram);
            } else {
                $this->showOutletPickerForRevenue($chatId, $messageId, $period, $telegram);
            }

            return;
        }

        if (str_starts_with($data, 'revout:')) {
            // Format: revout:{period}:{outletId|all}
            [, $period, $outletIdOrAll] = explode(':', $data, 3);

            $outletTerpilih = $outletIdOrAll === 'all' ? null : Outlet::find((int) $outletIdOrAll);

            if ($outletIdOrAll !== 'all' && ! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->showRevenueReport($chatId, $messageId, $outletTerpilih, $period, $telegram);

            return;
        }

        if (str_starts_with($data, 'lappdf:')) {
            $period = substr($data, strlen('lappdf:'));

            if (! $this->isAdminChat($chatId)) {
                $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                return;
            }

            if ($this->tanganiNavigasiPeriode('lappdf', $period, 'Pilih periode laporan PDF:', $chatId, $messageId, $telegram)) {
                return;
            }

            $this->showAreaPickerForLaporanPdf($chatId, $messageId, $period, $telegram);

            return;
        }

        if (str_starts_with($data, 'lappdfarea:')) {
            // Format: lappdfarea:{period}:{areaId|all}
            [, $period, $areaIdOrAll] = explode(':', $data, 3);

            $area = $areaIdOrAll === 'all' ? null : \App\Models\Area::find((int) $areaIdOrAll);

            if ($areaIdOrAll !== 'all' && ! $area) {
                $telegram->editMessageText($chatId, $messageId, 'Wilayah tidak ditemukan.');

                return;
            }

            $this->kirimLaporanPendapatanPdf($chatId, $messageId, $area, $period, $telegram);

            return;
        }

        if (str_starts_with($data, 'lapout:')) {
            $period = substr($data, strlen('lapout:'));

            if (! $this->isAdminChat($chatId)) {
                $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                return;
            }

            if ($this->tanganiNavigasiPeriode('lapout', $period, 'Pilih periode laporan profit outlet:', $chatId, $messageId, $telegram)) {
                return;
            }

            $this->showAreaPickerForLaporanPdf($chatId, $messageId, $period, $telegram, 'lapoutarea');

            return;
        }

        if (str_starts_with($data, 'lapoutarea:')) {
            // Format: lapoutarea:{period}:{areaId|all|each}
            [, $period, $areaIdOrAll] = explode(':', $data, 3);

            if (! $this->isAdminChat($chatId)) {
                $telegram->editMessageText($chatId, $messageId, 'Perintah ini khusus admin.');

                return;
            }

            // 'each' = tombol sekarang. 'all' = tombol versi sebelumnya yang
            // masih menempel di pesan lama di chat -- diperlakukan sama supaya
            // "Semua Wilayah" selalu berarti satu PDF per wilayah.
            if (in_array($areaIdOrAll, ['each', 'all'], true)) {
                $this->kirimLaporanOutletPerWilayah($chatId, $messageId, $period, $telegram);

                return;
            }

            $area = $areaIdOrAll === 'all' ? null : \App\Models\Area::find((int) $areaIdOrAll);

            if ($areaIdOrAll !== 'all' && ! $area) {
                $telegram->editMessageText($chatId, $messageId, 'Wilayah tidak ditemukan.');

                return;
            }

            $this->kirimLaporanPendapatanPdf($chatId, $messageId, $area, $period, $telegram, true);

            return;
        }

        if (str_starts_with($data, 'stokout:')) {
            // Format: stokout:{outletId|all} -- outlet SUDAH dipilih
            // (khusus admin), lanjut ke langkah berikutnya: pilih PAKET.
            $outletIdOrAll = substr($data, strlen('stokout:'));

            $outletTerpilih = $outletIdOrAll === 'all' ? null : Outlet::find((int) $outletIdOrAll);

            if ($outletIdOrAll !== 'all' && ! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->showStockPackagePicker($chatId, $messageId, $outletTerpilih, $telegram);

            return;
        }

        if (str_starts_with($data, 'stokpkg:')) {
            // Format: stokpkg:{outletId|all}:{pkgId|all}
            [$outletIdOrAll, $pkgIdOrAll] = explode(':', substr($data, strlen('stokpkg:')), 2);

            $outletTerpilih = $outletIdOrAll === 'all' ? null : Outlet::find((int) $outletIdOrAll);

            if ($outletIdOrAll !== 'all' && ! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $paketTerpilih = $pkgIdOrAll === 'all' ? null : ServicePackage::find((int) $pkgIdOrAll);

            if ($pkgIdOrAll !== 'all' && ! $paketTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            $this->showStockReportFinal($chatId, $messageId, $outletTerpilih, $paketTerpilih, $telegram);

            return;
        }

        if (str_starts_with($data, 'stokpdf:')) {
            // Format: stokpdf:{outletId}:{pkgId} -- tombol "Export PDF"
            // di showStockReportFinal(), cuma pernah dikirim kalau outlet
            // & paketnya SUDAH SPESIFIK keduanya (lihat komentar di sana
            // kenapa "Semua Outlet" tidak ditawari tombol ini).
            [$outletId, $pkgId] = explode(':', substr($data, strlen('stokpdf:')), 2);

            $outletTerpilih = Outlet::find((int) $outletId);

            if (! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $paketTerpilih = ServicePackage::find((int) $pkgId);

            if (! $paketTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            $this->kirimPdfStok($chatId, $messageId, $outletTerpilih, $paketTerpilih, $telegram);

            return;
        }

        if (str_starts_with($data, 'pppoeout:')) {
            // Khusus ADMIN (lihat showOutletPickerForPppoe()) -- outlet
            // dipilih, mulai alur tanya-jawab (Nama, Alamat, dst).
            $outletTerpilih = Outlet::find((int) substr($data, strlen('pppoeout:')));

            if (! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->startPppoeFlow($chatId, $outletTerpilih, $telegram, $messageId);

            return;
        }

        if (str_starts_with($data, 'pppoepkg:')) {
            // Paket dipilih di tengah alur /pppoe (lihat
            // showPppoePackageMenu()) -- lanjut ke langkah Username.
            $state = Cache::get($this->pppoeFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'paket') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah pelanggan PPPoE sudah tidak berlaku. Ketik /pppoe lagi.');

                return;
            }

            $paket = ServicePackage::find((int) substr($data, strlen('pppoepkg:')));

            if (! $paket) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            $state['data']['service_package_id'] = $paket->id;
            $state['step'] = 'username';
            Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));

            $telegram->editMessageText($chatId, $messageId, "Paket: <b>{$paket->name}</b>\n\nKetik <b>Username PPPoE</b> (harus unik, tanpa spasi):");

            return;
        }

        if (str_starts_with($data, 'saldoout:')) {
            $pilihan = substr($data, strlen('saldoout:'));

            if ($pilihan === 'all') {
                $this->showSaldoSemuaOutlet($chatId, $messageId, $telegram);

                return;
            }

            $outletTerpilih = Outlet::find((int) $pilihan);

            if (! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->showSaldoReport($chatId, $outletTerpilih, $telegram, $messageId);

            return;
        }

        if (str_starts_with($data, 'pppoebill:')) {
            // Langkah TERAKHIR alur /pppoe: pilih BILLER yang bertugas
            // menagih pelanggan ini (lihat showPppoeBillerMenu()).
            $state = Cache::get($this->pppoeFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'biller') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah pelanggan PPPoE sudah tidak berlaku. Ketik /pppoe lagi.');

                return;
            }

            $pilihan = substr($data, strlen('pppoebill:'));

            if ($pilihan !== 'none') {
                $biller = Outlet::where('partner_type', \App\Enums\PartnerType::Biller)
                    ->find((int) $pilihan);

                if (! $biller) {
                    $telegram->editMessageText($chatId, $messageId, 'Biller tidak ditemukan.');

                    return;
                }

                $state['data']['biller_id'] = $biller->id;
            }

            Cache::forget($this->pppoeFlowKey($chatId));
            $telegram->editMessageText($chatId, $messageId, '⏳ Membuat pelanggan...');
            $this->finalizePppoeCustomer($chatId, $state, $telegram);

            return;
        }

        // BARU: langkah pilih Area di tengah alur /addoutlet &
        // /addreseller (lihat showAreaMenuForMitra()) -- langkah
        // TERAKHIR sebelum data login (Username/Email/Password).
        if (str_starts_with($data, 'mitraarea:')) {
            $state = Cache::get($this->mitraFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'area') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi sudah tidak berlaku. Ketik /addoutlet atau /addreseller lagi.');

                return;
            }

            $pilihan = substr($data, strlen('mitraarea:'));

            if ($pilihan !== 'none') {
                $area = \App\Models\Area::find((int) $pilihan);

                if (! $area) {
                    $telegram->editMessageText($chatId, $messageId, 'Area tidak ditemukan.');

                    return;
                }

                $state['data']['area_id'] = $area->id;
            }

            $state['step'] = 'username';
            Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
            $telegram->editMessageText($chatId, $messageId, 'Ketik <b>Username Login</b> (harus unik, tanpa spasi):');

            return;
        }

        if (str_starts_with($data, 'sippkg:')) {
            // Paket dipilih di tengah alur /addstaticip (lihat
            // showStaticIpPackageMenu()) -- langkah TERAKHIR, langsung
            // buat pelanggannya.
            $state = Cache::get($this->staticIpFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'paket') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah pelanggan Static IP sudah tidak berlaku. Ketik /addstaticip lagi.');

                return;
            }

            $paket = ServicePackage::find((int) substr($data, strlen('sippkg:')));

            if (! $paket) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            $state['data']['service_package_id'] = $paket->id;
            // Bukan langsung dibuat: masih ada 1 langkah lagi, pilih
            // BILLER (opsional) -- SAMA seperti field "Biller (opsional)"
            // di StaticIpUserResource aplikasi. Nanti hasilnya DITULIS
            // LANGSUNG ke kolom `outlet_id` pelanggan (lihat 'sipbill:'
            // & finalizeStaticIpCustomer() di bawah) -- BUKAN lewat
            // pivot biller_customer_commissions seperti /pppoe &
            // /addmember, supaya PERSIS konsisten dengan cara aplikasi
            // menyimpannya (lihat catatan di idPelangganMilikBiller()).
            $state['step'] = 'biller';
            Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
            $telegram->editMessageText($chatId, $messageId, "Paket: <b>{$paket->name}</b>");
            $this->showBillerMenuUntuk($chatId, 'sipbill', $telegram);

            return;
        }

        if (str_starts_with($data, 'sipbill:')) {
            // Langkah TERAKHIR alur /addstaticip: pilih Biller (opsional)
            // -- lihat catatan di atas kenapa hasilnya masuk ke
            // `outlet_id`, bukan pivot biller_customer_commissions.
            $state = Cache::get($this->staticIpFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'biller') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah pelanggan Static IP sudah tidak berlaku. Ketik /addstaticip lagi.');

                return;
            }

            $pilihan = substr($data, strlen('sipbill:'));

            if ($pilihan !== 'none') {
                $biller = Outlet::where('partner_type', \App\Enums\PartnerType::Biller)->find((int) $pilihan);

                if (! $biller) {
                    $telegram->editMessageText($chatId, $messageId, 'Biller tidak ditemukan.');

                    return;
                }

                $state['data']['outlet_id'] = $biller->id;
            }

            Cache::forget($this->staticIpFlowKey($chatId));
            $telegram->editMessageText($chatId, $messageId, '⏳ Membuat pelanggan...');
            $this->finalizeStaticIpCustomer($chatId, $state, $telegram);

            return;
        }

        if (str_starts_with($data, 'membout:')) {
            // Khusus ADMIN (lihat showOutletPickerForMember()) -- outlet
            // dipilih, mulai alur /addmember.
            $outletTerpilih = Outlet::find((int) substr($data, strlen('membout:')));

            if (! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->startMemberFlow($chatId, $outletTerpilih, $telegram, $messageId);

            return;
        }

        if (str_starts_with($data, 'membpkg:')) {
            // Paket dipilih di tengah alur /addmember (lihat
            // showMemberPackageMenu()) -- lanjut ke langkah Username.
            $state = Cache::get($this->memberFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'paket') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah Member sudah tidak berlaku. Ketik /addmember lagi.');

                return;
            }

            $paket = ServicePackage::find((int) substr($data, strlen('membpkg:')));

            if (! $paket) {
                $telegram->editMessageText($chatId, $messageId, 'Paket tidak ditemukan.');

                return;
            }

            $state['data']['service_package_id'] = $paket->id;
            $state['step'] = 'username';
            Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));

            $telegram->editMessageText($chatId, $messageId, "Paket: <b>{$paket->name}</b>\n\nKetik <b>Username</b> login Member (harus unik, tanpa spasi):");

            return;
        }

        if (str_starts_with($data, 'membbill:')) {
            // Langkah TERAKHIR alur /addmember: pilih BILLER (lihat
            // showBillerMenuUntuk()) -- pola PERSIS sama dengan
            // 'pppoebill:' & 'sipbill:'.
            $state = Cache::get($this->memberFlowKey($chatId));

            if (! $state || ($state['step'] ?? null) !== 'biller') {
                $telegram->editMessageText($chatId, $messageId, 'Sesi tambah Member sudah tidak berlaku. Ketik /addmember lagi.');

                return;
            }

            $pilihan = substr($data, strlen('membbill:'));

            if ($pilihan !== 'none') {
                $biller = Outlet::where('partner_type', \App\Enums\PartnerType::Biller)->find((int) $pilihan);

                if (! $biller) {
                    $telegram->editMessageText($chatId, $messageId, 'Biller tidak ditemukan.');

                    return;
                }

                $state['data']['biller_id'] = $biller->id;
            }

            Cache::forget($this->memberFlowKey($chatId));
            $telegram->editMessageText($chatId, $messageId, '⏳ Membuat Member...');
            $this->finalizeMemberCustomer($chatId, $state, $telegram);

            return;
        }

        if (str_starts_with($data, 'daftarmember:')) {
            // Format: daftarmember:{outletId|all}:{page}
            [$outletIdOrAll, $halaman] = explode(':', substr($data, strlen('daftarmember:')), 2);

            $outletTerpilih = $outletIdOrAll === 'all' ? null : Outlet::find((int) $outletIdOrAll);

            if ($outletIdOrAll !== 'all' && ! $outletTerpilih) {
                $telegram->editMessageText($chatId, $messageId, 'Outlet tidak ditemukan.');

                return;
            }

            $this->showDaftarMember($chatId, $messageId, $outletTerpilih, (int) $halaman, $telegram);

            return;
        }

        if (str_starts_with($data, 'pengkat:')) {
            // Kategori pengeluaran dipilih (lihat showKategoriPengeluaran())
            // -- lanjut ke langkah ketik Judul.
            $outletPelapor = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outletPelapor) {
                $telegram->editMessageText($chatId, $messageId, 'Akun ini sudah tidak terhubung ke outlet manapun. Ketik /pengeluaran lagi setelah link ulang.');

                return;
            }

            $kategori = substr($data, strlen('pengkat:'));

            if (! \App\Enums\ExpenseCategory::tryFrom($kategori)) {
                $telegram->editMessageText($chatId, $messageId, 'Kategori tidak dikenali.');

                return;
            }

            Cache::put($this->pengeluaranFlowKey($chatId), [
                'outlet_id' => $outletPelapor->id,
                'step' => 'judul',
                'data' => ['category' => $kategori],
            ], now()->addMinutes(20));

            $telegram->editMessageText($chatId, $messageId,
                'Kategori: <b>'.\App\Enums\ExpenseCategory::from($kategori)->label().'</b>'.
                "\n\nKetik <b>Judul</b> pengeluaran (mis. \"Beli Kabel UTP 50m\"):");

            return;
        }
    }

    /**
     * Khusus ADMIN: tampilkan daftar outlet buat dipilih sebelum lihat
     * laporan pendapatan -- opsi pertama "🌐 Semua Outlet" (gabungan,
     * lihat showRevenueReport()), disusul tiap outlet satu per satu.
     * Kalau outlet-nya BANYAK sekali, dibatasi 200 tombol pertama saja
     * (urut nama) supaya pesannya tidak kepanjangan/gagal terkirim --
     * cukup buat kebutuhan wajar, admin dengan outlet lebih banyak dari
     * itu sebaiknya pakai laporan di aplikasi langsung.
     *
     * CATATAN: batas ini SEBELUMNYA 40 -- pernah bikin outlet yang
     * urutan abjadnya di luar 40 besar (mis. "SURYADI", outlet ke-41
     * dari 48 outlet) hilang sama sekali dari daftar pilihan, padahal
     * datanya normal. Dinaikkan ke 200 supaya ada ruang tumbuh jauh di
     * atas kebutuhan sekarang sebelum kepotong lagi.
     */
    /**
     * PERIODE LAPORAN (/pendapatan & /laporanpdf) -- permintaan user
     * 2026-09-29: selain "Hari Ini" & "Bulan Ini", bisa pilih bulan yang
     * SUDAH LEWAT lewat tombol "📅 Pilih Bulan...".
     *
     * Kode periode di callback_data:
     *   today   -> hari ini
     *   month   -> tanggal 1 bulan berjalan s/d hari ini
     *   mYYYYMM -> satu bulan penuh (mis. m202608 = Agustus 2026)
     *   pilih   -> tampilkan daftar bulan (navigasi, bukan laporan)
     *   menu    -> kembali ke menu periode (navigasi)
     */
    protected const BULAN_LAPORAN_KE_BELAKANG = 12;

    protected const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** @return array<int, array<int, array{text:string, callback_data:string}>> */
    protected function tombolPeriodeLaporan(string $awalan): array
    {
        return [
            [
                ['text' => 'Hari Ini', 'callback_data' => "{$awalan}:today"],
                ['text' => 'Bulan Ini', 'callback_data' => "{$awalan}:month"],
            ],
            [
                ['text' => '📅 Pilih Bulan…', 'callback_data' => "{$awalan}:pilih"],
            ],
        ];
    }

    /**
     * Daftar bulan yang SUDAH LEWAT (bulan lalu ke belakang), 3 per baris,
     * terbaru di atas, ditutup tombol kembali. Bulan berjalan tidak dimasukkan
     * karena sudah ada tombol "Bulan Ini".
     *
     * @return array<int, array<int, array{text:string, callback_data:string}>>
     */
    protected function tombolPilihBulan(string $awalan): array
    {
        $tombol = [];
        $bulan = now()->startOfMonth();

        for ($i = 1; $i <= self::BULAN_LAPORAN_KE_BELAKANG; $i++) {
            $b = $bulan->copy()->subMonthsNoOverflow($i);
            $tombol[] = [
                'text' => mb_substr(self::NAMA_BULAN[$b->month], 0, 3).' '.$b->year,
                'callback_data' => "{$awalan}:m".$b->format('Ym'),
            ];
        }

        $baris = array_chunk($tombol, 3);
        $baris[] = [['text' => '⬅️ Kembali', 'callback_data' => "{$awalan}:menu"]];

        return $baris;
    }

    /**
     * Tangani tombol navigasi periode ("pilih" / "menu"). Balik true kalau
     * sudah ditangani (pemanggil cukup return), false kalau $period adalah
     * periode laporan sungguhan yang harus dilanjutkan.
     */
    protected function tanganiNavigasiPeriode(string $awalan, string $period, string $judulMenu, string $chatId, int $messageId, TelegramService $telegram): bool
    {
        if ($period === 'pilih') {
            $telegram->editMessageText($chatId, $messageId, 'Pilih bulan laporan:', ['inline_keyboard' => $this->tombolPilihBulan($awalan)]);

            return true;
        }

        if ($period === 'menu') {
            $telegram->editMessageText($chatId, $messageId, $judulMenu, ['inline_keyboard' => $this->tombolPeriodeLaporan($awalan)]);

            return true;
        }

        if (! static::rentangPeriodeLaporan($period)) {
            $telegram->editMessageText($chatId, $messageId, 'Periode laporan tidak dikenali. Ulangi perintahnya.');

            return true;
        }

        return false;
    }

    /**
     * Kode periode -> [dari, sampai, label]. NULL kalau kode tidak sah
     * (bentuk salah, bulan di masa depan, atau lebih jauh dari batas daftar
     * pilihan -- callback_data bisa saja dikirim ulang/diutak-atik).
     *
     * @return array{0:\Illuminate\Support\Carbon, 1:\Illuminate\Support\Carbon, 2:string}|null
     */
    public static function rentangPeriodeLaporan(string $period): ?array
    {
        if ($period === 'today') {
            return [now()->startOfDay(), now()->endOfDay(), 'Hari Ini'];
        }

        if ($period === 'month') {
            return [now()->startOfMonth(), now()->endOfDay(), 'Bulan Ini'];
        }

        if (! preg_match('/^m(\d{4})(\d{2})$/', $period, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }

        $awal = \Illuminate\Support\Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        $bulanIni = now()->startOfMonth();

        if ($awal->greaterThanOrEqualTo($bulanIni) || $awal->lessThan($bulanIni->copy()->subMonthsNoOverflow(self::BULAN_LAPORAN_KE_BELAKANG))) {
            return null;
        }

        return [$awal, $awal->copy()->endOfMonth(), self::NAMA_BULAN[$awal->month].' '.$awal->year];
    }

    protected function showOutletPickerForRevenue(string $chatId, int $messageId, string $period, TelegramService $telegram): void
    {
        $outlets = Outlet::orderBy('name')->limit(200)->get(['id', 'name']);

        $buttons = [[
            ['text' => '🌐 Semua Outlet', 'callback_data' => "revout:{$period}:all"],
        ]];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "revout:{$period}:{$o->id}",
            ])->values()->all();
        }

        $telegram->editMessageText($chatId, $messageId, 'Pilih outlet (atau lihat gabungan semua):', ['inline_keyboard' => $buttons]);
    }

    /**
     * $outlet NULL artinya laporan GABUNGAN SEMUA OUTLET -- cuma boleh
     * kejadian kalau yang minta admin (lihat pemanggilnya di
     * handleCallback(), sudah dicek isAdminChat() di sana). Outlet
     * biasa SELALU dapat $outlet terisi (laporan punya sendiri saja).
     */
    protected function showRevenueReport(string $chatId, int $messageId, ?Outlet $outlet, string $period, TelegramService $telegram): void
    {
        $rentang = static::rentangPeriodeLaporan($period);

        if (! $rentang) {
            $telegram->editMessageText($chatId, $messageId, 'Periode laporan tidak dikenali. Ketik /pendapatan lagi.');

            return;
        }

        [$from, $to, $label] = $rentang;

        $rows = app(ReportService::class)->query(array_filter([
            'outlet_id' => $outlet?->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]))->get();

        $totalPendapatan = (float) $rows->sum('total_amount');
        $format = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');

        if ($outlet) {
            // Satu mitra. Komisi dihitung PER TRANSAKSI lewat
            // Outlet::hitungKomisi() -- rumus yang SAMA dengan laporan di
            // aplikasi & PDF (ReportService, Laba/Rugi), jadi angkanya tidak
            // bisa beda. PERBAIKAN (2026-10-01): dulu di sini cuma
            // commission_percent, sehingga mitra bertipe komisi "Selisih
            // Harga Jual - Harga Beli Voucher" (umumnya Reseller) selalu
            // tampil Komisi Rp0.
            //
            // "Bebas Saldo" (is_saldo_exempt) HANYA soal generate voucher
            // tanpa dipotong saldo -- bukan alasan tidak dapat komisi.
            $totalKomisi = (float) $rows->sum(fn ($t) => $outlet->hitungKomisi((float) $t->total_amount, $t->voucher));
            // Label persennya SENGAJA dibuang (permintaan user) -- cukup
            // "Komisi" saja.
            $labelKomisi = 'Komisi';
            // Label mengikuti jenis mitranya: Outlet / Reseller / Biller
            // (permintaan user: reseller jangan tertulis "Outlet").
            $jenisMitra = ($outlet->partner_type ?? \App\Enums\PartnerType::Outlet)->label();
            $labelEntitas = "{$jenisMitra}: {$outlet->name}\n\n";
        } else {
            // GABUNGAN semua mitra (admin) -- tiap transaksi bisa dari mitra
            // BEDA dengan aturan komisi BEDA-BEDA (persentase atau selisih
            // harga; atau tanpa mitra sama sekali / stok pusat = tidak ada
            // komisi), jadi dihitung PER TRANSAKSI lalu dijumlah.
            $totalKomisi = (float) $rows->sum(fn ($row) => $row->outlet
                ? $row->outlet->hitungKomisi((float) $row->total_amount, $row->voucher)
                : 0);
            $labelKomisi = 'Komisi (semua outlet)';
            $labelEntitas = "Cakupan: SEMUA OUTLET\n\n";
        }

        $profit = $totalPendapatan - $totalKomisi;

        // Outlet melihat laporannya sendiri: yang penting BAGI MEREKA adalah
        // komisi yang mereka terima -- "Profit" (pendapatan dikurangi
        // komisi) itu keuntungan pihak ISP, bukan miliknya, dan cuma
        // membingungkan. Admin (termasuk saat memilih SATU outlet) tetap
        // melihat "Profit" seperti sebelumnya.
        $tampilSebagaiOutlet = $outlet && ! $this->isAdminChat($chatId);

        $pesan = "📊 <b>Laporan {$label}</b>\n".
            $labelEntitas.
            "Transaksi: {$rows->count()}\n".
            "Pendapatan: {$format($totalPendapatan)}\n".
            ($tampilSebagaiOutlet ? "Komisi Anda" : $labelKomisi).": {$format($totalKomisi)}";

        if (! $tampilSebagaiOutlet) {
            $pesan .= "\nProfit: {$format($profit)}";
        }

        $telegram->editMessageText($chatId, $messageId, $pesan);
    }

    /**
     * Khusus ADMIN: tampilkan daftar outlet buat dipilih sebelum lihat
     * sisa stok voucher -- opsi pertama "🌐 Semua Outlet", disusul tiap
     * outlet satu per satu. Setelah outlet dipilih, langkah berikutnya
     * adalah pilih PAKET (lihat showStockPackagePicker()). Sama seperti
     * showOutletPickerForRevenue(), dibatasi 200 outlet pertama (urut
     * nama) supaya pesannya tidak kepanjangan.
     */
    protected function showOutletPickerForStock(string $chatId, TelegramService $telegram): void
    {
        $outlets = Outlet::orderBy('name')->limit(200)->get(['id', 'name']);

        $buttons = [[
            ['text' => '🌐 Semua Outlet', 'callback_data' => 'stokout:all'],
        ]];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "stokout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih outlet (atau lihat ringkasan semua), lalu pilih paketnya:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Daftar PAKET yang MASIH PUNYA stok belum terjual/belum aktif,
     * dibatasi cakupan outlet tertentu ($outletId) atau SEMUA outlet
     * ($outletId null) -- cuma paket yang beneran ada stoknya yang
     * ditampilkan jadi tombol, biar tidak nawarin paket yang stoknya
     * memang sudah 0.
     */
    protected function stockPackageOptions(?int $outletId): \Illuminate\Support\Collection
    {
        $query = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('status', [\App\Enums\VoucherStatus::BelumTerjual, \App\Enums\VoucherStatus::Terjual])
            ->whereNotNull('voucher_profile_id');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $profileIds = $query->distinct()->pluck('voucher_profile_id');

        return ServicePackage::whereIn('id', $profileIds)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Balasan LANGSUNG (bukan edit tombol) buat outlet biasa yang kirim
     * /stok -- cuma ada satu pilihan outlet (punya sendiri), jadi
     * langsung ditawari pilih PAKET, tidak perlu pilih outlet lagi.
     */
    protected function sendStockPackagePicker(string $chatId, Outlet $outlet, TelegramService $telegram): void
    {
        $options = $this->stockPackageOptions($outlet->id);

        if ($options->isEmpty()) {
            $telegram->sendMessage($chatId, "📦 <b>Sisa Stok Voucher</b>\nOutlet: {$outlet->name}\n\nTidak ada stok tersisa di outlet ini -- semua voucher sudah aktif/expired/habis. Waktunya generate voucher baru.");

            return;
        }

        $buttons = [[
            ['text' => '🌐 Semua Paket', 'callback_data' => "stokpkg:{$outlet->id}:all"],
        ]];

        foreach ($options->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($p) => [
                'text' => $p->name,
                'callback_data' => "stokpkg:{$outlet->id}:{$p->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih paket buat lihat sisa stoknya:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Dipanggil dari menu pilih outlet (handleCallback(), "stokout:") --
     * khusus ADMIN. $outlet NULL artinya cakupan SEMUA OUTLET sekaligus
     * (opsi "🌐 Semua Outlet"). Langkah SELANJUTNYA (permintaan user):
     * tawarkan pilih PAKET juga, bukan langsung tampilkan laporan.
     */
    protected function showStockPackagePicker(string $chatId, int $messageId, ?Outlet $outlet, TelegramService $telegram): void
    {
        $options = $this->stockPackageOptions($outlet?->id);
        $outletKey = $outlet?->id ?? 'all';
        $cakupan = $outlet ? "Outlet: {$outlet->name}" : 'Cakupan: SEMUA OUTLET';

        if ($options->isEmpty()) {
            $telegram->editMessageText($chatId, $messageId, "📦 <b>Sisa Stok Voucher</b>\n{$cakupan}\n\nTidak ada stok tersisa sama sekali.");

            return;
        }

        $buttons = [[
            ['text' => '🌐 Semua Paket', 'callback_data' => "stokpkg:{$outletKey}:all"],
        ]];

        foreach ($options->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($p) => [
                'text' => $p->name,
                'callback_data' => "stokpkg:{$outletKey}:{$p->id}",
            ])->values()->all();
        }

        $telegram->editMessageText($chatId, $messageId, "{$cakupan}\n\nPilih paket buat lihat sisa stoknya:", ['inline_keyboard' => $buttons]);
    }

    /**
     * Laporan AKHIR sisa stok, setelah outlet & paket dipilih (atau opsi
     * "Semua"-nya). Kombinasi yang mungkin:
     *  - outlet TERTENTU + "Semua Paket": rincian per paket outlet itu.
     *  - outlet TERTENTU + paket TERTENTU: satu angka spesifik.
     *  - "Semua Outlet" + "Semua Paket": ringkasan per outlet (admin).
     *  - "Semua Outlet" + paket TERTENTU: sebaran satu paket itu per
     *    outlet (admin) -- buat lihat outlet mana yang stok paket itu
     *    menipis.
     * "Belum Terjual" (stok mentah) & "Terjual (belum aktif)" (sudah
     * dijual/dicetak tapi pelanggan belum pernah pakai) dihitung
     * terpisah supaya jelas, tapi yang relevan buat "jangan kebanyakan
     * cetak" adalah TOTAL keduanya -- itu semua voucher yang masih ada/
     * belum habis dipakai.
     */
    protected function showStockReportFinal(string $chatId, int $messageId, ?Outlet $outlet, ?ServicePackage $paket, TelegramService $telegram): void
    {
        $cakupan = $outlet ? "Outlet: {$outlet->name}" : 'Cakupan: SEMUA OUTLET';

        $query = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('status', [\App\Enums\VoucherStatus::BelumTerjual, \App\Enums\VoucherStatus::Terjual]);

        if ($outlet) {
            $query->where('outlet_id', $outlet->id);
        }

        if ($paket) {
            $query->where('voucher_profile_id', $paket->id);
        }

        $rows = $query->with(['profile', 'outlet:id,name'])->get(['id', 'username', 'outlet_id', 'voucher_profile_id', 'status']);

        $labelPaket = $paket ? "\nPaket: {$paket->name}" : '';
        $teks = "📦 <b>Sisa Stok Voucher</b>\n{$cakupan}{$labelPaket}\n\n";

        if ($rows->isEmpty()) {
            $teks .= $paket ? 'Tidak ada stok tersisa untuk paket ini.' : 'Tidak ada stok tersisa sama sekali.';
            $telegram->editMessageText($chatId, $messageId, $teks);

            return;
        }

        // BARU (permintaan user): tombol "Export PDF" pakai format kartu
        // yang SAMA seperti hasil generate voucher baru (lihat
        // kirimPdfBatch()) -- cuma ditawarkan kalau OUTLET-nya JUGA
        // spesifik (bukan "Semua Outlet"), karena nama outlet di kartu
        // PDF itu SATU label buat SELURUH lembar (lihat voucher-cards-
        // pdf.blade.php, variabel $outletName dicetak sama di semua
        // kartu) -- kalau vouchernya kecampur dari outlet lain, nama
        // outlet yang tercetak di kartu itu jadi salah buat sebagian.
        $keyboard = null;

        if ($paket) {
            // Satu paket spesifik -- kalau sekalian dibatasi satu outlet
            // juga, tampilkan angka tunggal. Kalau cakupannya "Semua
            // Outlet", sebar per outlet (biar kelihatan outlet mana yang
            // menipis khusus paket ini).
            if ($outlet) {
                $belumTerjual = $rows->where('status', \App\Enums\VoucherStatus::BelumTerjual)->count();
                $terjual = $rows->where('status', \App\Enums\VoucherStatus::Terjual)->count();

                $teks .= "Belum Terjual: {$belumTerjual}\n".
                    "Terjual (belum aktif): {$terjual}\n".
                    'Total sisa: '.($belumTerjual + $terjual)."\n".
                    $this->formatVoucherCodes($rows);

                $keyboard = ['inline_keyboard' => [[
                    ['text' => '📄 Export PDF (format kartu voucher)', 'callback_data' => "stokpdf:{$outlet->id}:{$paket->id}"],
                ]]];
            } else {
                $totalKeseluruhan = 0;
                $grupOutlet = $rows->groupBy(fn ($v) => $v->outlet_id ?? 0)->sortByDesc(fn ($g) => $g->count());

                // Kode voucher SENGAJA cuma dilampirkan buat 15 outlet
                // pertama (yang stoknya paling banyak) -- kalau outletnya
                // ada puluhan, melampirkan kode SEMUANYA sekaligus bisa
                // bikin pesan kepanjangan/gagal terkirim (batas Telegram
                // 4096 karakter). Outlet ke-16 dst tetap ikut dihitung di
                // TOTAL, cuma kode vouchernya tidak ditampilkan di sini
                // -- buka /stok lagi & pilih outlet itu langsung kalau
                // butuh kodenya.
                $batasOutletDenganKode = 15;
                $indeks = 0;

                foreach ($grupOutlet as $group) {
                    $nama = $group->first()->outlet?->name ?? 'Stok Pusat';
                    $belumTerjual = $group->where('status', \App\Enums\VoucherStatus::BelumTerjual)->count();
                    $terjual = $group->where('status', \App\Enums\VoucherStatus::Terjual)->count();
                    $total = $belumTerjual + $terjual;
                    $totalKeseluruhan += $total;

                    $teks .= "<b>{$nama}</b>: {$total} sisa (Belum Terjual {$belumTerjual}, Terjual belum aktif {$terjual})\n";

                    if ($indeks < $batasOutletDenganKode) {
                        $teks .= $this->formatVoucherCodes($group, 15);
                    }

                    $indeks++;
                }

                if ($grupOutlet->count() > $batasOutletDenganKode) {
                    $teks .= "\n(kode voucher cuma dilampirkan buat {$batasOutletDenganKode} outlet pertama di atas)\n";
                }

                $teks .= "\n———\nTOTAL SISA SEMUA OUTLET (paket ini): {$totalKeseluruhan}";
            }
        } else {
            // "Semua Paket" -- kalau outletnya juga tertentu, rincian
            // per paket outlet itu. Kalau cakupannya "Semua Outlet"
            // JUGA, ringkas per outlet saja (tanpa pecah per paket lagi,
            // supaya pesannya tidak kepanjangan/gagal terkirim).
            if ($outlet) {
                $totalBelumTerjual = 0;
                $totalTerjual = 0;
                $grupPaket = $rows->groupBy(fn ($v) => $v->voucher_profile_id ?? 0)->sortByDesc(fn ($g) => $g->count());

                // Sama seperti pembatasan di cabang "Semua Outlet" di
                // atas -- kode voucher cuma dilampirkan buat 10 paket
                // pertama (yang stoknya paling banyak), biar pesannya
                // tidak kepanjangan kalau paketnya banyak jenis.
                $batasPaketDenganKode = 10;
                $indeks = 0;

                foreach ($grupPaket as $group) {
                    $nama = $group->first()->profile?->name ?? '(paket tidak diketahui)';
                    $belumTerjual = $group->where('status', \App\Enums\VoucherStatus::BelumTerjual)->count();
                    $terjual = $group->where('status', \App\Enums\VoucherStatus::Terjual)->count();
                    $totalBelumTerjual += $belumTerjual;
                    $totalTerjual += $terjual;

                    $teks .= "<b>{$nama}</b>\n".
                        "  Belum Terjual: {$belumTerjual}\n".
                        "  Terjual (belum aktif): {$terjual}\n".
                        '  Total sisa: '.($belumTerjual + $terjual)."\n";

                    if ($indeks < $batasPaketDenganKode) {
                        $teks .= $this->formatVoucherCodes($group, 10);
                    }

                    $teks .= "\n";
                    $indeks++;
                }

                if ($grupPaket->count() > $batasPaketDenganKode) {
                    $teks .= "(kode voucher cuma dilampirkan buat {$batasPaketDenganKode} paket pertama di atas -- pilih paketnya langsung buat lihat kode paket lain)\n\n";
                }

                $teks .= "———\n".
                    "Total Belum Terjual: {$totalBelumTerjual}\n".
                    "Total Terjual (belum aktif): {$totalTerjual}\n".
                    'TOTAL SISA SEMUA PAKET: '.($totalBelumTerjual + $totalTerjual);
            } else {
                $totalKeseluruhan = 0;

                foreach ($rows->groupBy(fn ($v) => $v->outlet_id ?? 0)->sortByDesc(fn ($g) => $g->count()) as $group) {
                    $nama = $group->first()->outlet?->name ?? 'Stok Pusat';
                    $belumTerjual = $group->where('status', \App\Enums\VoucherStatus::BelumTerjual)->count();
                    $terjual = $group->where('status', \App\Enums\VoucherStatus::Terjual)->count();
                    $total = $belumTerjual + $terjual;
                    $totalKeseluruhan += $total;

                    $teks .= "<b>{$nama}</b>: {$total} sisa (Belum Terjual {$belumTerjual}, Terjual belum aktif {$terjual})\n";
                }

                $teks .= "\n———\nTOTAL SISA SEMUA OUTLET: {$totalKeseluruhan}";
            }
        }

        $telegram->editMessageText($chatId, $messageId, $teks, $keyboard);
    }

    /**
     * BARU (permintaan user): lampirkan KODE voucher-nya sendiri di
     * bawah laporan stok, bukan cuma jumlahnya -- supaya bisa langsung
     * dipakai/dikasih ke pelanggan tanpa perlu buka aplikasi lagi.
     * Dipisah per status (Belum Terjual dulu, baru Terjual belum aktif)
     * supaya jelas mana yang benar-benar stok mentah vs yang sudah
     * sempat dijual. Dibatasi $limit kode per status (default 50) --
     * kalau lebih, sisanya cuma disebutkan jumlahnya, tidak didaftar
     * satu-satu (jaga-jaga stoknya ribuan, pesan Telegram ada batas
     * panjangnya).
     */
    protected function formatVoucherCodes(\Illuminate\Support\Collection $rows, int $limit = 50): string
    {
        $belumTerjual = $rows->where('status', \App\Enums\VoucherStatus::BelumTerjual);
        $terjual = $rows->where('status', \App\Enums\VoucherStatus::Terjual);

        $teks = '';

        if ($belumTerjual->isNotEmpty()) {
            $teks .= "<i>Kode Belum Terjual:</i>\n";
            $teks .= $belumTerjual->take($limit)->map(fn ($v) => "<code>{$v->username}</code>")->implode("\n");

            if ($belumTerjual->count() > $limit) {
                $teks .= "\n… dan ".($belumTerjual->count() - $limit).' lainnya';
            }

            $teks .= "\n";
        }

        if ($terjual->isNotEmpty()) {
            $teks .= "<i>Kode Terjual (belum aktif):</i>\n";
            $teks .= $terjual->take($limit)->map(fn ($v) => "<code>{$v->username}</code>")->implode("\n");

            if ($terjual->count() > $limit) {
                $teks .= "\n… dan ".($terjual->count() - $limit).' lainnya';
            }

            $teks .= "\n";
        }

        return $teks;
    }

    /**
     * Kunci Cache buat nyimpan STATE alur /pppoe satu chat -- dibubuhi
     * subdomain tenant (atau "central" buat instalasi utama) SENGAJA
     * SUPAYA AMAN walau nanti config cache-nya berubah tidak lagi ikut
     * koneksi 'mysql' yang dialihkan per tenant -- Chat ID yang SAMA
     * TIDAK MUNGKIN kejadian di 2 tenant beda sekaligus (satu akun
     * Telegram cuma link ke SATU outlet/tenant), jadi ini murni lapisan
     * jaga-jaga tambahan, bukan solusi utamanya.
     */
    protected function pppoeFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_pppoe_flow:{$namespace}:{$chatId}";
    }

    protected function mitraFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_mitra_flow:{$namespace}:{$chatId}";
    }

    /**
     * Alur tambah Outlet/Reseller lewat bot -- SENGAJA pakai SATU state
     * machine yang sama buat dua-duanya (dibedakan lewat
     * $state['partner_type']), karena field & langkahnya PERSIS sama
     * di aplikasi web (lihat OutletResource.php/ResellerResource.php --
     * satu-satunya beda cuma nilai kolom `partner_type`). Field
     * OPSIONAL/administratif yang ada di wizard web (Profile Hotspot
     * pembatasan, Komisi & Catatan, Saldo awal Reseller) SENGAJA
     * DILEWATI di sini, sama seperti /pppoe melewati field-field web-
     * only serupa -- itu semua bisa diatur admin belakangan lewat
     * aplikasi, tidak menghalangi Outlet/Reseller-nya dibuat duluan
     * lewat bot dengan cepat.
     *
     * Langkah: Nama -> Nama Pemilik -> WhatsApp -> (tombol) Area ->
     * Username -> Email -> Password -> selesai (finalizeMitra()).
     */
    protected function startMitraFlow(string $chatId, string $tipe, TelegramService $telegram): void
    {
        Cache::put($this->mitraFlowKey($chatId), [
            'partner_type' => $tipe,
            'step' => 'nama',
            'data' => [],
        ], now()->addMinutes(20));

        $label = $tipe === 'outlet' ? 'Outlet' : 'Reseller';
        $telegram->sendMessage($chatId, "🏢 <b>Tambah {$label}</b>\n\nKetik <b>Nama {$label}</b> (kapan saja ketik /batal buat membatalkan):");
    }

    protected function showAreaMenuForMitra(string $chatId, TelegramService $telegram): void
    {
        $areas = \App\Models\Area::orderBy('name')->limit(40)->get(['id', 'name']);

        $buttons = [];

        foreach ($areas->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($a) => [
                'text' => $a->name,
                'callback_data' => "mitraarea:{$a->id}",
            ])->values()->all();
        }

        // Tombol "Tanpa Area" SELALU ada, sama pola dengan "Tanpa
        // Biller" di showBillerMenuUntuk() -- alurnya tidak pernah
        // buntu walau belum ada Area terdaftar/dipilih admin.
        $buttons[] = [[
            'text' => '⏭️ Tanpa Area',
            'callback_data' => 'mitraarea:none',
        ]];

        $telegram->sendMessage($chatId, 'Pilih <b>Area/Wilayah</b>:', ['inline_keyboard' => $buttons]);
    }

    protected function handleMitraFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $step = $state['step'] ?? null;
        $tipe = $state['partner_type'] ?? 'outlet';
        $label = $tipe === 'outlet' ? 'Outlet' : 'Reseller';

        switch ($step) {
            case 'nama':
                if ($text === '') {
                    $telegram->sendMessage($chatId, "Nama tidak boleh kosong. Ketik Nama {$label}:");

                    return;
                }

                $state['data']['name'] = $text;
                $state['step'] = 'pemilik';
                Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Nama Pemilik:');

                return;

            case 'pemilik':
                if ($text === '') {
                    $telegram->sendMessage($chatId, 'Nama Pemilik tidak boleh kosong. Ketik lagi:');

                    return;
                }

                $state['data']['owner_name'] = $text;
                $state['step'] = 'whatsapp';
                Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik nomor WhatsApp (boleh kirim "-" kalau tidak ada):');

                return;

            case 'whatsapp':
                $state['data']['phone'] = $text === '-' ? null : $text;
                $state['step'] = 'area';
                Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showAreaMenuForMitra($chatId, $telegram);

                return;

            case 'area':
                // Seharusnya dipilih lewat TOMBOL (callback "mitraarea:"),
                // sama seperti langkah 'paket' di alur /pppoe.
                $telegram->sendMessage($chatId, 'Pilih Area-nya lewat tombol di atas ya, bukan diketik manual.');

                return;

            case 'username':
                $username = trim(str_replace(' ', '', $text));

                if ($username === '') {
                    $telegram->sendMessage($chatId, 'Username tidak boleh kosong. Ketik Username Login:');

                    return;
                }

                // Kolom username di tabel users UNIK -- dicek dulu di
                // sini sama seperti /pppoe cek username Customer,
                // supaya pesan errornya ramah, bukan error SQL mentah.
                $sudahAda = \App\Models\User::where('username', $username)->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "Username <code>{$username}</code> sudah dipakai user lain. Ketik username lain:");

                    return;
                }

                $state['data']['login_username'] = $username;
                $state['step'] = 'email';
                Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Email Login:');

                return;

            case 'email':
                $email = trim($text);

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $telegram->sendMessage($chatId, 'Email tidak valid (contoh: nama@email.com). Ketik lagi:');

                    return;
                }

                $sudahAda = \App\Models\User::where('email', $email)->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "Email <code>{$email}</code> sudah dipakai user lain. Ketik email lain:");

                    return;
                }

                $state['data']['login_email'] = $email;
                $state['step'] = 'password';
                Cache::put($this->mitraFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Password Login (minimal 4 karakter):');

                return;

            case 'password':
                $password = trim($text);

                if (mb_strlen($password) < 4) {
                    $telegram->sendMessage($chatId, 'Password minimal 4 karakter. Ketik lagi:');

                    return;
                }

                $state['data']['login_password'] = $password;
                Cache::forget($this->mitraFlowKey($chatId));
                $telegram->sendMessage($chatId, "⏳ Membuat {$label}...");
                $this->finalizeMitra($chatId, $state, $telegram);

                return;

            default:
                Cache::forget($this->mitraFlowKey($chatId));
                $telegram->sendMessage($chatId, "Sesi tambah {$label} sudah tidak berlaku. Ketik /add{$tipe} lagi.");
        }
    }

    /**
     * Langkah TERAKHIR alur /addoutlet & /addreseller -- semua field
     * lengkap, bikin Outlet + User login-nya. SENGAJA REUSE pola aman
     * yang baru ditambahkan di OutletResource.php/ResellerResource.php
     * versi aplikasi web (lihat catatan di sana, 2026-09-18): dicek
     * ulang duplikat SEKALI LAGI persis sebelum membuat (jaga-jaga ada
     * yang daftar duluan di antara langkah Username/Email tadi dan
     * sekarang), dan Outlet::create()+User::create() dibungkus
     * DB::transaction() supaya TIDAK ADA User "yatim piatu" (tanpa
     * Outlet) kalau salah satu dari dua operasi gagal di tengah jalan
     * -- persis masalah yang sempat bikin tombol Simpan di web
     * "kelihatan tidak bergerak" tanpa keterangan, JANGAN diulangi di
     * jalur bot ini.
     */
    protected function finalizeMitra(string $chatId, array $state, TelegramService $telegram): void
    {
        $tipe = $state['partner_type'] ?? 'outlet';
        $label = $tipe === 'outlet' ? 'Outlet' : 'Reseller';
        $data = $state['data'];

        $duplicate = \App\Models\User::where('email', $data['login_email'])
            ->orWhere('username', $data['login_username'])
            ->exists();

        if ($duplicate) {
            $telegram->sendMessage($chatId, "❌ Username atau email itu sudah dipakai user lain (baru saja dipakai barusan). Ketik /add{$tipe} buat coba lagi.");

            return;
        }

        try {
            $mitra = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tipe) {
                $outlet = Outlet::create([
                    'name' => $data['name'],
                    'owner_name' => $data['owner_name'],
                    'phone' => $data['phone'] ?? null,
                    'area_id' => $data['area_id'] ?? null,
                    'is_active' => true,
                    'partner_type' => $tipe === 'outlet'
                        ? \App\Enums\PartnerType::Outlet->value
                        : \App\Enums\PartnerType::Reseller->value,
                ]);

                \App\Models\User::create([
                    'name' => $outlet->owner_name,
                    'username' => $data['login_username'],
                    'email' => $data['login_email'],
                    'password' => $data['login_password'],
                    'role' => \App\Enums\RoleType::Outlet,
                    'outlet_id' => $outlet->id,
                    'is_active' => true,
                ]);

                return $outlet;
            });
        } catch (\Throwable $e) {
            Log::warning("Gagal membuat {$label} lewat bot Telegram", [
                'name' => $data['name'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, "❌ Gagal membuat {$label} (kemungkinan username/email sudah dipakai lagi barusan). Ketik /add{$tipe} buat coba lagi.");

            return;
        }

        $telegram->sendMessage($chatId, "✅ {$label} <b>{$mitra->name}</b> berhasil dibuat.\n\nLogin: <code>{$data['login_username']}</code>");
    }

    /**
     * Khusus ADMIN: tampilkan daftar outlet/biller buat dipilih SEBELUM
     * mulai alur tambah pelanggan PPPoE -- TIDAK ada opsi "Semua Outlet"
     * di sini (beda dari /pendapatan & /stok) karena satu pelanggan
     * WAJIB terikat ke SATU outlet/biller tertentu, tidak masuk akal
     * "gabungan semua outlet".
     */
    /**
     * Khusus ADMIN: daftar Reseller untuk dipilih sebelum melihat saldo,
     * plus opsi "Semua Reseller" yang menampilkan ringkasan sekaligus.
     *
     * HANYA Reseller yang ditampilkan -- Outlet & Biller tidak lagi
     * pakai sistem saldo sama sekali (kebijakan: yang wajib saldo cuma
     * Reseller), jadi tidak ada gunanya dimunculkan di sini.
     */
    protected function showOutletPickerForSaldo(string $chatId, TelegramService $telegram): void
    {
        $outlets = Outlet::where('partner_type', \App\Enums\PartnerType::Reseller)
            ->orderBy('name')->limit(40)->get(['id', 'name']);

        if ($outlets->isEmpty()) {
            $telegram->sendMessage($chatId, 'Belum ada reseller terdaftar.');

            return;
        }

        $buttons = [[[
            'text' => '📊 Ringkasan Semua Reseller',
            'callback_data' => 'saldoout:all',
        ]]];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "saldoout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih outlet untuk lihat saldo:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Laporan saldo SATU outlet: saldo sekarang, permintaan top up yang
     * masih menunggu, dan 5 mutasi terakhir.
     *
     * $messageId diisi kalau dipanggil dari tombol (biar pesan tombolnya
     * yang diganti isinya, bukan kirim pesan baru).
     */
    protected function showSaldoReport(string $chatId, Outlet $outlet, TelegramService $telegram, ?int $messageId = null): void
    {
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        $teks = "💰 <b>Saldo {$outlet->name}</b>\n\n";
        $teks .= "Saldo sekarang: <b>{$rp($outlet->saldo)}</b>\n";

        // Mitra yang tidak wajib saldo (semua Outlet otomatis, atau
        // Reseller yang ditandai manual "Bebas Saldo") tidak dipotong
        // saat generate voucher -- perlu ditegaskan di sini supaya
        // tidak bingung melihat saldo Rp0 tapi tetap bisa generate.
        if (! $outlet->requiresSaldo()) {
            $teks .= "Status: <b>Bebas Saldo</b> (generate voucher tidak memotong saldo)\n";
        }

        // Permintaan top up yang masih menunggu persetujuan admin.
        $pending = \App\Models\SaldoTopupRequest::where('outlet_id', $outlet->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        if ($pending->isNotEmpty()) {
            $totalPending = (float) $pending->sum('amount');
            $teks .= "\n⏳ <b>Menunggu persetujuan admin:</b> {$pending->count()} permintaan ({$rp($totalPending)})\n";

            foreach ($pending->take(5) as $r) {
                $tgl = $r->created_at?->format('d/m H:i') ?? '-';
                $teks .= "   • {$rp($r->amount)} — diminta {$tgl}\n";
            }
        }

        // 5 mutasi terakhir: sumber kebenaran riwayat saldo, dicatat
        // SaldoService setiap kali saldo bertambah/berkurang.
        $mutasi = \App\Models\SaldoMutation::where('outlet_id', $outlet->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $teks .= "\n📜 <b>5 Mutasi Terakhir</b>\n";

        if ($mutasi->isEmpty()) {
            $teks .= "   Belum ada mutasi saldo.\n";
        } else {
            foreach ($mutasi as $m) {
                $tgl = $m->created_at?->format('d/m H:i') ?? '-';
                // type "topup" = saldo masuk, selain itu dianggap keluar.
                $tanda = $m->type === 'topup' ? '➕' : '➖';
                $ket = $m->description ? ' — '.$m->description : '';
                $teks .= "   {$tanda} {$rp($m->amount)} ({$tgl}){$ket}\n";
            }
        }

        $teks .= "\nMau menambah saldo? Kirim: <code>/topup 100000</code>";

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teks);
        } else {
            $telegram->sendMessage($chatId, $teks);
        }
    }

    /**
     * Khusus ADMIN: ringkasan saldo SEMUA Reseller dalam satu pesan,
     * diurutkan dari saldo terkecil supaya yang perlu ditagih/diisi
     * langsung kelihatan paling atas.
     *
     * HANYA Reseller -- Outlet & Biller tidak lagi pakai sistem saldo.
     */
    protected function showSaldoSemuaOutlet(string $chatId, int $messageId, TelegramService $telegram): void
    {
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        $outlets = Outlet::where('partner_type', \App\Enums\PartnerType::Reseller)
            ->orderBy('saldo')->get(['id', 'name', 'saldo', 'partner_type', 'is_saldo_exempt']);

        if ($outlets->isEmpty()) {
            $telegram->editMessageText($chatId, $messageId, 'Belum ada reseller terdaftar.');

            return;
        }

        $teks = "💰 <b>Ringkasan Saldo Semua Reseller</b>\n";
        $teks .= "<i>Diurutkan dari saldo paling sedikit.</i>\n\n";

        foreach ($outlets->take(30) as $o) {
            $tanda = ! $o->requiresSaldo() ? ' (bebas saldo)' : '';
            $teks .= "• {$o->name}: <b>{$rp($o->saldo)}</b>{$tanda}\n";
        }

        if ($outlets->count() > 30) {
            $teks .= "\n<i>...dan ".($outlets->count() - 30)." outlet lain (dibatasi 30 supaya pesan tidak kepanjangan).</i>\n";
        }

        $totalSaldo = (float) $outlets->sum('saldo');
        $teks .= "\nTotal saldo seluruh outlet: <b>{$rp($totalSaldo)}</b>";

        // Permintaan top up yang masih menggantung -- paling berguna
        // buat admin, karena ini yang menunggu tindakannya.
        $pending = \App\Models\SaldoTopupRequest::where('status', 'pending')->get();

        if ($pending->isNotEmpty()) {
            $teks .= "\n⏳ Menunggu persetujuan: <b>{$pending->count()}</b> permintaan ({$rp($pending->sum('amount'))})";
        }

        $telegram->editMessageText($chatId, $messageId, $teks);
    }

    /**
     * Laporan pelanggan PPPoE yang SEDANG OFFLINE.
     *
     * $outlet null = gabungan semua outlet (cuma dipakai admin). Dalam
     * mode gabungan, nama outlet ditulis di tiap baris supaya tetap
     * ketahuan siapa yang harus menangani -- perintah ini sengaja tidak
     * menanyakan outlet dulu (lihat /offline di handleMessage()).
     *
     * $messageId diisi kalau dipanggil dari tombol.
     *
     * DEFINISI "offline" di sini: tidak punya sesi berjalan di radacct
     * (baris tanpa acctstoptime) -- patokan yang SAMA PERSIS dengan menu
     * "PPPoE > Online" di aplikasi dan kartu "Sesi Online", supaya angka
     * di bot tidak pernah berselisih dengan angka di aplikasi.
     *
     * PENTING soal pemetaan username: tabel radacct dipakai BERSAMA semua
     * tenant, dan username di sana memakai awalan tenant. Jadi username
     * dari tabel customers WAJIB dilewatkan RadiusUsername::forRadius()
     * dulu sebelum dicocokkan -- kalau tidak, untuk tenant ber-subdomain
     * hasilnya SELALU "semua offline" karena tidak ada yang cocok.
     *
     * Pelanggan Suspend/Isolir SENGAJA dipisah dari daftar utama: mereka
     * offline karena memang diputus, itu bukan gangguan dan tidak perlu
     * diperiksa teknisi. Yang benar-benar perlu perhatian adalah yang
     * statusnya Aktif tapi tidak terhubung.
     */
    protected function showPppoeOfflineReport(string $chatId, ?Outlet $outlet, TelegramService $telegram, ?int $messageId = null): void
    {
        $kirim = function (string $teks) use ($chatId, $telegram, $messageId) {
            if ($messageId) {
                $telegram->editMessageText($chatId, $messageId, $teks);
            } else {
                $telegram->sendMessage($chatId, $teks);
            }
        };

        try {
            $query = Customer::query()
                ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->where('type', CustomerType::Pppoe);

            if ($outlet) {
                $query->where('outlet_id', $outlet->id);
            }

            $pelanggan = $query->orderBy('name')->get(['id', 'name', 'username', 'status', 'outlet_id']);
        } catch (\Throwable $e) {
            Log::error('Gagal ambil daftar pelanggan PPPoE untuk /offline: '.$e->getMessage());
            $kirim('Gagal membaca data pelanggan. Coba lagi sebentar lagi.');

            return;
        }

        $judul = $outlet ? "Offline — {$outlet->name}" : 'Offline — Semua Outlet';

        // Mode gabungan: nama outlet ditulis di tiap baris. Diambil
        // sekali sebagai peta id => nama (satu query), bukan lewat relasi
        // per pelanggan -- daftar 50 orang kalau dibaca satu-satu jadi 50
        // query dan webhook Telegram gampang kehabisan waktu.
        $namaOutlet = [];

        if (! $outlet) {
            $namaOutlet = Outlet::whereIn('id', $pelanggan->pluck('outlet_id')->filter()->unique())
                ->pluck('name', 'id')
                ->all();
        }

        if ($pelanggan->isEmpty()) {
            $kirim("📡 <b>{$judul}</b>\n\nBelum ada pelanggan PPPoE terdaftar.");

            return;
        }

        // Username RADIUS (sudah berawalan tenant) -> username asli, biar
        // hasil query radacct gampang dicocokkan balik ke pelanggannya.
        $petaUsername = [];

        foreach ($pelanggan as $p) {
            if ($p->username) {
                $petaUsername[\App\Support\RadiusUsername::forRadius($p->username)] = $p->username;
            }
        }

        try {
            $sedangOnline = empty($petaUsername) ? collect() : \App\Models\Radacct::query()
                ->whereNull('acctstoptime')
                ->whereIn('username', array_keys($petaUsername))
                ->pluck('username')
                ->map(fn ($u) => $petaUsername[$u] ?? $u)
                ->unique();
        } catch (\Throwable $e) {
            Log::error('Gagal baca radacct untuk /offline: '.$e->getMessage());
            $kirim('Gagal membaca data sesi RADIUS. Coba lagi sebentar lagi.');

            return;
        }

        $offline = $pelanggan->filter(fn ($p) => $p->username && ! $sedangOnline->contains($p->username));

        $offlineAktif = $offline->filter(fn ($p) => $p->status === CustomerStatus::Active)->values();
        $offlineDiputus = $offline->filter(fn ($p) => $p->status !== CustomerStatus::Active)->values();

        $teks = "📡 <b>{$judul}</b>\n\n";
        $teks .= "Total pelanggan PPPoE: <b>{$pelanggan->count()}</b>\n";
        $teks .= "🟢 Online: <b>{$sedangOnline->count()}</b>\n";
        $teks .= "🔴 Offline: <b>{$offline->count()}</b>\n";

        if ($offlineAktif->isEmpty()) {
            $teks .= "\n✅ Semua pelanggan berstatus Aktif sedang terhubung.\n";
        } else {
            $teks .= "\n🔴 <b>Aktif tapi tidak terhubung ({$offlineAktif->count()})</b>\n";

            // Kapan terakhir terlihat online -- diambil sekali untuk
            // SELURUH daftar (satu query), bukan per pelanggan di dalam
            // perulangan. Kalau per pelanggan, daftar 50 orang jadi 50
            // query dan webhook Telegram gampang kehabisan waktu.
            $terakhir = [];

            try {
                $usernameRadius = $offlineAktif->take(30)
                    ->map(fn ($p) => \App\Support\RadiusUsername::forRadius($p->username))
                    ->all();

                if (! empty($usernameRadius)) {
                    $terakhir = \App\Models\Radacct::query()
                        ->whereIn('username', $usernameRadius)
                        ->selectRaw('username, MAX(acctstoptime) as terakhir')
                        ->groupBy('username')
                        ->pluck('terakhir', 'username')
                        ->all();
                }
            } catch (\Throwable $e) {
                // Waktu terakhir online cuma pelengkap -- kalau gagal
                // dibaca, daftar nama TETAP dikirim tanpa keterangan itu.
                $terakhir = [];
            }

            foreach ($offlineAktif->take(30) as $p) {
                $kunci = \App\Support\RadiusUsername::forRadius($p->username);
                $waktu = $terakhir[$kunci] ?? null;

                if ($waktu) {
                    $ket = ' — terakhir online '.\Illuminate\Support\Carbon::parse($waktu)->diffForHumans();
                } else {
                    $ket = ' — belum pernah terhubung';
                }

                // Nama outlet cuma ditulis di mode gabungan -- kalau
                // laporannya sudah khusus satu outlet, mengulang namanya
                // di tiap baris hanya memanjangkan pesan tanpa guna.
                $asal = '';

                if (! $outlet) {
                    $asal = ' ['.($namaOutlet[$p->outlet_id] ?? 'Stok pusat').']';
                }

                $teks .= "   • {$p->name} (<code>{$p->username}</code>){$asal}{$ket}\n";
            }

            if ($offlineAktif->count() > 30) {
                $teks .= "\n<i>...dan ".($offlineAktif->count() - 30)." pelanggan lain (dibatasi 30 supaya pesan tidak kepanjangan).</i>\n";
            }
        }

        // Suspend/Isolir ditampilkan sebagai ANGKA saja, tidak dirinci --
        // mereka offline karena memang diputus, jadi tidak perlu ditindak
        // teknisi. Merincinya cuma bikin pesan panjang dan menenggelamkan
        // daftar yang benar-benar perlu diperiksa di atas.
        if ($offlineDiputus->isNotEmpty()) {
            $suspend = $offlineDiputus->filter(fn ($p) => $p->status === CustomerStatus::Suspended)->count();
            $isolir = $offlineDiputus->filter(fn ($p) => $p->status === CustomerStatus::Isolir)->count();

            $teks .= "\n⚪ <b>Offline karena diputus ({$offlineDiputus->count()})</b>\n";
            $teks .= "   Suspend manual: {$suspend} &middot; Isolir tunggakan: {$isolir}\n";
        }

        $teks .= "\n<i>Data sesi dibaca langsung dari RADIUS saat ini juga.</i>";

        $kirim($teks);
    }

    protected function showOutletPickerForPppoe(string $chatId, TelegramService $telegram): void
    {
        // Batas dinaikkan dari 40 ke 200 -- lihat catatan di
        // showOutletPickerForRevenue() soal outlet yang hilang dari
        // daftar kalau urutan abjadnya kebetulan di luar batas lama.
        $outlets = Outlet::orderBy('name')->limit(200)->get(['id', 'name']);

        if ($outlets->isEmpty()) {
            $telegram->sendMessage($chatId, 'Belum ada outlet terdaftar.');

            return;
        }

        $buttons = [];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "pppoeout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih outlet/biller buat pelanggan PPPoE ini:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Mulai (atau ulang) alur tanya-jawab /pppoe -- rekam STATE-nya di
     * Cache (bukan cuma di memori PHP) karena tiap pesan Telegram yang
     * masuk sesudahnya adalah REQUEST HTTP BARU/terpisah, tidak ada
     * memori yang bertahan antar pesan kecuali disimpan eksplisit
     * begini. Dipanggil dari 2 tempat: langsung dari /pppoe (outlet
     * biasa, $messageId null -> kirim pesan baru), atau dari callback
     * "pppoeout:" (admin baru pilih outlet, $messageId terisi -> edit
     * tombol yang barusan ditekan).
     */
    protected function startPppoeFlow(string $chatId, Outlet $outlet, TelegramService $telegram, ?int $messageId = null): void
    {
        Cache::put($this->pppoeFlowKey($chatId), [
            'outlet_id' => $outlet->id,
            'step' => 'nama',
            'data' => [],
        ], now()->addMinutes(20));

        $teks = "📶 <b>Tambah Pelanggan PPPoE</b>\nOutlet/Biller: {$outlet->name}\n\n".
            "Ketik <b>Nama</b> pelanggan (kapan saja ketik /batal buat membatalkan):";

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teks);
        } else {
            $telegram->sendMessage($chatId, $teks);
        }
    }

    /**
     * Tampilkan menu PAKET Langganan PPPoE lewat tombol -- dipisah dari
     * langkah teks bebas lainnya (Nama/Alamat/No HP/Username/Password)
     * karena paket harus dipilih dari daftar yang BENAR-BENAR ada &
     * tersedia buat outlet ini, bukan diketik bebas. Sama persis
     * filternya dengan showPackageMenu() (voucher hotspot), cuma tipe
     * paketnya 'pppoe'/'both' (bukan 'hotspot'/'both').
     */
    protected function showPppoePackageMenu(string $chatId, int $outletId, TelegramService $telegram): void
    {
        $packages = ServicePackage::where('is_active', true)
            ->whereIn('type', ['pppoe', 'both'])
            ->get()
            ->filter(fn ($p) => $p->isAvailableForOutlet($outletId))
            ->values();

        if ($packages->isEmpty()) {
            Cache::forget($this->pppoeFlowKey($chatId));
            $telegram->sendMessage($chatId, 'Belum ada paket PPPoE aktif buat outlet ini. Alur dibatalkan -- hubungi admin dulu buat siapkan paketnya.');

            return;
        }

        $buttons = $packages->map(fn ($p) => [[
            'text' => $p->name,
            'callback_data' => "pppoepkg:{$p->id}",
        ]])->toArray();

        $telegram->sendMessage($chatId, 'Pilih Paket Langganan:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Langkah TERAKHIR alur /pppoe: pilih BILLER penagih pelanggan ini
     * (permintaan user: "selain outlet tambahkan juga biller sebagai
     * pemilik pelanggan").
     *
     * PENTING soal skema data -- SENGAJA TIDAK menambah kolom baru:
     * - OUTLET pemilik pelanggan disimpan di kolom `customers.outlet_id`
     *   (dipilih di awal alur).
     * - BILLER penagih disimpan di tabel pivot `biller_customer_commissions`
     *   lewat relasi Outlet::billedCustomers() -- PERSIS mekanisme yang
     *   sudah dipakai aplikasi di menu Biller > "Kelola Pelanggan &
     *   Komisi". Jadi pelanggan yang dibuat lewat bot langsung muncul di
     *   daftar tagihan biller itu, tanpa perlu migration baru.
     * Komisi diisi 0 dulu -- nominalnya diatur Owner lewat menu Biller.
     */
    /**
     * Menu pilih Biller, generik -- dipakai bersama oleh /pppoe,
     * /addstaticip & /addmember (lihat showPppoeBillerMenu() di bawah,
     * yang sekarang cuma pembungkus tipis buat /pppoe). $prefix beda
     * per alur (pppoebill/sipbill/membbill) supaya callback-nya tidak
     * ketuker antar alur yang sedang berjalan bersamaan di chat lain.
     */
    protected function showBillerMenuUntuk(string $chatId, string $prefix, TelegramService $telegram): void
    {
        $billers = Outlet::where('partner_type', \App\Enums\PartnerType::Biller)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(40)
            ->get(['id', 'name']);

        // Tombol "Tanpa Biller" SELALU ada (dan jadi satu-satunya pilihan
        // kalau memang belum ada biller terdaftar) supaya alurnya tidak
        // pernah buntu -- pelanggan tetap bisa dibuat tanpa biller.
        $buttons = [];

        foreach ($billers->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($b) => [
                'text' => $b->name,
                'callback_data' => "{$prefix}:{$b->id}",
            ])->values()->all();
        }

        $buttons[] = [[
            'text' => '⏭️ Tanpa Biller',
            'callback_data' => "{$prefix}:none",
        ]];

        $teks = $billers->isEmpty()
            ? "Belum ada Biller aktif terdaftar.\n\nLanjut tanpa biller:"
            : 'Terakhir — pilih <b>Biller</b> yang menagih pelanggan ini (supaya pelanggan ini muncul juga di pencarian bot milik Biller tsb):';

        $telegram->sendMessage($chatId, $teks, ['inline_keyboard' => $buttons]);
    }

    protected function showPppoeBillerMenu(string $chatId, TelegramService $telegram): void
    {
        $this->showBillerMenuUntuk($chatId, 'pppoebill', $telegram);
    }

    /**
     * Mesin state alur /pppoe -- dipanggil dari handleMessage() SETIAP
     * kali ada pesan teks BIASA masuk dari chat yang lagi punya state
     * tersimpan (lihat pengecekan $pppoeState di awal handleMessage()).
     * Satu langkah = satu field: Nama -> Alamat -> No HP -> (tombol)
     * Paket -> Username -> Password -> selesai (finalizePppoeCustomer()).
     */
    protected function handlePppoeFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $step = $state['step'] ?? null;

        switch ($step) {
            case 'nama':
                if ($text === '') {
                    $telegram->sendMessage($chatId, 'Nama tidak boleh kosong. Ketik Nama pelanggan:');

                    return;
                }

                $state['data']['name'] = $text;
                $state['step'] = 'alamat';
                Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Alamat pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'alamat':
                $state['data']['address'] = $text === '-' ? null : $text;
                $state['step'] = 'no_hp';
                Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik No HP pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'no_hp':
                $state['data']['phone'] = $text === '-' ? null : $text;
                $state['step'] = 'paket';
                Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showPppoePackageMenu($chatId, (int) $state['outlet_id'], $telegram);

                return;

            case 'paket':
                // Seharusnya dipilih lewat TOMBOL (callback "pppoepkg:"),
                // bukan diketik -- kalau ada teks masuk di langkah ini,
                // berarti user salah kirim (mestinya tekan tombolnya).
                $telegram->sendMessage($chatId, 'Pilih paketnya lewat tombol di atas ya, bukan diketik manual.');

                return;

            case 'username':
                $username = trim(str_replace(' ', '', $text));

                if ($username === '') {
                    $telegram->sendMessage($chatId, 'Username tidak boleh kosong. Ketik Username PPPoE:');

                    return;
                }

                // Kolom username di tabel customers UNIK (lihat migration
                // create_customers_table) -- dicek DULU di sini supaya
                // pesan errornya jelas/ramah, bukan cuma error SQL mentah
                // kalau kebetulan sudah dipakai. withoutGlobalScope() sama
                // seperti pola di handleCekVoucher() -- OutletScope model
                // ini cuma aktif kalau ada Auth::user() ber-role Outlet
                // (tidak ada di konteks webhook), jadi sebenarnya no-op,
                // tapi tetap eksplisit biar jelas maksudnya.
                $sudahAda = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->where('username', $username)
                    ->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "Username <code>{$username}</code> sudah dipakai pelanggan lain. Ketik username lain:");

                    return;
                }

                $state['data']['username'] = $username;
                $state['step'] = 'password';
                Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Password PPPoE (minimal 4 karakter):');

                return;

            case 'password':
                $password = trim($text);

                if (mb_strlen($password) < 4) {
                    $telegram->sendMessage($chatId, 'Password minimal 4 karakter. Ketik lagi:');

                    return;
                }

                $state['data']['password'] = $password;
                // Belum langsung dibuat: masih ada 1 langkah lagi, yaitu
                // pilih BILLER penagih (lewat tombol). Pelanggannya baru
                // benar-benar dibuat di callback "pppoebill:".
                $state['step'] = 'biller';
                Cache::put($this->pppoeFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showPppoeBillerMenu($chatId, $telegram);

                return;

            case 'biller':
                // Sama seperti langkah 'paket': harus lewat tombol.
                $telegram->sendMessage($chatId, 'Pilih billernya lewat tombol di atas ya, bukan diketik manual.');

                return;

            default:
                // Harusnya tidak pernah kejadian (state selalu diisi salah
                // satu step di atas), tapi jaga-jaga data Cache-nya rusak
                // /kadaluarsa separuh jalan -- daripada nyangkut selamanya.
                Cache::forget($this->pppoeFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Sesi tambah pelanggan PPPoE sudah tidak berlaku. Ketik /pppoe lagi.');
        }
    }

    /**
     * Langkah TERAKHIR alur /pppoe -- semua field sudah lengkap, bikin
     * Customer-nya beneran + sinkron ke RADIUS. SENGAJA REUSE persis
     * cara aplikasi (PppoeUserResource) bikin pelanggan PPPoE manual:
     * Customer::create() lalu app(CustomerRadiusService::class)->
     * provisionCustomer() -- BUKAN ditulis ulang logika radcheck-nya di
     * sini, supaya pelanggan yang dibuat lewat bot 100% konsisten
     * (kredensial RADIUS, Filter-Id/profile MikroTik, dst) dengan yang
     * dibuat lewat aplikasi. Alokasi IP statis otomatis dari IP Pool
     * paket (kalau ada) juga disamakan persis polanya.
     */
    protected function finalizePppoeCustomer(string $chatId, array $state, TelegramService $telegram): void
    {
        $outlet = Outlet::find($state['outlet_id'] ?? null);
        $paket = ServicePackage::find($state['data']['service_package_id'] ?? null);

        if (! $outlet || ! $paket) {
            $telegram->sendMessage($chatId, 'Gagal: outlet atau paket sudah tidak ada lagi. Ketik /pppoe lagi dari awal.');

            return;
        }

        $data = $state['data'];

        try {
            $customer = Customer::create([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'type' => CustomerType::Pppoe->value,
                'username' => $data['username'],
                'password' => $data['password'],
                'service_package_id' => $paket->id,
                'outlet_id' => $outlet->id,
                'status' => CustomerStatus::Active->value,
                'billing_day' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal membuat pelanggan PPPoE lewat bot Telegram', [
                'username' => $data['username'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat pelanggan (kemungkinan username sudah dipakai lagi barusan). Ketik /pppoe buat coba lagi.');

            return;
        }

        // BILLER penagih (kalau dipilih) -- disimpan di pivot
        // biller_customer_commissions lewat relasi billedCustomers(),
        // sama seperti menu Biller > "Kelola Pelanggan & Komisi" di
        // aplikasi. Komisi 0 dulu, diatur Owner belakangan. Gagal di
        // sini TIDAK membatalkan pembuatan pelanggannya.
        if (! empty($data['biller_id'])) {
            try {
                $biller = Outlet::find($data['biller_id']);
                $biller?->billedCustomers()->syncWithoutDetaching([
                    $customer->id => ['commission_amount' => 0],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Pelanggan PPPoE dibuat lewat bot tapi gagal ditugaskan ke biller', [
                    'customer_id' => $customer->id,
                    'biller_id' => $data['biller_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Sama persis pola di PppoeUserResource: kalau belum diisi IP
        // statis manual & paketnya punya IP Pool, coba alokasikan
        // otomatis -- GAGAL alokasi IP TIDAK membatalkan pembuatan
        // pelanggannya (pelanggan tetap dibuat, IP-nya kosong dulu).
        if (! $customer->static_ip && $paket->ip_pool_id) {
            $ip = $paket->ipPool?->nextAvailableIp();

            if ($ip) {
                $customer->update(['static_ip' => $ip]);
            }
        }

        try {
            app(CustomerRadiusService::class)->provisionCustomer($customer, $paket);
        } catch (\Throwable $e) {
            Log::warning('Pelanggan PPPoE dibuat tapi gagal provisioning RADIUS lewat bot Telegram', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, "⚠️ Pelanggan <b>{$customer->name}</b> berhasil dibuat, TAPI gagal disinkron ke RADIUS. Cek & sinkron manual lewat aplikasi (menu User PPPoE).");

            return;
        }

        $labelIp = $customer->static_ip ? "\nIP: <code>{$customer->static_ip}</code>" : '';

        $telegram->sendMessage($chatId,
            "✅ <b>Pelanggan PPPoE berhasil dibuat</b>\n\n".
            "Nama: {$customer->name}\n".
            'Alamat: '.($customer->address ?: '-')."\n".
            'No HP: '.($customer->phone ?: '-')."\n".
            "Outlet: {$outlet->name}\n".
            'Biller: '.(! empty($data['biller_id']) ? (Outlet::find($data['biller_id'])?->name ?? '-') : 'Tanpa biller')."\n".
            "Paket: {$paket->name}\n".
            "Username PPPoE: <code>{$customer->username}</code>\n".
            "Password PPPoE: <code>{$customer->password}</code>".
            $labelIp
        );
    }

    /**
     * Daftar kode voucher untuk dibalas ke chat setelah generate.
     *
     * WAJIB ikut menampilkan PASSWORD kalau metode loginnya "Username +
     * Password": sejak format voucher bot bisa diatur sendiri, password
     * tidak lagi selalu sama dengan username. Kalau di sini cuma
     * username yang dikirim, voucher hasil bot jadi TIDAK BISA DIPAKAI —
     * passwordnya tidak pernah sampai ke tangan penjual.
     */
    /**
     * SATU baris = SATU blok <code> utuh (bukan dipecah jadi dua blok
     * <code> terpisah untuk username & password seperti sebelumnya).
     * Alasannya: di Telegram, teks dalam <code>...</code> bisa di-tap
     * buat langsung disalin -- tapi kalau username & password masing-
     * masing dibungkus <code> SENDIRI-SENDIRI (dipisah " / " yang TIDAK
     * ikut ter-copy), admin harus tap dua kali & gabungkan sendiri
     * sebelum bisa dikirim ke pelanggan. Sekarang satu baris/voucher
     * jadi SATU blok copy -- tap sekali, langsung dapat teks lengkap
     * yang siap tempel & kirim ke pelanggan tanpa ketik ulang.
     */
    protected function barisKodeVoucher(\Illuminate\Support\Collection $vouchers): string
    {
        return $vouchers->map(function ($v) {
            if ($v->password && $v->password !== $v->username) {
                return "<code>{$v->username} / {$v->password}</code>";
            }

            return "<code>{$v->username}</code>";
        })->implode("\n");
    }

    /**
     * Format voucher yang dibuat LEWAT BOT — diatur di menu Pengaturan &gt;
     * Bot Telegram, bagian "Format Voucher dari Bot".
     *
     * Dipakai bersama oleh SEMUA jalur pembuatan voucher lewat bot
     * (tombol /voucher maupun perintah suara). Sengaja satu method:
     * dulu formatnya ditulis terpisah di dua tempat, dan kalau salah
     * satunya lupa diubah, voucher dari jalur itu diam-diam beda bentuk
     * dari yang lain tanpa ada yang sadar.
     *
     * Nilai cadangan di tiap baris sengaja sama dengan format yang dulu
     * dipatok di kode (user_only + alnum_upper + tanpa prefix), supaya
     * instalasi yang belum pernah menyimpan halaman pengaturan itu tetap
     * menghasilkan voucher yang sama persis seperti sebelumnya.
     *
     * Nilai enum divalidasi pakai tryFrom(): isi tabel setting bisa saja
     * tidak dikenali lagi (mis. enumnya pernah berubah), dan from() akan
     * melempar exception yang bikin seluruh pembuatan voucher gagal —
     * lebih baik jatuh ke bawaan daripada bot berhenti melayani.
     */
    protected function formatVoucherBot(): array
    {
        $metode = \App\Enums\VoucherLoginMethod::tryFrom(
            (string) Setting::get('telegram_voucher_login_method')
        ) ?? \App\Enums\VoucherLoginMethod::UserOnly;

        $kombinasi = \App\Enums\VoucherCodeCombination::tryFrom(
            (string) Setting::get('telegram_voucher_combination')
        ) ?? \App\Enums\VoucherCodeCombination::AlnumUpper;

        return [
            'login_method' => $metode->value,
            'code_combination' => $kombinasi->value,
            'prefix' => (string) (Setting::get('telegram_voucher_prefix') ?: ''),
            'username_length' => (int) (Setting::get('telegram_voucher_length') ?: 8),
            'password_length' => (int) (Setting::get('telegram_voucher_password_length') ?: 6),
        ];
    }

    /**
     * Tampilkan konfirmasi "Yakin mau buat voucher?" SEBELUM benar-benar
     * generate -- permintaan user: supaya tidak kepencet salah tombol
     * jumlah / salah ketik angka lalu voucher (yang makan stok/nomor di
     * Mikrotik) sudah kadung dibuat padahal tidak sengaja. Dipakai oleh
     * SEMUA jalur generate voucher: tombol jumlah cepat (qty: di
     * handleCallback()), jumlah custom yang diketik
     * (handleVoucherQtyFlowInput()), dan perintah suara (voice command).
     *
     * $messageId null artinya konfirmasi dikirim sebagai PESAN BARU
     * (dipakai jalur ketik/voice, yang tidak sedang mengedit pesan
     * tombol manapun) -- kalau ada messageId, pesan yang itu yang
     * di-edit jadi konfirmasi (dipakai jalur tombol qty:).
     */
    protected function showGenerateConfirmation(string $chatId, ?int $messageId, int $pkgId, int $qty, Outlet $outlet, TelegramService $telegram): void
    {
        $package = ServicePackage::find($pkgId);

        if (! $package) {
            $teks = 'Paket tidak ditemukan.';
            $messageId ? $telegram->editMessageText($chatId, $messageId, $teks) : $telegram->sendMessage($chatId, $teks);

            return;
        }

        $teks = "<b>Konfirmasi Generate Voucher</b>\n\n".
            "Paket  : {$package->name}\n".
            "Jumlah : {$qty}\n".
            "Outlet : {$outlet->name}\n\n".
            'Yakin ingin membuat voucher ini?';

        $keyboard = ['inline_keyboard' => [[
            ['text' => '✅ Ya, Buat', 'callback_data' => "gengo:{$pkgId}:{$qty}"],
            ['text' => '❌ Tidak, Batal', 'callback_data' => 'gencancel'],
        ]]];

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teks, $keyboard);
        } else {
            $telegram->sendMessage($chatId, $teks, $keyboard);
        }
    }

    protected function generateAndReply(string $chatId, int $messageId, int $pkgId, int $qty, Outlet $outlet, TelegramService $telegram): void
    {
        $telegram->editMessageText($chatId, $messageId, '⏳ Membuat voucher...');

        try {
            $vouchers = app(GenerateVoucherBatchAction::class)->execute(array_merge([
                'service_package_id' => $pkgId,
                'quantity' => $qty,
                'outlet_id' => $outlet->id,
                // Ditandai supaya di tabel Voucher kelihatan bahwa
                // voucher ini dibuat lewat bot Telegram, bukan aplikasi.
                'generated_via' => \App\Enums\VoucherSource::Telegram->value,
            ], $this->formatVoucherBot()));
        } catch (\Throwable $e) {
            $telegram->editMessageText($chatId, $messageId, '❌ Gagal membuat voucher: '.$e->getMessage());

            return;
        }

        $this->kirimHasilVoucher($chatId, $vouchers, $telegram, $messageId);
    }

    /**
     * Kunci Cache untuk alur "✍️ Jumlah lain..." yang sedang menunggu
     * admin/outlet mengetik ANGKA jumlah voucher -- dipakai kalau mau
     * generate di luar preset tombol cepat (mis. 2000). Sama polanya
     * dengan pppoeFlowKey()/bayarFlowKey().
     */
    protected function voucherQtyFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_voucher_qty_flow:{$namespace}:{$chatId}";
    }

    /**
     * Cache key buat menyimpan "admin ini lagi generate voucher ATAS
     * NAMA outlet mana" (permintaan user: admin bisa generate voucher
     * setelah pilih outlet dulu). Diisi di 'vchout:' (handleCallback()),
     * lalu dipakai balik di resolusi $outlet paling atas handleCallback()
     * -- SEMUA langkah setelahnya (pkg:/qty:/qtycustom:/gengo:) otomatis
     * ikut kepakai tanpa perlu diubah satu-satu, karena semuanya baca
     * dari variabel $outlet yang sama itu.
     */
    protected function adminActingOutletKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_admin_acting_outlet:{$namespace}:{$chatId}";
    }

    /**
     * Dipanggil dari handleMessage() saat chat ini SEDANG menunggu
     * angka jumlah voucher (state dari qtycustom: di handleCallback()).
     */
    protected function handleVoucherQtyFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $qty = (int) preg_replace('/[^0-9]/', '', $text);
        $maks = \App\Services\VoucherGeneratorService::MAX_QUANTITY;

        if ($qty < 1 || $qty > $maks) {
            $telegram->sendMessage($chatId, "Jumlah tidak valid. Ketik angka antara 1 - {$maks}, atau /batal buat membatalkan.");

            return;
        }

        Cache::forget($this->voucherQtyFlowKey($chatId));

        $outlet = Outlet::find($state['outlet_id'] ?? null);

        if (! $outlet) {
            $telegram->sendMessage($chatId, 'Outlet tidak ditemukan lagi. Mulai ulang dengan /voucher.');

            return;
        }

        // BARU (permintaan user): jangan langsung generate, tampilkan
        // konfirmasi dulu -- lihat showGenerateConfirmation(). $messageId
        // null karena ini jalur ketik teks bebas, bukan sedang mengedit
        // pesan tombol manapun -- konfirmasinya dikirim sebagai pesan baru.
        $this->showGenerateConfirmation($chatId, null, (int) ($state['pkg_id'] ?? 0), $qty, $outlet, $telegram);
    }

    /**
     * Satu pintu buat balas hasil generate voucher (dipakai oleh
     * generateAndReply(), handleVoucherQtyFlowInput(), DAN perintah
     * suara) -- jumlah kecil tetap dibalas sebagai teks yang bisa
     * langsung di-tap-copy seperti sebelumnya, jumlah besar (di atas
     * VOUCHER_PDF_THRESHOLD) dikirim sebagai FILE PDF supaya tidak
     * kepotong batas 4096 karakter pesan Telegram DAN lebih enak
     * dicari/di-scroll lagi nanti (permintaan user).
     */
    protected function kirimHasilVoucher(string $chatId, \Illuminate\Support\Collection $vouchers, TelegramService $telegram, ?int $messageId = null): void
    {
        $batchLabel = $vouchers->first()?->batch_label ?: ('Voucher '.$vouchers->count());

        // "Voucher {HARGA} Berhasil dibuat {jumlah}pcs" (permintaan
        // user, diluruskan: $batchLabel sudah berisi "Voucher {harga}",
        // mis. "Voucher 2000" = voucher seharga Rp2.000 -- bukan jumlah
        // yang digenerate, itu ditambahkan terpisah di sini sebagai
        // "...pcs").
        $judul = "{$batchLabel} Berhasil dibuat {$vouchers->count()}pcs";

        if ($vouchers->count() <= self::VOUCHER_PDF_THRESHOLD) {
            $lines = $this->barisKodeVoucher($vouchers);
            $teks = "<b>{$judul}</b>\n\n{$lines}";

            if ($messageId) {
                $telegram->editMessageText($chatId, $messageId, $teks);
            } else {
                $telegram->sendMessage($chatId, $teks);
            }

            return;
        }

        $teksSelesai = "<b>{$judul}</b>\n\n⏳ Menyiapkan PDF...";

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teksSelesai);
        } else {
            $telegram->sendMessage($chatId, $teksSelesai);
        }

        $this->kirimPdfBatch($chatId, $vouchers, $telegram, $batchLabel);
    }

    /**
     * Render daftar voucher jadi PDF (lihat resources/views/exports/
     * voucher-batch-pdf.blade.php) lalu kirim sebagai dokumen ke chat.
     * Dipakai buat hasil generate BARU (kirimHasilVoucher) maupun buat
     * unduh ULANG batch LAMA lewat /batch (kirimPdfBatchByCode).
     */
    protected function kirimPdfBatch(string $chatId, \Illuminate\Support\Collection $vouchers, TelegramService $telegram, ?string $batchLabel = null): void
    {
        $first = $vouchers->first();

        if (! $first) {
            return;
        }

        $package = $first->voucher_profile_id ? ServicePackage::find($first->voucher_profile_id) : null;
        $outlet = $first->outlet_id ? Outlet::find($first->outlet_id) : null;
        $showPassword = (bool) ($first->password && $first->password !== $first->username);
        $batchLabel = $batchLabel ?: ($first->batch_label ?: ('Voucher '.$vouchers->count()));
        $ispName = Setting::get('isp_name', 'Toya Radius');

        // Durasi aktif & masa berlaku SATU KALI dihitung dari paketnya
        // (bukan per voucher) -- satu batch selalu satu paket & satu
        // outlet yang sama, jadi tidak perlu query berulang per baris
        // kartu seperti di halaman cetak aplikasi (yang memang
        // menampilkan macam-macam batch/paket sekaligus).
        $durasiAktif = 'Unlimited';
        if ($package?->limit_type?->value === 'waktu' && $package->time_limit_value) {
            $durasiAktif = $package->time_limit_value.' '.($package->time_limit_unit?->label() ?? '');
        }

        $masaBerlaku = '-';
        if ($package?->validity_days) {
            $masaBerlaku = $package->validity_days.' '.($package->validity_unit?->label() ?? 'Hari');
        }

        $outletName = $outlet?->name ?? $ispName;

        try {
            // Kartu siap-cetak-gunting -- gaya template yang ada di
            // aplikasi (permintaan user), tata letak 5 kolom x 50
            // voucher per lembar A4. Lihat exports/voucher-cards-pdf
            // buat alasan kenapa dipakai TABLE, bukan view cetak
            // aplikasi yang asli (itu pakai CSS grid, tidak didukung
            // dompdf).
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.voucher-cards-pdf', [
                'vouchers' => $vouchers->values(),
                'batchLabel' => $batchLabel,
                'ispName' => $ispName,
                'outletName' => $outletName,
                'tampilkanIsp' => $outletName !== $ispName,
                'durasiAktif' => $durasiAktif,
                'masaBerlaku' => $masaBerlaku,
                'showPassword' => $showPassword,
            ])->setPaper('a4');

            $fileName = \Illuminate\Support\Str::slug($batchLabel).'-'.now()->format('His').'.pdf';

            $telegram->sendDocument($chatId, $pdf->output(), $fileName, "📄 <b>{$batchLabel}</b> — {$vouchers->count()} voucher");
        } catch (\Throwable $e) {
            Log::error('Gagal membuat PDF voucher dari bot Telegram', ['error' => $e->getMessage()]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat PDF: '.$e->getMessage());
        }
    }

    /**
     * Ringkasan BATCH voucher (dikelompokkan per batch_code) buat
     * perintah /batch -- permintaan user: supaya batch tertentu (mis.
     * "Voucher 2000") gampang dicari/di-scroll lagi belakangan, tanpa
     * buka aplikasi.
     */
    protected function batchSummaries(?int $outletId, int $limit = 15): \Illuminate\Support\Collection
    {
        $query = Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->selectRaw('batch_code, MAX(batch_label) as batch_label, MAX(voucher_profile_id) as voucher_profile_id, MIN(created_at) as created_at, COUNT(*) as jumlah')
            ->whereNotNull('batch_code')
            ->groupBy('batch_code')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query->get();

        $paketNama = ServicePackage::whereIn('id', $rows->pluck('voucher_profile_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(function ($row) use ($paketNama) {
            $row->package_name = $paketNama[$row->voucher_profile_id] ?? '-';

            return $row;
        });
    }

    protected function batchButtonLabel(object $row): string
    {
        $label = $row->batch_label ?: "Voucher {$row->jumlah}";
        $tanggal = \Illuminate\Support\Carbon::parse($row->created_at)->format('d/m H:i');

        return "{$label} · {$row->package_name} · {$tanggal}";
    }

    /**
     * Balasan LANGSUNG buat outlet biasa yang kirim /batch -- cuma
     * riwayat batch miliknya sendiri, sama pola-nya seperti
     * sendStockPackagePicker().
     */
    protected function sendBatchList(string $chatId, Outlet $outlet, TelegramService $telegram): void
    {
        $rows = $this->batchSummaries($outlet->id);

        if ($rows->isEmpty()) {
            $telegram->sendMessage($chatId, "📚 <b>Riwayat Batch Voucher</b>\nOutlet: {$outlet->name}\n\nBelum ada batch voucher yang pernah digenerate.");

            return;
        }

        $buttons = $rows->map(fn ($row) => [[
            'text' => $this->batchButtonLabel($row),
            'callback_data' => "batchpdf:{$row->batch_code}",
        ]])->values()->all();

        $telegram->sendMessage($chatId, "📚 <b>Riwayat Batch Voucher</b>\nOutlet: {$outlet->name}\n\nPilih batch buat lihat/unduh daftar kodenya (PDF):", ['inline_keyboard' => $buttons]);
    }

    /**
     * Khusus ADMIN: pilih outlet dulu sebelum lihat riwayat batch --
     * sama pola-nya seperti showOutletPickerForStock().
     */
    protected function showOutletPickerForBatch(string $chatId, TelegramService $telegram): void
    {
        $outlets = Outlet::orderBy('name')->limit(200)->get(['id', 'name']);

        $buttons = [[
            ['text' => '🌐 Semua Outlet', 'callback_data' => 'batchout:all'],
        ]];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "batchout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih outlet buat lihat riwayat batch vouchernya:', ['inline_keyboard' => $buttons]);
    }

    /**
     * Dipanggil dari menu pilih outlet (handleCallback(), "batchout:")
     * -- khusus ADMIN. $outlet NULL artinya cakupan SEMUA OUTLET.
     */
    protected function editBatchList(string $chatId, int $messageId, ?Outlet $outlet, TelegramService $telegram): void
    {
        $rows = $this->batchSummaries($outlet?->id);
        $cakupan = $outlet ? "Outlet: {$outlet->name}" : 'Cakupan: SEMUA OUTLET';

        if ($rows->isEmpty()) {
            $telegram->editMessageText($chatId, $messageId, "📚 <b>Riwayat Batch Voucher</b>\n{$cakupan}\n\nBelum ada batch voucher.");

            return;
        }

        $buttons = $rows->map(fn ($row) => [[
            'text' => $this->batchButtonLabel($row),
            'callback_data' => "batchpdf:{$row->batch_code}",
        ]])->values()->all();

        $telegram->editMessageText($chatId, $messageId, "📚 <b>Riwayat Batch Voucher</b>\n{$cakupan}\n\nPilih batch buat lihat/unduh daftar kodenya (PDF):", ['inline_keyboard' => $buttons]);
    }

    /**
     * Dipanggil dari tombol satu batch (handleCallback(), "batchpdf:")
     * -- ambil ULANG semua voucher batch itu dari database lalu kirim
     * sebagai PDF, TANPA peduli berapa jumlahnya (batch lama yang
     * dulunya di bawah threshold pun tetap boleh diunduh PDF-nya kalau
     * mau, bukan cuma yang di atas threshold).
     */
    protected function kirimPdfBatchByCode(string $chatId, int $messageId, string $batchCode, TelegramService $telegram): void
    {
        $vouchers = Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('batch_code', $batchCode)
            ->orderBy('id')
            ->get();

        if ($vouchers->isEmpty()) {
            $telegram->editMessageText($chatId, $messageId, 'Batch ini tidak ditemukan (mungkin sudah dihapus).');

            return;
        }

        $batchLabel = $vouchers->first()->batch_label ?: ('Voucher '.$vouchers->count());

        $telegram->editMessageText($chatId, $messageId, "⏳ Menyiapkan PDF \"{$batchLabel}\" ({$vouchers->count()} voucher)...");

        $this->kirimPdfBatch($chatId, $vouchers, $telegram, $batchLabel);
    }

    /**
     * Export SISA STOK (voucher belum terjual/belum aktif) jadi PDF
     * dengan FORMAT KARTU YANG SAMA seperti hasil generate voucher baru
     * (lihat kirimPdfBatch()) -- permintaan user: bisa langsung
     * cetak-gunting sisa stok yang sudah ada, bukan cuma voucher yang
     * baru saja digenerate. $outlet & $paket WAJIB spesifik (dijamin
     * oleh pemanggilnya, tombol di showStockReportFinal() cuma pernah
     * dikirim dalam kondisi itu) -- durasi/masa berlaku & nama outlet
     * di kartu dihitung SATU KALI dari voucher pertama (lihat
     * kirimPdfBatch()), jadi semua baris harus benar-benar satu paket
     * & satu outlet yang sama supaya labelnya tidak salah buat sebagian
     * kartu.
     */
    protected function kirimPdfStok(string $chatId, int $messageId, Outlet $outlet, ServicePackage $paket, TelegramService $telegram): void
    {
        $vouchers = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('status', [\App\Enums\VoucherStatus::BelumTerjual, \App\Enums\VoucherStatus::Terjual])
            ->where('voucher_profile_id', $paket->id)
            ->where('outlet_id', $outlet->id)
            ->orderBy('id')
            ->get();

        if ($vouchers->isEmpty()) {
            $telegram->editMessageText($chatId, $messageId, 'Tidak ada stok tersisa untuk paket ini.');

            return;
        }

        $batchLabel = "Stok {$paket->name} — {$outlet->name}";

        $telegram->editMessageText($chatId, $messageId, "⏳ Menyiapkan PDF \"{$batchLabel}\" ({$vouchers->count()} voucher)...");

        $this->kirimPdfBatch($chatId, $vouchers, $telegram, $batchLabel);
    }

    /**
     * Cek apakah chat ID ini terdaftar sebagai ADMIN (kolom
     * telegram_admin_chat_id di Pengaturan > Bot Telegram) -- dipakai
     * bareng oleh /cek (lihat handleCekVoucher) dan /pendapatan (lihat
     * di bawah) supaya admin bisa lihat/cek data SEMUA outlet, bukan
     * cuma satu outlet yang terhubung ke chat-nya.
     *
     * BISA LEBIH DARI SATU admin: isi kolomnya dipisah per BARIS BARU
     * atau KOMA, mis.:
     *     646525365
     *     712345678, 899001122
     * Sebelumnya cuma menerima SATU Chat ID persis apa adanya -- tetap
     * kompatibel (satu ID polos tanpa pemisah apa pun tetap jalan sama
     * seperti sebelumnya), cuma sekarang boleh diisi lebih dari satu.
     */

    // ================================================================
    // PERINTAH KHUSUS ADMIN — pembantu bersama
    // ================================================================

    /**
     * Cari pelanggan dari kata kunci (nama ATAU username), lalu tampilkan
     * hasilnya sebagai tombol. $prefix menentukan alur lanjutannya
     * ("gpcust" = ganti paket, "baycust" = catat pembayaran).
     *
     * Dipakai bersama oleh /gantipaket dan /bayar supaya cara mencari
     * pelanggan PERSIS sama di keduanya — kalau ditulis dua kali, yang
     * satu gampang ketinggalan saat yang lain diperbaiki.
     */
    protected function cariPelangganUntukAksi(string $chatId, string $kunci, string $prefix, string $labelAksi, TelegramService $telegram, ?Outlet $biller = null): void
    {
        $pelanggan = Customer::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where(function ($q) use ($kunci) {
                $q->where('name', 'like', '%'.$kunci.'%')
                    ->orWhere('username', 'like', '%'.$kunci.'%');
            })
            // Kalau dipanggil dari Biller (permintaan user: /bayar juga
            // boleh dipakai Biller), pencariannya dibatasi HANYA
            // pelanggan tanggungan biller itu -- lihat
            // idPelangganMilikBiller(). Untuk admin ($biller null),
            // pencarian tetap lintas semua pelanggan seperti semula.
            ->when($biller, fn ($q) => $q->whereIn('id', $this->idPelangganMilikBiller($biller)))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'username', 'status']);

        if ($pelanggan->isEmpty()) {
            $telegram->sendMessage($chatId, "Tidak ada pelanggan yang cocok dengan \"<b>{$kunci}</b>\".");

            return;
        }

        if ($pelanggan->count() === 1) {
            // Cuma satu yang cocok — langsung lanjut, tidak usah
            // menyuruh memilih dari daftar berisi satu tombol.
            $satu = $pelanggan->first();

            if ($prefix === 'gpcust') {
                $this->showPilihanPaketBaru($chatId, null, $satu->id, $telegram);
            } elseif ($prefix === 'cetakcust') {
                $this->showPilihanPrinterCetakUlang($chatId, null, $satu->id, $telegram);
            } else {
                $this->showTagihanPelanggan($chatId, null, $satu->id, $telegram);
            }

            return;
        }

        $tombol = $pelanggan->map(fn ($p) => [[
            'text' => $p->name.' ('.$p->username.')',
            'callback_data' => "{$prefix}:{$p->id}",
        ]])->values()->all();

        $telegram->sendMessage(
            $chatId,
            "Ditemukan {$pelanggan->count()} pelanggan. Pilih yang mau di-{$labelAksi}:",
            ['inline_keyboard' => $tombol]
        );
    }

    /**
     * Label & pesan untuk tiga aksi status pelanggan lewat bot: "iso"
     * (isolir manual), "bka" (buka isolir/suspend), "non" (nonaktifkan
     * manual, bukan tunggakan). Dikumpulkan di satu tempat supaya urutan
     * status/pesannya konsisten antar ketiganya.
     *
     * @return array{label: string, statusBaru: \App\Enums\CustomerStatus, sudahBerstatus: \App\Enums\CustomerStatus}
     */
    protected function detailAksiStatus(string $prefix): array
    {
        return match ($prefix) {
            'iso' => ['label' => 'isolir', 'statusBaru' => CustomerStatus::Isolir, 'sudahBerstatus' => CustomerStatus::Isolir],
            'bka' => ['label' => 'buka isolir', 'statusBaru' => CustomerStatus::Active, 'sudahBerstatus' => CustomerStatus::Active],
            'non' => ['label' => 'nonaktifkan', 'statusBaru' => CustomerStatus::Suspended, 'sudahBerstatus' => CustomerStatus::Suspended],
            default => ['label' => $prefix, 'statusBaru' => CustomerStatus::Active, 'sudahBerstatus' => CustomerStatus::Active],
        };
    }

    /**
     * Cari pelanggan dari kata kunci (nama ATAU username) untuk salah
     * satu dari tiga aksi status ("iso"/"bka"/"non") lewat bot, lalu
     * tampilkan hasilnya sebagai tombol -- sama seperti
     * cariPelangganUntukAksi() di atas, tapi menuju layar KONFIRMASI
     * dulu (bukan langsung ke layar berikutnya), karena ketiga aksi ini
     * memutus/menyambung akses internet pelanggan.
     */
    protected function cariPelangganUntukAksiStatus(string $chatId, string $kunci, string $prefix, string $labelAksi, TelegramService $telegram): void
    {
        $pelanggan = Customer::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where(function ($q) use ($kunci) {
                $q->where('name', 'like', '%'.$kunci.'%')
                    ->orWhere('username', 'like', '%'.$kunci.'%');
            })
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'username', 'status']);

        if ($pelanggan->isEmpty()) {
            $telegram->sendMessage($chatId, "Tidak ada pelanggan yang cocok dengan \"<b>{$kunci}</b>\".");

            return;
        }

        if ($pelanggan->count() === 1) {
            $this->showKonfirmasiAksiStatus($chatId, null, $prefix, $pelanggan->first()->id, $telegram);

            return;
        }

        $tombol = $pelanggan->map(fn ($p) => [[
            'text' => $p->name.' ('.$p->username.') — '.$p->status->label(),
            'callback_data' => "{$prefix}cust:{$p->id}",
        ]])->values()->all();

        $telegram->sendMessage(
            $chatId,
            "Ditemukan {$pelanggan->count()} pelanggan. Pilih yang mau di-{$labelAksi}:",
            ['inline_keyboard' => $tombol]
        );
    }

    /**
     * Layar konfirmasi sebelum status pelanggan benar-benar diubah --
     * dipakai bareng oleh isolir/buka isolir/nonaktifkan. Kalau
     * pelanggan SUDAH berstatus yang dituju, tombol tidak ditampilkan
     * sama sekali (tidak ada gunanya "konfirmasi" sesuatu yang sudah
     * terjadi).
     */
    protected function showKonfirmasiAksiStatus(string $chatId, ?int $messageId, string $prefix, int $customerId, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tidak ditemukan.', null, $telegram);

            return;
        }

        $d = $this->detailAksiStatus($prefix);

        $teks = "⚠️ <b>Konfirmasi ".ucfirst($d['label'])."</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Status sekarang: {$pelanggan->status->label()}\n";

        if ($prefix === 'iso' || $prefix === 'bka') {
            $tunggakan = $pelanggan->invoices()->whereIn('status', ['unpaid', 'overdue'])->count();
            if ($tunggakan > 0) {
                $teks .= "Tagihan belum lunas: {$tunggakan}\n";
            }
        }

        if ($pelanggan->status === $d['sudahBerstatus']) {
            $teks .= "\nPelanggan ini SUDAH berstatus ini -- tidak ada yang perlu diubah.";
            $this->balas($chatId, $messageId, $teks, null, $telegram);

            return;
        }

        $teks .= "\nLanjutkan?";

        $this->balas($chatId, $messageId, $teks, ['inline_keyboard' => [[
            ['text' => '✅ Ya, '.$d['label'], 'callback_data' => "{$prefix}ok:{$pelanggan->id}"],
        ]]], $telegram);
    }

    /**
     * Eksekusi isolir/buka isolir/nonaktifkan setelah tombol "Ya"
     * ditekan. Selalu lewat CustomerRadiusService (BUKAN mengubah kolom
     * status saja) -- kalau cuma status aplikasi yang berubah sementara
     * RADIUS tidak ikut ditulis ulang, pelanggan bisa tetap
     * tersambung/tertolak berlawanan dengan yang ditampilkan aplikasi.
     */
    protected function terapkanAksiStatus(string $chatId, int $messageId, string $prefix, int $customerId, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tidak ditemukan.', null, $telegram);

            return;
        }

        $d = $this->detailAksiStatus($prefix);

        if ($pelanggan->status === $d['sudahBerstatus']) {
            $this->balas($chatId, $messageId, 'Pelanggan ini sudah berstatus '.$pelanggan->status->label().' -- tidak ada perubahan.', null, $telegram);

            return;
        }

        $statusSebelumnya = $pelanggan->status->label();

        try {
            $radius = app(CustomerRadiusService::class);
            $pelanggan->update(['status' => $d['statusBaru']]);

            if ($d['statusBaru'] === CustomerStatus::Active) {
                $radius->reactivate($pelanggan);
            } else {
                $radius->suspend($pelanggan);
            }
        } catch (\Throwable $e) {
            Log::error('Gagal ubah status pelanggan lewat Telegram', [
                'customer_id' => $customerId,
                'aksi' => $prefix,
                'error' => $e->getMessage(),
            ]);

            $this->balas($chatId, $messageId, '❌ Gagal: '.$e->getMessage(), null, $telegram);

            return;
        }

        $teks = "✅ <b>Selesai</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Status: {$statusSebelumnya} → {$pelanggan->status->label()}\n";

        // Pengingat khusus buka isolir: invoices:cek-overdue hanya
        // menyisir invoice yang MASIH Unpaid saat lewat jatuh tempo --
        // begitu sudah jadi Overdue (seperti yang terjadi tepat sebelum
        // pelanggan ini diisolir), invoice yang SAMA tidak akan pernah
        // membuatnya diisolir ulang secara otomatis.
        if ($prefix === 'bka') {
            $adaTunggakan = $pelanggan->invoices()->whereIn('status', ['unpaid', 'overdue'])->exists();

            if ($adaTunggakan) {
                $teks .= "\n<i>Catatan: pelanggan ini masih punya tagihan belum lunas. Sistem TIDAK akan mengisolir ulang tagihan yang sama secara otomatis -- kalau tetap tidak dibayar, isolir berikutnya juga harus manual (/isolir).</i>";
            }
        }

        $this->balas($chatId, $messageId, $teks, null, $telegram);
    }

    /**
     * /isolirinfo -- ringkasan cepat, tidak mengubah apa pun.
     */
    protected function showRingkasanIsolir(string $chatId, TelegramService $telegram): void
    {
        $dasar = fn () => Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class);

        $isolir = $dasar()->where('status', CustomerStatus::Isolir)->count();
        $suspend = $dasar()->where('status', CustomerStatus::Suspended)->count();
        $aktif = $dasar()->where('status', CustomerStatus::Active)->count();

        $teks = "📊 <b>Ringkasan Status Pelanggan</b>\n\n";
        $teks .= "🔴 Isolir (tunggakan): <b>{$isolir}</b>\n";
        $teks .= "🟠 Suspend (manual): <b>{$suspend}</b>\n";
        $teks .= "🟢 Aktif: <b>{$aktif}</b>\n";

        $telegram->sendMessage($chatId, $teks);
    }

    /**
     * Kirim ATAU sunting pesan, tergantung apakah alurnya datang dari
     * tombol ($messageId ada) atau dari perintah ketik ($messageId null).
     *
     * PENTING -- ini penyebab "tombol ditekan tapi tidak terjadi apa-apa":
     * TelegramService::call() sengaja TIDAK melempar exception kalau
     * Telegram menolak; kegagalan cuma dicatat ke log dan dikembalikan
     * sebagai ['ok' => false]. Jadi editMessageText yang ditolak
     * menghasilkan DIAM TOTAL di layar pengguna.
     *
     * Telegram bisa menolak penyuntingan karena banyak sebab yang wajar:
     * pesannya sudah lebih dari 48 jam, isinya dianggap tidak berubah,
     * atau ada satu karakter yang merusak penguraian HTML. Semuanya di
     * luar kendali kita dan tidak layak membuat bot bungkam.
     *
     * Karena itu: kalau menyunting gagal, KIRIM PESAN BARU. Pengguna
     * selalu mendapat jawaban, apa pun yang terjadi pada pesan lamanya.
     */
    protected function balas(string $chatId, ?int $messageId, string $teks, ?array $markup, TelegramService $telegram): void
    {
        if ($messageId) {
            $hasil = $telegram->editMessageText($chatId, $messageId, $teks, $markup);

            if (($hasil['ok'] ?? false) === true) {
                return;
            }

            Log::warning('Sunting pesan Telegram ditolak, dikirim sebagai pesan baru', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'alasan' => $hasil['description'] ?? $hasil['error'] ?? 'tidak diketahui',
            ]);
        }

        $telegram->sendMessage($chatId, $teks, $markup);
    }

    // ================================================================
    // 1) GANTI PAKET PELANGGAN
    // ================================================================

    protected function showPilihanPaketBaru(string $chatId, ?int $messageId, int $customerId, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tidak ditemukan.', null, $telegram);

            return;
        }

        // Paket yang ditawarkan dibatasi ke JENIS yang sama dengan
        // langganan pelanggan ini (PPPoE tetap PPPoE, hotspot tetap
        // hotspot). Menawarkan paket lintas jenis cuma mengundang salah
        // pilih: profil RADIUS-nya beda dan sambungannya bisa mati.
        $jenis = $pelanggan->type === CustomerType::Pppoe
            ? ['pppoe', 'both']
            : ['hotspot', 'both'];

        $paket = ServicePackage::where('is_active', true)
            ->whereIn('type', $jenis)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'sale_price']);

        if ($paket->isEmpty()) {
            $this->balas($chatId, $messageId, 'Belum ada paket aktif yang cocok untuk pelanggan ini.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $paketSekarang = $pelanggan->servicePackage;

        $teks = "🔄 <b>Ganti Paket</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= 'Paket sekarang: '.($paketSekarang?->name ?? '-');

        if ($paketSekarang) {
            $teks .= ' — '.$rp($paketSekarang->sale_price);
        }

        $teks .= "\n\nPilih paket baru:";

        $tombol = $paket
            ->filter(fn ($p) => $p->id !== $pelanggan->service_package_id)
            ->map(fn ($p) => [[
                'text' => $p->name.' — '.$rp($p->sale_price),
                'callback_data' => "gppkg:{$pelanggan->id}:{$p->id}",
            ]])->values()->all();

        if (empty($tombol)) {
            $this->balas($chatId, $messageId, $teks."\n\n(Tidak ada paket lain selain yang sedang dipakai.)", null, $telegram);

            return;
        }

        $this->balas($chatId, $messageId, $teks, ['inline_keyboard' => $tombol], $telegram);
    }

    /**
     * Terapkan paket baru: ubah data pelanggan, lalu TULIS ULANG ke
     * RADIUS supaya kecepatan/profilnya benar-benar berubah.
     *
     * TAGIHAN BULAN BERJALAN SENGAJA TIDAK DIUTAK-ATIK (keputusan user):
     * invoice yang sudah terbit tetap memakai harga paket lama, harga
     * baru mulai berlaku pada invoice berikutnya. Ini pilihan paling
     * aman — angka yang sudah tercatat (dan mungkin sudah ditagihkan ke
     * pelanggan) tidak berubah di belakang layar.
     */
    protected function terapkanGantiPaket(string $chatId, int $messageId, int $customerId, int $packageId, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);
        $paketBaru = ServicePackage::find($packageId);

        if (! $pelanggan || ! $paketBaru) {
            $this->balas($chatId, $messageId, 'Pelanggan atau paket tidak ditemukan.', null, $telegram);

            return;
        }

        $paketLama = $pelanggan->servicePackage;
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        try {
            $pelanggan->update(['service_package_id' => $paketBaru->id]);

            // Provision ulang WAJIB: tanpa ini, data di aplikasi sudah
            // paket baru sementara RADIUS masih memakai batas kecepatan
            // lama — persis jenis ketidaksesuaian yang dulu bikin
            // pelanggan bermasalah dan susah dilacak.
            $radius = app(CustomerRadiusService::class);
            $radius->provisionCustomer($pelanggan, $paketBaru);

            // Sinkronkan juga status blokirnya, karena provisionCustomer
            // menulis ulang baris radcheck dari nol.
            if ($pelanggan->status === CustomerStatus::Active) {
                $radius->reactivate($pelanggan);
            } else {
                $radius->suspend($pelanggan);
            }
        } catch (\Throwable $e) {
            Log::error('Gagal ganti paket lewat Telegram', [
                'customer_id' => $pelanggan->id,
                'error' => $e->getMessage(),
            ]);

            $this->balas($chatId, $messageId, '❌ Gagal mengganti paket: '.$e->getMessage(), null, $telegram);

            return;
        }

        $teks = "✅ <b>Paket berhasil diganti</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= 'Paket lama: '.($paketLama?->name ?? '-')."\n";
        $teks .= "Paket baru: <b>{$paketBaru->name}</b> — {$rp($paketBaru->sale_price)}\n\n";
        $teks .= "<i>Tagihan yang sudah terbit tidak diubah. Harga baru berlaku mulai tagihan berikutnya.</i>\n";
        $teks .= "<i>Pelanggan perlu menyambung ulang (reconnect) supaya kecepatan barunya terpakai.</i>";

        $this->balas($chatId, $messageId, $teks, null, $telegram);
    }

    // ================================================================
    // 2) OUTLET YANG BELUM SETOR BULAN INI
    // ================================================================

    /**
     * "Belum bayar" untuk outlet = BELUM ADA setoran ke kasir sama
     * sekali pada bulan berjalan.
     *
     * Definisi ini sengaja disamakan dengan kartu "Mitra Sudah Setor" di
     * halaman Kasir (CashierOverview) — supaya angka di bot tidak pernah
     * berselisih dengan angka di aplikasi. Kalau di sini dipakai rumus
     * lain (mis. penjualan dikurangi setoran), dua tempat akan memberi
     * jawaban berbeda untuk pertanyaan yang sama.
     */
    protected function showOutletBelumSetor(string $chatId, TelegramService $telegram): void
    {
        $awal = now()->startOfMonth();
        $akhir = now()->endOfMonth();

        try {
            $sudahSetor = \App\Models\CashierDeposit::whereBetween('deposit_date', [$awal, $akhir])
                ->pluck('outlet_id')
                ->filter()
                ->unique();

            $outlets = Outlet::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'partner_type']);
        } catch (\Throwable $e) {
            Log::error('Gagal baca data setoran untuk /outletnunggak: '.$e->getMessage());
            $telegram->sendMessage($chatId, 'Gagal membaca data setoran. Coba lagi sebentar lagi.');

            return;
        }

        $belum = $outlets->reject(fn ($o) => $sudahSetor->contains($o->id))->values();

        $teks = "🏪 <b>Outlet Belum Setor — ".now()->translatedFormat('F Y')."</b>\n\n";
        $teks .= "Mitra aktif: <b>{$outlets->count()}</b>\n";
        $teks .= "Sudah setor: <b>".($outlets->count() - $belum->count())."</b>\n";
        $teks .= "Belum setor: <b>{$belum->count()}</b>\n";

        if ($belum->isEmpty()) {
            $teks .= "\n✅ Semua mitra aktif sudah menyetor bulan ini.";
            $telegram->sendMessage($chatId, $teks);

            return;
        }

        $teks .= "\n<b>Daftar yang belum setor</b>\n";

        foreach ($belum->take(40) as $o) {
            $jenis = $o->partner_type instanceof \BackedEnum ? $o->partner_type->value : (string) $o->partner_type;
            $label = $jenis && $jenis !== 'outlet' ? ' ('.ucfirst($jenis).')' : '';
            $teks .= "   • {$o->name}{$label}\n";
        }

        if ($belum->count() > 40) {
            $teks .= "\n<i>...dan ".($belum->count() - 40)." lagi (dibatasi 40 supaya pesan tidak kepanjangan).</i>";
        }

        $telegram->sendMessage($chatId, $teks);
    }

    // ================================================================
    // 4) PELANGGAN YANG TAGIHANNYA BELUM LUNAS
    // ================================================================

    protected function showPelangganBelumBayar(string $chatId, TelegramService $telegram): void
    {
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        try {
            // Dikelompokkan per PELANGGAN, bukan per invoice: satu
            // pelanggan bisa menunggak beberapa bulan sekaligus, dan
            // yang perlu ditagih tetap orangnya, bukan lembar-lembarnya.
            $tunggakan = \App\Models\Invoice::query()
                ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
                ->selectRaw('customer_id, COUNT(*) as jumlah, SUM(amount - amount_paid) as sisa, MIN(due_date) as jatuh_tempo')
                ->groupBy('customer_id')
                ->orderByDesc('sisa')
                ->limit(30)
                ->get();

            $totalSemua = (float) \App\Models\Invoice::query()
                ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
                ->selectRaw('SUM(amount - amount_paid) as t')
                ->value('t');
        } catch (\Throwable $e) {
            Log::error('Gagal baca tagihan untuk /tagihan: '.$e->getMessage());
            $telegram->sendMessage($chatId, 'Gagal membaca data tagihan. Coba lagi sebentar lagi.');

            return;
        }

        if ($tunggakan->isEmpty()) {
            $telegram->sendMessage($chatId, "💳 <b>Tagihan Belum Lunas</b>\n\n✅ Tidak ada tagihan yang belum lunas.");

            return;
        }

        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('id', $tunggakan->pluck('customer_id'))
            ->pluck('name', 'id');

        $teks = "💳 <b>Tagihan Belum Lunas</b>\n\n";
        $teks .= "Total tunggakan: <b>{$rp($totalSemua)}</b>\n";
        $teks .= "<i>Diurutkan dari nominal terbesar.</i>\n\n";

        foreach ($tunggakan as $t) {
            $nama = $pelanggan[$t->customer_id] ?? 'Pelanggan #'.$t->customer_id;
            $lewat = $t->jatuh_tempo && \Illuminate\Support\Carbon::parse($t->jatuh_tempo)->isPast()
                ? ' ⚠️ lewat tempo'
                : '';
            $teks .= "   • {$nama} — <b>{$rp($t->sisa)}</b> ({$t->jumlah} tagihan){$lewat}\n";
        }

        $teks .= "\nUntuk mencatat pembayaran: <code>/bayar NAMA</code>";

        $telegram->sendMessage($chatId, $teks);
    }

    /**
     * ID semua pelanggan yang jadi tanggungan satu Biller -- gabungan
     * dari DUA jalur penugasan yang ada di menu Biller (BillerResource):
     *   1. "Kelola Pelanggan & Komisi"  -> pelanggan ditugaskan LANGSUNG
     *      satu-satu, lewat relasi billedCustomers() (pivot
     *      biller_customer_commissions).
     *   2. "Kelola Outlet & Komisi"     -> SELURUH pelanggan milik satu
     *      outlet ditugaskan sekaligus, lewat relasi billedOutlets()
     *      (pivot biller_outlet_commissions) -- semua pelanggan yang
     *      outlet_id-nya ada di outlet-outlet itu ikut jadi tanggungan
     *      biller ini.
     * Dipakai supaya "/tagihan" (dan fitur lain yang butuh scope Biller
     * di masa depan) tidak perlu menulis ulang logika gabungan ini.
     */
    protected function idPelangganMilikBiller(Outlet $biller): \Illuminate\Support\Collection
    {
        $dariOutlet = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('outlet_id', $biller->billedOutlets()->pluck('outlets.id'))
            ->pluck('id');

        // PENTING: pelanggan tipe Static IP MEMAKAI kolom `outlet_id`
        // dengan ARTI BEDA -- di StaticIpUserResource aplikasi, field itu
        // dilabeli "Biller (opsional)" dan opsinya cuma daftar Outlet
        // ber-partner_type Biller (lihat form-nya), jadi ISINYA LANGSUNG
        // id Biller itu sendiri, BUKAN id Outlet reseller biasa seperti
        // $dariOutlet di atas. Kalau tidak dicek TERPISAH di sini,
        // pelanggan Static IP yang sudah diberi Biller lewat aplikasi
        // TIDAK PERNAH muncul di pencarian bot manapun (baycust/bayinv/
        // cetakcust dst) milik Biller itu, walau di aplikasi kelihatan
        // sudah "ditugaskan" -- baru ketahuan lewat laporan user.
        $staticIpLangsung = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', CustomerType::StaticIp->value)
            ->where('outlet_id', $biller->id)
            ->pluck('id');

        $langsung = $biller->billedCustomers()->pluck('customers.id');

        return $dariOutlet->merge($staticIpLangsung)->merge($langsung)->unique()->values();
    }

    /**
     * Versi "/tagihan" khusus BILLER -- persis showPelangganBelumBayar()
     * di atas, cuma daftarnya disaring HANYA pelanggan tanggungan biller
     * ini (lihat idPelangganMilikBiller()). Ditulis method terpisah
     * (bukan menambah parameter di showPelangganBelumBayar()) supaya
     * query "total semua" & "per outlet" milik admin tidak ikut
     * bercampur dengan logika scope biller.
     */
    protected function showTagihanUntukBiller(Outlet $biller, string $chatId, TelegramService $telegram): void
    {
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        $idPelanggan = $this->idPelangganMilikBiller($biller);

        if ($idPelanggan->isEmpty()) {
            $telegram->sendMessage($chatId, "💳 <b>Tagihan Belum Lunas</b>\n\nBelum ada pelanggan yang ditugaskan ke biller ini. Atur lewat menu Biller &gt; \"Kelola Outlet &amp; Komisi\" atau \"Kelola Pelanggan &amp; Komisi\" di aplikasi.");

            return;
        }

        try {
            $tunggakan = \App\Models\Invoice::query()
                ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
                ->whereIn('customer_id', $idPelanggan)
                ->selectRaw('customer_id, COUNT(*) as jumlah, SUM(amount - amount_paid) as sisa, MIN(due_date) as jatuh_tempo')
                ->groupBy('customer_id')
                ->orderByDesc('sisa')
                ->limit(30)
                ->get();

            $totalSemua = (float) \App\Models\Invoice::query()
                ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
                ->whereIn('customer_id', $idPelanggan)
                ->selectRaw('SUM(amount - amount_paid) as t')
                ->value('t');
        } catch (\Throwable $e) {
            Log::error('Gagal baca tagihan untuk /tagihan (biller): '.$e->getMessage());
            $telegram->sendMessage($chatId, 'Gagal membaca data tagihan. Coba lagi sebentar lagi.');

            return;
        }

        if ($tunggakan->isEmpty()) {
            $telegram->sendMessage($chatId, "💳 <b>Tagihan Belum Lunas</b>\n\n✅ Tidak ada tagihan yang belum lunas untuk pelanggan Anda.");

            return;
        }

        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('id', $tunggakan->pluck('customer_id'))
            ->pluck('name', 'id');

        $teks = "💳 <b>Tagihan Belum Lunas — {$biller->name}</b>\n\n";
        $teks .= "Total tunggakan: <b>{$rp($totalSemua)}</b>\n";
        $teks .= "<i>Diurutkan dari nominal terbesar.</i>\n\n";

        foreach ($tunggakan as $t) {
            $nama = $pelanggan[$t->customer_id] ?? 'Pelanggan #'.$t->customer_id;
            $lewat = $t->jatuh_tempo && \Illuminate\Support\Carbon::parse($t->jatuh_tempo)->isPast()
                ? ' ⚠️ lewat tempo'
                : '';
            $teks .= "   • {$nama} — <b>{$rp($t->sisa)}</b> ({$t->jumlah} tagihan){$lewat}\n";
        }

        $telegram->sendMessage($chatId, $teks);
    }

    // ================================================================
    // 5) CATAT PEMBAYARAN TAGIHAN PELANGGAN
    // ================================================================

    /**
     * Layar utama /bayar: RINCIAN BULAN yang belum lunas, lalu admin
     * diminta mengetik berapa bulan yang mau dibayar.
     *
     * Sebelumnya layar ini menampilkan tombol per-invoice. Diganti
     * (permintaan user) karena yang dipakai orang di lapangan adalah
     * satuan BULAN, bukan nomor invoice: "bayar sampai Desember" jauh
     * lebih sering diucapkan daripada "lunasi invoice INV-202609-0031".
     */
    protected function showTagihanPelanggan(string $chatId, ?int $messageId, int $customerId, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tidak ditemukan.', null, $telegram);

            return;
        }

        $paket = $pelanggan->servicePackage;

        if (! $paket || (float) $paket->sale_price <= 0) {
            $this->balas($chatId, $messageId, 'Pelanggan ini belum punya paket berbayar, jadi tidak bisa dihitung pembayarannya.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        $belumLunas = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();

        $teks = "💳 <b>{$pelanggan->name}</b>\n";
        $teks .= "<code>{$pelanggan->username}</code>\n";
        $teks .= "Paket: {$paket->name} — {$rp($paket->sale_price)}/bulan\n\n";

        if ($belumLunas->isEmpty()) {
            // Bulan TERAKHIR yang sudah ada tagihannya (biasanya bulan
            // berjalan) -- ditunjukkan eksplisit sebagai "sudah lunas",
            // supaya admin tidak salah kira bot ini mau menagih ulang
            // bulan yang sama. Tanpa baris ini, layar cuma bilang "tidak
            // menunggak" lalu langsung nawarin "ketik jumlah bulan" --
            // ambigu, kelihatan seperti bakal bayar bulan yang SAMA lagi.
            $terakhir = $pelanggan->invoices()->orderByDesc('period_year')->orderByDesc('period_month')->first();

            if ($terakhir) {
                $teks .= "✅ <b>{$terakhir->periodLabel()} sudah lunas.</b>\n";
            } else {
                $teks .= "✅ <b>Tidak ada bulan yang menunggak.</b>\n";
            }

            if ((float) $pelanggan->credit_balance > 0) {
                $teks .= 'Saldo di muka: <b>'.$rp($pelanggan->credit_balance)."</b>\n";
            }

            $bulanDepan = $this->bulanBerikutnyaUntukDitagih($pelanggan);
            $teks .= "\nMau bayar di muka untuk <b>{$bulanDepan->translatedFormat('F Y')}</b> (bulan berikutnya) dan seterusnya?\n";
        } else {
            $teks .= "<b>Bulan yang belum dibayar</b>\n";

            foreach ($belumLunas as $i) {
                $tanda = $i->due_date && $i->due_date->isPast() ? ' ⚠️' : '';
                $teks .= "   • {$i->periodLabel()} — {$rp($i->remainingAmount())}{$tanda}\n";
            }

            $teks .= "\nTotal tunggakan: <b>".$rp($belumLunas->sum(fn ($i) => $i->remainingAmount()))."</b>\n";
        }

        // Contoh konkret memakai bulan yang SEBENARNYA, bukan kalimat
        // umum -- inilah yang paling sering ditanyakan admin, jadi
        // jawabannya disodorkan langsung.
        $sampaiDes = $this->bulanSampaiDesember($pelanggan);

        $teks .= "\n<b>Ketik jumlah bulan yang mau dibayar.</b>\n";

        if ($sampaiDes > 0) {
            $cakupan = $this->bulanTercakup($pelanggan, $sampaiDes);
            $namaBulan = collect($cakupan)->map(fn ($b) => explode(' ', $b['label'])[0])->implode(', ');

            $teks .= "Contoh: ketik <b>{$sampaiDes}</b> untuk lunas sampai Desember ({$namaBulan}).\n";
        }

        $teks .= "\n<i>Ketik /batal untuk membatalkan.</i>";

        // State disimpan 15 menit: cukup panjang untuk mengetik sambil
        // melayani pelanggan, cukup pendek supaya angka yang diketik
        // besok tidak nyasar ke pelanggan yang salah.
        Cache::put($this->bayarFlowKey($chatId), [
            'customer_id' => $pelanggan->id,
        ], now()->addMinutes(15));

        $this->balas($chatId, $messageId, $teks, ['inline_keyboard' => $this->tombolBayarMuka($pelanggan)], $telegram);
    }

    /**
     * Menerima angka yang diketik admin setelah layar /bayar di atas.
     */
    protected function handleBayarFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $angka = (int) preg_replace('/[^0-9]/', '', $text);

        if ($angka < 1 || $angka > 60) {
            $telegram->sendMessage($chatId, "Masukkan ANGKA jumlah bulan, antara 1 sampai 60.\n\nContoh: <code>4</code>\n\nAtau ketik /batal untuk membatalkan.");

            return;
        }

        Cache::forget($this->bayarFlowKey($chatId));

        $this->showKonfirmasiBayarMuka($chatId, null, (int) ($state['customer_id'] ?? 0), $angka, $telegram);
    }

    /**
     * Layar konfirmasi sebelum uang benar-benar dicatat.
     *
     * PENTING soal cara hitungnya: InvoiceService::recordPayment()
     * mengalokasikan uang dari tagihan PALING LAMA lebih dulu — itu
     * aturan yang sudah dipakai seluruh aplikasi dan sengaja TIDAK
     * dilawan di sini. Jadi kalau admin memilih tagihan bulan Maret
     * sementara Januari & Februari masih menunggak, yang dicatat adalah
     * pelunasan Januari + Februari + Maret sekaligus, dan nominalnya
     * ditampilkan apa adanya di layar ini sebelum ditekan.
     *
     * Kalau ini tidak dilakukan, admin akan mengira membayar satu bulan
     * tapi uangnya jatuh ke bulan lain — beda antara yang dilihat dan
     * yang tercatat, persis jenis kebingungan yang paling mahal di
     * urusan uang.
     */
    protected function showKonfirmasiBayar(string $chatId, int $messageId, int $invoiceId, TelegramService $telegram): void
    {
        $invoice = \App\Models\Invoice::find($invoiceId);

        if (! $invoice) {
            $this->balas($chatId, $messageId, 'Tagihan tidak ditemukan.', null, $telegram);

            return;
        }

        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($invoice->customer_id);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tagihan ini tidak ditemukan.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $cakupan = $this->tagihanSampaiDengan($pelanggan, $invoice);
        $total = $cakupan->sum(fn ($i) => $i->remainingAmount());

        $teks = "💰 <b>Konfirmasi Pembayaran</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Dipilih: {$invoice->periodLabel()}\n\n";

        if ($cakupan->count() > 1) {
            $teks .= "⚠️ Ada tagihan lebih lama yang belum lunas. Pembayaran selalu melunasi dari yang paling lama, jadi yang akan dilunasi:\n";
        } else {
            $teks .= "Yang akan dilunasi:\n";
        }

        foreach ($cakupan as $i) {
            $teks .= "   • {$i->periodLabel()} — {$rp($i->remainingAmount())}\n";
        }

        $teks .= "\nTotal dibayar: <b>{$rp($total)}</b>\nMetode: Tunai\n\nLanjutkan?";

        $this->balas($chatId, $messageId, $teks, ['inline_keyboard' => [[
            ['text' => '✅ Ya, catat lunas', 'callback_data' => "bayok:{$invoice->id}"],
        ]]], $telegram);
    }

    /**
     * Semua tagihan belum lunas milik pelanggan ini, dari yang paling
     * lama SAMPAI DENGAN invoice yang dipilih. Urutannya harus sama
     * dengan urutan alokasi di InvoiceService::recordPayment()
     * (period_year lalu period_month menaik).
     */
    protected function tagihanSampaiDengan(Customer $pelanggan, \App\Models\Invoice $invoice): \Illuminate\Support\Collection
    {
        return $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get()
            ->filter(fn ($i) => ($i->period_year * 100 + $i->period_month)
                <= ($invoice->period_year * 100 + $invoice->period_month))
            ->values();
    }

    protected function terapkanPembayaran(string $chatId, int $messageId, int $invoiceId, TelegramService $telegram): void
    {
        $invoice = \App\Models\Invoice::find($invoiceId);

        if (! $invoice) {
            $this->balas($chatId, $messageId, 'Tagihan tidak ditemukan.', null, $telegram);

            return;
        }

        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($invoice->customer_id);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tagihan ini tidak ditemukan.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        // Direkam DULU, SEBELUM recordPayment() -- setelah pembayaran
        // tercatat, status invoice-invoice ini berubah jadi Paid dan
        // tidak akan lolos filter Unpaid/Overdue lagi kalau dihitung
        // ULANG belakangan (itu sebabnya rinciannya diambil di sini,
        // dipakai lagi nanti buat isi struk PDF).
        $cakupanInvoice = $this->tagihanSampaiDengan($pelanggan, $invoice);
        $total = (float) $cakupanInvoice->sum(fn ($i) => $i->remainingAmount());

        if ($total <= 0) {
            $this->balas($chatId, $messageId, 'Tagihan ini sudah lunas.', null, $telegram);

            return;
        }

        try {
            // Memakai InvoiceService yang SAMA dengan halaman aplikasi —
            // bukan menulis sendiri ke tabel invoices. Dengan begitu
            // PaymentLog ikut tercatat (jadi bisa dibatalkan lewat menu
            // Riwayat Pembayaran kalau salah), dan pelanggan yang tadinya
            // isolir otomatis diaktifkan kembali beserta RADIUS-nya.
            $hasil = app(\App\Services\InvoiceService::class)->recordPayment(
                $pelanggan,
                $total,
                \App\Enums\PaymentMethod::Tunai->value,
                'Dicatat lewat bot Telegram oleh admin'
            );
        } catch (\Throwable $e) {
            Log::error('Gagal catat pembayaran lewat Telegram', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            $this->balas($chatId, $messageId, '❌ Gagal mencatat pembayaran: '.$e->getMessage(), null, $telegram);

            return;
        }

        $jumlahLunas = count($hasil['invoices_paid'] ?? []);
        $sisaTagihan = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->get()
            ->sum(fn ($i) => $i->remainingAmount());

        $teks = "✅ <b>Pembayaran tercatat</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Dibayar: <b>{$rp($total)}</b>\n";
        $teks .= "Tagihan lunas: {$jumlahLunas}\n";

        if (($hasil['credit_added'] ?? 0) > 0) {
            $teks .= 'Saldo lebih bayar: '.$rp($hasil['credit_added'])."\n";
        }

        $teks .= 'Sisa tagihan: <b>'.$rp($sisaTagihan)."</b>\n";
        $teks .= "\n<i>Tercatat sebagai Tunai. Kalau salah, batalkan lewat menu Riwayat Pembayaran di aplikasi.</i>";

        $this->balas($chatId, $messageId, $teks, null, $telegram);

        // BARU (permintaan user): kirim juga invoice/struk pembayaran
        // SIAP CETAK di printer POS 58mm, format PDF -- dikirim SETELAH
        // balas() supaya urutan pesan di chat masuk akal (ringkasan teks
        // dulu, baru file PDF-nya).
        $items = $cakupanInvoice
            ->map(fn ($i) => [
                'label' => \Carbon\Carbon::create()->month($i->period_month)->translatedFormat('M').' '.$i->period_year,
                'jumlah' => (float) $i->remainingAmount(),
            ])
            ->all();

        $this->kirimStrukPembayaranBot($chatId, $pelanggan, $items, $total, $telegram, $sisaTagihan);
    }

    /**
     * Kunci Cache untuk alur "/bayar" yang sedang menunggu admin
     * mengetik JUMLAH BULAN. Dibubuhi subdomain tenant, alasannya sama
     * dengan pppoeFlowKey().
     */
    protected function bayarFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_bayar_flow:{$namespace}:{$chatId}";
    }

    /**
     * Daftar BULAN yang akan tertutup kalau membayar $n bulan.
     *
     * Ini inti perhitungan pembayaran, dan sengaja SATU fungsi yang
     * dipakai layar daftar, layar konfirmasi, dan pencatatannya
     * sekaligus. Kalau dihitung terpisah di tiga tempat, cepat atau
     * lambat ketiganya berbeda dan yang tertulis di layar tidak sama
     * dengan yang tercatat di pembukuan.
     *
     * Titik mulainya BUKAN selalu bulan ini: kalau ada tunggakan, mulai
     * dari tagihan tertunggak PALING LAMA. Itu mengikuti aturan
     * InvoiceService yang melunasi dari yang terlama dulu, jadi daftar
     * bulan di layar benar-benar menggambarkan ke mana uangnya pergi.
     *
     * Bulan yang SUDAH punya tagihan memakai sisa tagihannya (bisa lebih
     * kecil kalau sudah dibayar sebagian); bulan yang tagihannya belum
     * terbit memakai harga paket.
     *
     * @return array<int, array{label: string, jumlah: float, adaInvoice: bool, jenis: string}>
     */
    protected function bulanTercakup(Customer $pelanggan, int $n): array
    {
        $paket = $pelanggan->servicePackage;
        $harga = (float) ($paket->sale_price ?? 0);

        $belumLunas = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();

        $pertama = $belumLunas->first();

        // PENTING: kalau tidak ada tunggakan sama sekali, titik mulainya
        // BUKAN "bulan ini" (now()->startOfMonth()) -- bulan ini biasanya
        // SUDAH punya invoice yang lunas. Pakai now() di sini akan membuat
        // bulan yang sudah lunas itu dihitung ulang seolah belum ditagih
        // (harga penuh, dianggap belum ada invoice), sehingga kelihatan
        // seperti "bayar lagi untuk bulan yang sama". Mulai dari bulan
        // SETELAH invoice terakhir (apa pun statusnya) supaya tidak
        // tumpang tindih dengan bulan yang sudah tercatat.
        $mulai = $pertama
            ? \Illuminate\Support\Carbon::createFromDate($pertama->period_year, $pertama->period_month, 1)
            : $this->bulanBerikutnyaUntukDitagih($pelanggan);

        // Tagihan dipetakan per "YYYYMM" supaya pencarian per bulan tidak
        // perlu menyisir ulang koleksinya di dalam perulangan.
        $petaInvoice = $belumLunas->keyBy(fn ($i) => $i->period_year * 100 + $i->period_month);

        $hasil = [];
        $bulanIni = now()->startOfMonth();
        // Bulan berjalan yang belum punya invoice DIBUATKAN invoice oleh
        // InvoiceService::recordPayment() lalu dilunasi -- jadi bukan "di
        // muka". Kalau Tagihan Otomatis dimatikan, perilaku lama berlaku
        // (masuk saldo), maka tetap ditandai di muka.
        $tagihanOtomatis = \App\Models\Setting::get('billing_auto_generate', '1') === '1';

        for ($i = 0; $i < $n; $i++) {
            $bulan = $mulai->copy()->addMonths($i);
            $kunci = $bulan->year * 100 + $bulan->month;
            $invoice = $petaInvoice[$kunci] ?? null;

            $hasil[] = [
                'label' => $bulan->translatedFormat('F Y'),
                'jumlah' => $invoice ? (float) $invoice->remainingAmount() : $harga,
                'adaInvoice' => (bool) $invoice,
                // berjalan: invoice-nya dibuat saat dibayar | dimuka: bulan depan
                // (atau Tagihan Otomatis mati) | lampau: bulan lewat tanpa invoice
                'jenis' => $invoice ? 'ada'
                    : (! $tagihanOtomatis || $bulan->greaterThan($bulanIni) ? 'dimuka'
                    : ($bulan->equalTo($bulanIni) ? 'berjalan' : 'lampau')),
            ];
        }

        return $hasil;
    }

    /**
     * Berapa bulan dari titik mulai pembayaran sampai Desember tahun ini.
     * Dipakai sebagai contoh di petunjuk — "sampai akhir tahun berarti
     * berapa bulan?" itu yang paling sering ditanyakan, jadi angkanya
     * langsung disodorkan, bukan disuruh hitung sendiri.
     */
    protected function bulanSampaiDesember(Customer $pelanggan): int
    {
        $pertama = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->first();

        $mulai = $pertama
            ? \Illuminate\Support\Carbon::createFromDate($pertama->period_year, $pertama->period_month, 1)
            : $this->bulanBerikutnyaUntukDitagih($pelanggan);

        $desember = now()->copy()->month(12)->startOfMonth();

        if ($mulai->greaterThan($desember)) {
            return 0;
        }

        return $mulai->diffInMonths($desember) + 1;
    }

    /**
     * Bulan pertama yang BELUM punya invoice sama sekali -- dipakai
     * sebagai titik mulai perhitungan pembayaran di muka ketika pelanggan
     * TIDAK punya tunggakan (semua invoice yang ada berstatus Paid).
     *
     * Sengaja dihitung dari invoice TERAKHIR (apa pun statusnya), BUKAN
     * dari now()->startOfMonth() -- kalau invoice bulan berjalan sudah
     * dibuat & lunas, now()->startOfMonth() akan menunjuk ke bulan yang
     * sama itu lagi, sehingga bulanTercakup() mengira bulan itu belum
     * ditagih (karena petanya cuma berisi invoice Unpaid/Overdue) dan
     * menampilkannya seolah perlu dibayar lagi.
     */
    protected function bulanBerikutnyaUntukDitagih(Customer $pelanggan): \Illuminate\Support\Carbon
    {
        $terakhir = $pelanggan->invoices()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        if (! $terakhir) {
            return now()->startOfMonth();
        }

        return \Illuminate\Support\Carbon::createFromDate($terakhir->period_year, $terakhir->period_month, 1)->addMonth();
    }

    /**
     * Tombol pintasan jumlah bulan. Mengetik angka tetap bisa; tombol
     * ini cuma memangkas langkah untuk jumlah yang paling sering dipakai.
     */
    protected function tombolBayarMuka(Customer $pelanggan): array
    {
        $sampaiDes = $this->bulanSampaiDesember($pelanggan);

        $pilihan = [1 => '1 bulan', 3 => '3 bulan', 6 => '6 bulan', 12 => '12 bulan'];

        // "Sampai Desember" cuma ditawarkan kalau memang masih ada sisa
        // bulan DAN jumlahnya belum sama dengan pilihan di atas -- dua
        // tombol yang hasilnya identik cuma bikin ragu.
        if ($sampaiDes > 0 && ! isset($pilihan[$sampaiDes])) {
            $pilihan[$sampaiDes] = 's/d Des ('.$sampaiDes.' bln)';
        }

        ksort($pilihan);

        $baris = [];

        foreach (array_chunk($pilihan, 2, true) as $kelompok) {
            $baris[] = array_map(
                fn ($label, $bulan) => [
                    'text' => '📅 '.$label,
                    'callback_data' => "bmuka:{$pelanggan->id}:{$bulan}",
                ],
                $kelompok,
                array_keys($kelompok)
            );
        }

        return $baris;
    }

    /**
     * Layar konfirmasi pembayaran, dengan RINCIAN NAMA BULAN.
     *
     * $messageId boleh null (dipanggil setelah admin mengetik angka,
     * bukan menekan tombol).
     *
     * Bulan yang disebut di sini persis bulan yang akan tercatat lunas,
     * bukan perkiraan -- angkanya dari bulanTercakup() yang juga dipakai
     * saat pencatatan.
     */
    protected function showKonfirmasiBayarMuka(string $chatId, ?int $messageId, int $customerId, int $bulan, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan || $bulan < 1) {
            $this->balas($chatId, $messageId, 'Data tidak lengkap. Ketik /bayar NAMA lagi.', null, $telegram);

            return;
        }

        $paket = $pelanggan->servicePackage;

        if (! $paket || (float) $paket->sale_price <= 0) {
            $this->balas($chatId, $messageId, 'Pelanggan ini belum punya paket berbayar.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $cakupan = $this->bulanTercakup($pelanggan, $bulan);
        $total = array_sum(array_column($cakupan, 'jumlah'));

        $teks = "💰 <b>Konfirmasi Pembayaran</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Dibayar: <b>{$bulan} bulan</b>\n\n";
        $teks .= "<b>Bulan yang dilunasi</b>\n";

        foreach ($cakupan as $b) {
            // Ditandai supaya jelas mana yang menutup tunggakan dan mana
            // yang dibayar di muka -- dua hal yang artinya sangat berbeda
            // bagi pelanggan maupun pembukuan.
            $tanda = match ($b['jenis']) {
                'dimuka' => ' <i>(di muka)</i>',
                'berjalan' => ' <i>(tagihan bulan ini)</i>',
                'lampau' => ' <i>(belum ada tagihan)</i>',
                default => '',
            };
            $teks .= "   • {$b['label']} — {$rp($b['jumlah'])}{$tanda}\n";
        }

        $teks .= "\nTotal dibayar: <b>{$rp($total)}</b>\nMetode: Tunai\n\nLanjutkan?";

        $this->balas($chatId, $messageId, $teks, ['inline_keyboard' => [[
            ['text' => '✅ Ya, catat lunas', 'callback_data' => "bmukaok:{$pelanggan->id}:{$bulan}"],
        ]]], $telegram);
    }

    protected function terapkanBayarMuka(string $chatId, int $messageId, int $customerId, int $bulan, TelegramService $telegram): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan || $bulan < 1) {
            $this->balas($chatId, $messageId, 'Data tidak lengkap.', null, $telegram);

            return;
        }

        $paket = $pelanggan->servicePackage;

        if (! $paket || (float) $paket->sale_price <= 0) {
            $this->balas($chatId, $messageId, 'Pelanggan ini belum punya paket berbayar.', null, $telegram);

            return;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        // Dihitung ULANG di sini, tidak dititipkan lewat tombol: antara
        // layar konfirmasi dan penekanan tombol bisa saja ada pembayaran
        // lain masuk. Yang dicatat harus jumlah yang benar SAAT ditekan.
        $cakupan = $this->bulanTercakup($pelanggan, $bulan);
        $total = (float) array_sum(array_column($cakupan, 'jumlah'));

        if ($total <= 0) {
            $this->balas($chatId, $messageId, 'Tidak ada yang perlu dibayar.', null, $telegram);

            return;
        }

        $rentang = $cakupan[0]['label'].' s/d '.$cakupan[count($cakupan) - 1]['label'];

        try {
            // Lewat InvoiceService yang SAMA dengan halaman aplikasi:
            // tagihan yang ada dilunasi dari yang terlama, sisanya masuk
            // credit_balance dan dipakai otomatis saat tagihan bulan
            // berikutnya dibuat. Semuanya tercatat di PaymentLog sehingga
            // bisa dibatalkan lewat aplikasi kalau salah input.
            $hasil = app(\App\Services\InvoiceService::class)->recordPayment(
                $pelanggan,
                $total,
                \App\Enums\PaymentMethod::Tunai->value,
                "Bayar {$bulan} bulan ({$rentang}) lewat bot Telegram"
            );
        } catch (\Throwable $e) {
            Log::error('Gagal catat pembayaran lewat Telegram', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            $this->balas($chatId, $messageId, '❌ Gagal mencatat pembayaran: '.$e->getMessage(), null, $telegram);

            return;
        }

        $pelanggan->refresh();
        $saldo = (float) $pelanggan->credit_balance;

        $sisaTagihan = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->get()
            ->sum(fn ($i) => $i->remainingAmount());

        $teks = "✅ <b>Pembayaran tercatat</b>\n\n";
        $teks .= "Pelanggan: <b>{$pelanggan->name}</b> (<code>{$pelanggan->username}</code>)\n";
        $teks .= "Dibayar: <b>{$rp($total)}</b>\n";
        $teks .= "Untuk: {$rentang} ({$bulan} bulan)\n";
        $teks .= 'Tagihan lunas: '.count($hasil['invoices_paid'] ?? [])."\n";

        if ($saldo > 0) {
            $teks .= "Saldo di muka: <b>{$rp($saldo)}</b>\n";
        }

        $teks .= 'Sisa tunggakan: <b>'.$rp($sisaTagihan)."</b>\n";
        $teks .= "\n<i>Saldo di muka dipakai otomatis saat tagihan bulan berikutnya dibuat. Kalau salah, batalkan lewat menu Riwayat Pembayaran di aplikasi.</i>";

        $this->balas($chatId, $messageId, $teks, null, $telegram);

        // BARU (permintaan user): struk/invoice siap cetak printer POS
        // 58mm, format PDF -- $cakupan SUDAH DIHITUNG di atas SEBELUM
        // recordPayment() (lihat showKonfirmasiBayarMuka() & komentar
        // sama di terapkanPembayaran()), jadi label & jumlah per
        // bulannya masih benar dipakai di sini.
        $items = array_map(fn ($b) => [
            'label' => $b['label'],
            'jumlah' => $b['jumlah'],
        ], $cakupan);

        $this->kirimStrukPembayaranBot($chatId, $pelanggan, $items, $total, $telegram, $sisaTagihan, $saldo);
    }

    protected function isAdminChat(string $chatId): bool
    {
        if ($chatId === '') {
            return false;
        }

        $raw = (string) (\App\Models\Setting::get('telegram_admin_chat_id') ?? '');

        if (trim($raw) === '') {
            return false;
        }

        $daftarId = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($daftarId as $id) {
            if (hash_equals(trim($id), $chatId)) {
                return true;
            }
        }

        return false;
    }

    // ================================================================
    // BARU: /addstaticip -- tambah pelanggan Static IP lewat bot.
    //
    // BEDA PENTING dari /pppoe & /addmember: alur ini TIDAK punya
    // langkah pilih "Outlet" sama sekali. Ini BUKAN kelalaian --
    // pelanggan Static IP di aplikasi (lihat StaticIpUserResource)
    // memang TIDAK punya konsep "Outlet reseller" seperti PPPoE/Member.
    // Kolom `outlet_id` di tabel customers untuk tipe INI dipakai ULANG
    // buat menyimpan BILLER (opsional) secara LANGSUNG -- lihat field
    // "Biller (opsional)" di form aplikasi yang opsinya cuma Outlet
    // ber-partner_type Biller. Makanya di sini alurnya: data pelanggan
    // dulu -> paket -> baru di langkah TERAKHIR pilih Biller (opsional),
    // dan hasilnya ditulis LANGSUNG ke `outlet_id` (lihat
    // finalizeStaticIpCustomer()), BUKAN lewat pivot
    // biller_customer_commissions seperti dua alur lainnya.
    // ================================================================

    protected function staticIpFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_staticip_flow:{$namespace}:{$chatId}";
    }

    protected function startStaticIpFlow(string $chatId, TelegramService $telegram): void
    {
        Cache::put($this->staticIpFlowKey($chatId), [
            'step' => 'nama',
            'data' => [],
        ], now()->addMinutes(20));

        $telegram->sendMessage($chatId,
            "🔌 <b>Tambah Pelanggan Static IP</b>\n\n".
            'Ketik <b>Nama</b> pelanggan (kapan saja ketik /batal buat membatalkan):'
        );
    }

    protected function showStaticIpPackageMenu(string $chatId, TelegramService $telegram): void
    {
        // TIDAK disaring per outlet (lihat catatan di atas kenapa) --
        // sama seperti dropdown Paket di StaticIpUserResource aplikasi,
        // yang menampilkan SEMUA paket static_ip aktif tanpa kecuali.
        $packages = ServicePackage::where('is_active', true)
            ->where('type', 'static_ip')
            ->orderBy('name')
            ->get();

        if ($packages->isEmpty()) {
            Cache::forget($this->staticIpFlowKey($chatId));
            $telegram->sendMessage($chatId, 'Belum ada paket Static IP aktif. Alur dibatalkan -- hubungi admin dulu buat siapkan paketnya (menu Profile Static IP).');

            return;
        }

        $buttons = $packages->map(fn ($p) => [[
            'text' => $p->name,
            'callback_data' => "sippkg:{$p->id}",
        ]])->toArray();

        $telegram->sendMessage($chatId, 'Pilih Paket Static IP:', ['inline_keyboard' => $buttons]);
    }

    protected function handleStaticIpFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $step = $state['step'] ?? null;

        switch ($step) {
            case 'nama':
                if ($text === '') {
                    $telegram->sendMessage($chatId, 'Nama tidak boleh kosong. Ketik Nama pelanggan:');

                    return;
                }

                $state['data']['name'] = $text;
                $state['step'] = 'alamat';
                Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Alamat pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'alamat':
                $state['data']['address'] = $text === '-' ? null : $text;
                $state['step'] = 'no_hp';
                Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik No HP pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'no_hp':
                $state['data']['phone'] = $text === '-' ? null : $text;
                $state['step'] = 'mac';
                Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik <b>MAC Address</b> perangkat pelanggan (mis. AA:BB:CC:DD:EE:FF):');

                return;

            case 'mac':
                $mac = strtoupper(trim($text));

                if ($mac === '') {
                    $telegram->sendMessage($chatId, 'MAC Address tidak boleh kosong. Ketik lagi:');

                    return;
                }

                $sudahAda = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->where('mac_address', $mac)
                    ->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "MAC Address <code>{$mac}</code> sudah dipakai pelanggan lain. Ketik MAC lain:");

                    return;
                }

                $state['data']['mac_address'] = $mac;
                $state['step'] = 'ip';
                Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik <b>IP Address</b> tetap buat pelanggan ini (mis. 192.168.10.50):');

                return;

            case 'ip':
                $ip = trim($text);

                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    $telegram->sendMessage($chatId, 'IP Address tidak valid. Ketik lagi (mis. 192.168.10.50):');

                    return;
                }

                $sudahAda = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->where('static_ip', $ip)
                    ->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "IP <code>{$ip}</code> sudah dipakai pelanggan lain. Ketik IP lain:");

                    return;
                }

                $state['data']['static_ip'] = $ip;
                $state['step'] = 'paket';
                Cache::put($this->staticIpFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showStaticIpPackageMenu($chatId, $telegram);

                return;

            case 'paket':
                $telegram->sendMessage($chatId, 'Pilih paketnya lewat tombol di atas ya, bukan diketik manual.');

                return;

            case 'biller':
                $telegram->sendMessage($chatId, 'Pilih billernya lewat tombol di atas ya, bukan diketik manual.');

                return;

            default:
                Cache::forget($this->staticIpFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Sesi tambah pelanggan Static IP sudah tidak berlaku. Ketik /addstaticip lagi.');
        }
    }

    /**
     * SENGAJA REUSE persis cara aplikasi (StaticIpUserResource) bikin
     * pelanggan Static IP: Customer::create() lalu
     * app(CustomerRadiusService::class)->provisionCustomer() -- method
     * itu SENDIRI yang bercabang ke MikrotikService untuk tipe Static
     * IP (lihat komentar panjang di CustomerRadiusService::provisionCustomer()),
     * jadi tidak perlu ditulis ulang di sini.
     *
     * `outlet_id` di sini diisi dari Biller yang dipilih di langkah
     * 'sipbill:' (atau NULL kalau "Tanpa biller") -- BUKAN outlet
     * reseller, lihat catatan panjang di startStaticIpFlow().
     */
    protected function finalizeStaticIpCustomer(string $chatId, array $state, TelegramService $telegram): void
    {
        $paket = ServicePackage::find($state['data']['service_package_id'] ?? null);

        if (! $paket) {
            $telegram->sendMessage($chatId, 'Gagal: paket sudah tidak ada lagi. Ketik /addstaticip lagi dari awal.');

            return;
        }

        $data = $state['data'];
        $biller = ! empty($data['outlet_id']) ? Outlet::find($data['outlet_id']) : null;

        // Username/password MURNI identitas internal (tipe ini TIDAK
        // login pakai keduanya) -- dibuat otomatis dari nama, pola SAMA
        // PERSIS dengan StaticIpUserResource::generateUsername() di
        // aplikasi supaya konsisten.
        $dasar = \Illuminate\Support\Str::slug($data['name']) ?: 'pelanggan';

        do {
            $username = $dasar.'-'.random_int(1000, 9999);
        } while (Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->exists());

        try {
            $customer = Customer::create([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'type' => CustomerType::StaticIp->value,
                'username' => $username,
                'password' => \Illuminate\Support\Str::random(12),
                'mac_address' => $data['mac_address'],
                'static_ip' => $data['static_ip'],
                'service_package_id' => $paket->id,
                'outlet_id' => $biller?->id,
                'status' => CustomerStatus::Active->value,
                'billing_day' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal membuat pelanggan Static IP lewat bot Telegram', [
                'mac_address' => $data['mac_address'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat pelanggan: '.$e->getMessage());

            return;
        }

        try {
            app(CustomerRadiusService::class)->provisionCustomer($customer, $paket);
        } catch (\Throwable $e) {
            Log::warning('Pelanggan Static IP dibuat tapi gagal provisioning lewat bot Telegram', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, "⚠️ Pelanggan <b>{$customer->name}</b> berhasil dibuat, TAPI gagal disinkron ke MikroTik. Cek & sinkron manual lewat aplikasi (menu User Static IP).");

            return;
        }

        $telegram->sendMessage($chatId,
            "✅ <b>Pelanggan Static IP berhasil dibuat</b>\n\n".
            "Nama: {$customer->name}\n".
            'Alamat: '.($customer->address ?: '-')."\n".
            'No HP: '.($customer->phone ?: '-')."\n".
            "Paket: {$paket->name}\n".
            "MAC Address: <code>{$customer->mac_address}</code>\n".
            "IP Address: <code>{$customer->static_ip}</code>\n".
            'Biller: '.($biller?->name ?? 'Tanpa biller')
        );
    }

    // ================================================================
    // BARU: /addmember -- tambah Member Hotspot (langganan bulanan,
    // lihat HotspotMemberResource di aplikasi) lewat bot. Pola alurnya
    // SAMA PERSIS dengan /pppoe, termasuk langkah pilih Biller
    // (opsional) di akhir -- BEDA dari /addstaticip: di sini `outlet_id`
    // TETAP berarti Outlet reseller biasa (HotspotMemberResource
    // aplikasi melabelinya "Outlet (opsional)", bukan "Biller"), jadi
    // Biller yang dipilih ditugaskan lewat pivot
    // biller_customer_commissions seperti /pppoe -- BUKAN ditulis ke
    // `outlet_id` seperti Static IP.
    // ================================================================

    protected function memberFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_member_flow:{$namespace}:{$chatId}";
    }

    protected function showOutletPickerForMember(string $chatId, TelegramService $telegram): void
    {
        $outlets = Outlet::orderBy('name')->limit(200)->get(['id', 'name']);

        if ($outlets->isEmpty()) {
            $telegram->sendMessage($chatId, 'Belum ada outlet terdaftar.');

            return;
        }

        $buttons = [];

        foreach ($outlets->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($o) => [
                'text' => $o->name,
                'callback_data' => "membout:{$o->id}",
            ])->values()->all();
        }

        $telegram->sendMessage($chatId, 'Pilih outlet pemilik Member ini:', ['inline_keyboard' => $buttons]);
    }

    protected function startMemberFlow(string $chatId, Outlet $outlet, TelegramService $telegram, ?int $messageId = null): void
    {
        Cache::put($this->memberFlowKey($chatId), [
            'outlet_id' => $outlet->id,
            'step' => 'nama',
            'data' => [],
        ], now()->addMinutes(20));

        $teks = "👤 <b>Tambah Member Hotspot</b>\nOutlet: {$outlet->name}\n\n".
            "Ketik <b>Nama</b> pelanggan (kapan saja ketik /batal buat membatalkan):";

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teks);
        } else {
            $telegram->sendMessage($chatId, $teks);
        }
    }

    /**
     * Profile/ServicePackage yang ditawarkan waktu /addmember -- KHUSUS
     * yang ditandai "Khusus Member" (is_member_only = true, lihat
     * migration add_is_member_only_to_service_packages_table). Paket
     * "Voucher" biasa (is_member_only = false, dipakai buat generate
     * voucher hotspot) SENGAJA TIDAK ikut ditawarkan di sini (permintaan
     * user) -- Member itu langganan BULANAN, beda siklus dari Voucher
     * yang sekali pakai/habis kuota, jadi tidak boleh ketuker paketnya.
     */
    protected function showMemberPackageMenu(string $chatId, int $outletId, TelegramService $telegram): void
    {
        $packages = ServicePackage::where('is_active', true)
            ->where('is_member_only', true)
            ->whereIn('type', ['hotspot', 'both'])
            ->get()
            ->filter(fn ($p) => $p->isAvailableForOutlet($outletId))
            ->values();

        if ($packages->isEmpty()) {
            Cache::forget($this->memberFlowKey($chatId));
            $telegram->sendMessage($chatId, "Belum ada Profile khusus Member yang aktif buat outlet ini.\n\nAlur dibatalkan -- minta admin tandai \"Khusus Member\" di Profile yang sesuai lewat menu Hotspot > Profile dulu (bukan Profile Voucher biasa).");

            return;
        }

        $buttons = $packages->map(fn ($p) => [[
            'text' => $p->name,
            'callback_data' => "membpkg:{$p->id}",
        ]])->toArray();

        $telegram->sendMessage($chatId, 'Pilih Profile Member:', ['inline_keyboard' => $buttons]);
    }

    protected function handleMemberFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $step = $state['step'] ?? null;

        switch ($step) {
            case 'nama':
                if ($text === '') {
                    $telegram->sendMessage($chatId, 'Nama tidak boleh kosong. Ketik Nama pelanggan:');

                    return;
                }

                $state['data']['name'] = $text;
                $state['step'] = 'alamat';
                Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Alamat pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'alamat':
                $state['data']['address'] = $text === '-' ? null : $text;
                $state['step'] = 'no_hp';
                Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik No HP pelanggan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'no_hp':
                $state['data']['phone'] = $text === '-' ? null : $text;
                $state['step'] = 'paket';
                Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showMemberPackageMenu($chatId, (int) $state['outlet_id'], $telegram);

                return;

            case 'paket':
                $telegram->sendMessage($chatId, 'Pilih paketnya lewat tombol di atas ya, bukan diketik manual.');

                return;

            case 'username':
                $username = trim(str_replace(' ', '', $text));

                if ($username === '') {
                    $telegram->sendMessage($chatId, 'Username tidak boleh kosong. Ketik Username Member:');

                    return;
                }

                $sudahAda = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->where('username', $username)
                    ->exists();

                if ($sudahAda) {
                    $telegram->sendMessage($chatId, "Username <code>{$username}</code> sudah dipakai pelanggan lain. Ketik username lain:");

                    return;
                }

                $state['data']['username'] = $username;
                $state['step'] = 'password';
                Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Password Member (minimal 4 karakter):');

                return;

            case 'password':
                $password = trim($text);

                if (mb_strlen($password) < 4) {
                    $telegram->sendMessage($chatId, 'Password minimal 4 karakter. Ketik lagi:');

                    return;
                }

                $state['data']['password'] = $password;
                // Langkah TERAKHIR: pilih BILLER penagih -- pola SAMA
                // PERSIS dengan /pppoe & /addstaticip (lihat
                // showBillerMenuUntuk()). SEBELUMNYA langkah ini tidak
                // ada sama sekali, jadi Member yang dibuat lewat bot
                // TIDAK PERNAH tertaut ke biller manapun -- itu sebabnya
                // tidak muncul di pencarian /bayar milik Biller (kecuali
                // outletnya sendiri kebetulan sudah ditugaskan penuh ke
                // biller itu lewat "Kelola Outlet & Komisi").
                $state['step'] = 'biller';
                Cache::put($this->memberFlowKey($chatId), $state, now()->addMinutes(20));
                $this->showBillerMenuUntuk($chatId, 'membbill', $telegram);

                return;

            default:
                Cache::forget($this->memberFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Sesi tambah Member sudah tidak berlaku. Ketik /addmember lagi.');
        }
    }

    protected function finalizeMemberCustomer(string $chatId, array $state, TelegramService $telegram): void
    {
        $outlet = Outlet::find($state['outlet_id'] ?? null);
        $paket = ServicePackage::find($state['data']['service_package_id'] ?? null);

        if (! $outlet || ! $paket) {
            $telegram->sendMessage($chatId, 'Gagal: outlet atau paket sudah tidak ada lagi. Ketik /addmember lagi dari awal.');

            return;
        }

        $data = $state['data'];

        try {
            $customer = Customer::create([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'type' => CustomerType::Hotspot->value,
                'username' => $data['username'],
                'password' => $data['password'],
                'service_package_id' => $paket->id,
                'outlet_id' => $outlet->id,
                'status' => CustomerStatus::Active->value,
                'billing_day' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal membuat Member lewat bot Telegram', [
                'username' => $data['username'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat Member (kemungkinan username sudah dipakai lagi barusan). Ketik /addmember buat coba lagi.');

            return;
        }

        try {
            app(CustomerRadiusService::class)->provisionCustomer($customer, $paket);
        } catch (\Throwable $e) {
            Log::warning('Member dibuat tapi gagal provisioning RADIUS lewat bot Telegram', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, "⚠️ Member <b>{$customer->name}</b> berhasil dibuat, TAPI gagal disinkron ke RADIUS. Cek & sinkron manual lewat aplikasi (menu User Member).");

            return;
        }

        // BILLER penagih (kalau dipilih di langkah 'membbill:') -- pola
        // PERSIS sama dengan finalizePppoeCustomer(): ditugaskan lewat
        // pivot biller_customer_commissions, BUKAN ditulis ke
        // `outlet_id` (beda dari Static IP, lihat catatan panjang di
        // startStaticIpFlow()). SEBELUMNYA baris ini KELUPAAN ditulis
        // walau langkah pilih Billernya sudah ada -- akibatnya Biller
        // yang sudah dipilih pas /addmember tidak pernah benar-benar
        // tersimpan, itu sebabnya Member tidak muncul di pencarian
        // /bayar milik Biller manapun.
        $billerTerpilih = null;

        if (! empty($data['biller_id'])) {
            try {
                $billerTerpilih = Outlet::find($data['biller_id']);
                $billerTerpilih?->billedCustomers()->syncWithoutDetaching([
                    $customer->id => ['commission_amount' => 0],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Member dibuat lewat bot tapi gagal ditugaskan ke biller', [
                    'customer_id' => $customer->id,
                    'biller_id' => $data['biller_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $telegram->sendMessage($chatId,
            "✅ <b>Member Hotspot berhasil dibuat</b>\n\n".
            "Nama: {$customer->name}\n".
            'Alamat: '.($customer->address ?: '-')."\n".
            'No HP: '.($customer->phone ?: '-')."\n".
            "Outlet: {$outlet->name}\n".
            "Profile: {$paket->name}\n".
            "Username: <code>{$customer->username}</code>\n".
            "Password: <code>{$customer->password}</code>\n".
            'Biller: '.($billerTerpilih?->name ?? 'Tanpa biller')
        );
    }

    /**
     * /daftarmember -- daftar Member Hotspot yang SUDAH ada, dipaginasi
     * 10 per halaman lewat tombol "Sebelumnya"/"Berikutnya". $outlet
     * null artinya gabungan SEMUA outlet (cuma boleh kejadian buat
     * admin, dijamin pemanggilnya).
     */
    protected function showDaftarMember(string $chatId, ?int $messageId, ?Outlet $outlet, int $page, TelegramService $telegram): void
    {
        $perHalaman = 10;
        $page = max(1, $page);

        $query = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', CustomerType::Hotspot->value)
            ->when($outlet, fn ($q) => $q->where('outlet_id', $outlet->id))
            ->orderBy('name');

        $total = $query->count();

        if ($total === 0) {
            $teks = 'Belum ada Member terdaftar'.($outlet ? " untuk outlet {$outlet->name}." : '.');
            $messageId ? $telegram->editMessageText($chatId, $messageId, $teks) : $telegram->sendMessage($chatId, $teks);

            return;
        }

        $totalHalaman = (int) ceil($total / $perHalaman);
        $page = min($page, $totalHalaman);

        $members = $query->forPage($page, $perHalaman)->get(['id', 'name', 'username', 'status']);

        $teks = '📋 <b>Daftar Member</b>'.($outlet ? " — {$outlet->name}" : ' — Semua Outlet')."\n";
        $teks .= "Halaman {$page}/{$totalHalaman} ({$total} member)\n\n";

        foreach ($members as $i => $m) {
            $nomor = ($page - 1) * $perHalaman + $i + 1;
            $statusIcon = ($m->status instanceof CustomerStatus ? $m->status->value : $m->status) === CustomerStatus::Active->value ? '🟢' : '🔴';
            $teks .= "{$nomor}. {$statusIcon} <b>{$m->name}</b> (<code>{$m->username}</code>)\n";
        }

        $outletKey = $outlet?->id ?? 'all';
        $tombolNav = [];

        if ($page > 1) {
            $tombolNav[] = ['text' => '⬅️ Sebelumnya', 'callback_data' => "daftarmember:{$outletKey}:".($page - 1)];
        }

        if ($page < $totalHalaman) {
            $tombolNav[] = ['text' => 'Berikutnya ➡️', 'callback_data' => "daftarmember:{$outletKey}:".($page + 1)];
        }

        $markup = $tombolNav ? ['inline_keyboard' => [$tombolNav]] : null;

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $teks, $markup);
        } else {
            $telegram->sendMessage($chatId, $teks, $markup);
        }
    }

    // ================================================================
    // BARU: /pengeluaran -- outlet mencatat pengeluaran sendiri (beli
    // barang, listrik toko, dst) lewat bot. Tersimpan ke tabel Expense
    // yang SAMA dengan menu Pengeluaran di aplikasi (lihat model
    // Expense & migration add_outlet_id_to_expenses_table), cuma kolom
    // outlet_id-nya terisi supaya jelas pengeluaran ini punya outlet
    // mana -- bukan pengeluaran pusat.
    // ================================================================

    protected function pengeluaranFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_pengeluaran_flow:{$namespace}:{$chatId}";
    }

    protected function showKategoriPengeluaran(string $chatId, Outlet $outlet, TelegramService $telegram): void
    {
        $buttons = collect(\App\Enums\ExpenseCategory::cases())
            ->chunk(2)
            ->map(fn ($chunk) => $chunk->map(fn ($c) => [
                'text' => $c->label(),
                'callback_data' => "pengkat:{$c->value}",
            ])->values()->all())
            ->values()
            ->all();

        $telegram->sendMessage($chatId, "💸 <b>Catat Pengeluaran</b>\nOutlet: {$outlet->name}\n\nPilih kategori pengeluaran:", ['inline_keyboard' => $buttons]);
    }

    protected function handlePengeluaranFlowInput(string $chatId, string $text, array $state, TelegramService $telegram): void
    {
        $step = $state['step'] ?? null;

        switch ($step) {
            case 'judul':
                if ($text === '') {
                    $telegram->sendMessage($chatId, 'Judul tidak boleh kosong. Ketik Judul pengeluaran:');

                    return;
                }

                $state['data']['title'] = $text;
                $state['step'] = 'jumlah';
                Cache::put($this->pengeluaranFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik <b>Jumlah</b> pengeluaran (angka saja, mis. 150000):');

                return;

            case 'jumlah':
                $angka = (float) preg_replace('/[^0-9]/', '', $text);

                if ($angka <= 0) {
                    $telegram->sendMessage($chatId, 'Jumlah tidak valid. Ketik angka saja, mis. 150000:');

                    return;
                }

                $state['data']['amount'] = $angka;
                $state['step'] = 'catatan';
                Cache::put($this->pengeluaranFlowKey($chatId), $state, now()->addMinutes(20));
                $telegram->sendMessage($chatId, 'Ketik Catatan tambahan (boleh kirim "-" kalau tidak ada):');

                return;

            case 'catatan':
                $state['data']['notes'] = $text === '-' ? null : $text;
                Cache::forget($this->pengeluaranFlowKey($chatId));
                $this->simpanPengeluaran($chatId, $state, $telegram);

                return;

            default:
                Cache::forget($this->pengeluaranFlowKey($chatId));
                $telegram->sendMessage($chatId, 'Sesi catat pengeluaran sudah tidak berlaku. Ketik /pengeluaran lagi.');
        }
    }

    protected function simpanPengeluaran(string $chatId, array $state, TelegramService $telegram): void
    {
        $outlet = Outlet::find($state['outlet_id'] ?? null);

        if (! $outlet) {
            $telegram->sendMessage($chatId, 'Gagal: outlet sudah tidak ada lagi.');

            return;
        }

        $data = $state['data'];
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        try {
            $expense = \App\Models\Expense::create([
                'outlet_id' => $outlet->id,
                'expense_date' => now()->toDateString(),
                'category' => $data['category'],
                'title' => $data['title'],
                'amount' => $data['amount'],
                'payment_method' => \App\Enums\PaymentMethod::Tunai->value,
                'recipient' => $outlet->name,
                'notes' => $data['notes'] ?? null,
                'source' => 'telegram',
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal catat pengeluaran lewat bot Telegram', [
                'outlet_id' => $outlet->id,
                'error' => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId, '❌ Gagal menyimpan pengeluaran: '.$e->getMessage());

            return;
        }

        $telegram->sendMessage($chatId,
            "✅ <b>Pengeluaran tercatat</b>\n\n".
            "No: <code>{$expense->expense_number}</code>\n".
            "Kategori: {$expense->category->label()}\n".
            "Judul: {$expense->title}\n".
            "Jumlah: {$rp($expense->amount)}\n".
            'Catatan: '.($expense->notes ?: '-')."\n\n".
            '<i>Tercatat sebagai pengeluaran outlet ini -- bisa dilihat/dihapus lewat menu Pengeluaran di aplikasi.</i>'
        );
    }

    // ================================================================
    // BARU: /laporanpdf -- laporan pendapatan OUTLET KESELURUHAN versi
    // FILE PDF, RINGKAS (cuma Jumlah Transaksi, Pendapatan Total,
    // Komisi, Profit -- lihat exports/laporan-pendapatan-ringkas-pdf),
    // dihitung lewat ReportService::summarize() yang sama dengan
    // aplikasi & versi ringkas di chat (/pendapatan), ditambah pilihan
    // saring per AREA/WILAYAH.
    // ================================================================

    protected function showAreaPickerForLaporanPdf(string $chatId, int $messageId, string $period, TelegramService $telegram, string $awalanArea = 'lappdfarea'): void
    {
        $areas = \App\Models\Area::orderBy('name')->limit(100)->get(['id', 'name']);

        // /laporanoutlet (permintaan user 2026-10-01): "Semua Wilayah" berarti
        // SATU PDF UNTUK SETIAP WILAYAH (tiap wilayah punya grup WhatsApp
        // sendiri), BUKAN satu PDF gabungan. /laporanpdf (laporan internal
        // lengkap dengan komisi) tetap memberi satu PDF gabungan.
        $buttons = [[
            $awalanArea === 'lapoutarea'
                ? ['text' => '🌐 Semua Wilayah (1 wilayah = 1 PDF)', 'callback_data' => "{$awalanArea}:{$period}:each"]
                : ['text' => '🌐 Semua Wilayah', 'callback_data' => "{$awalanArea}:{$period}:all"],
        ]];

        foreach ($areas->chunk(2) as $baris) {
            $buttons[] = $baris->map(fn ($a) => [
                'text' => $a->name,
                'callback_data' => "{$awalanArea}:{$period}:{$a->id}",
            ])->values()->all();
        }

        $telegram->editMessageText($chatId, $messageId, 'Pilih Area/Wilayah (atau lihat gabungan semua):', ['inline_keyboard' => $buttons]);
    }

    /**
     * PDF ringkas (permintaan user): laporan pendapatan OUTLET
     * KESELURUHAN -- cuma 4 angka (Jumlah Transaksi, Pendapatan Total,
     * Komisi, Profit), BUKAN daftar transaksi satu per satu seperti
     * versi awal (yang meniru tombol "Export PDF" di menu Laporan
     * Penjualan). Perhitungannya tetap lewat ReportService::summarize()
     * yang sama, jadi angkanya konsisten dengan aplikasi & dengan versi
     * ringkas di chat (/pendapatan, lihat showRevenueReport()) -- cuma
     * di sini boleh disaring per AREA/WILAYAH dan hasilnya file PDF.
     */
    /**
     * PDF ringkas (permintaan user): laporan pendapatan OUTLET
     * KESELURUHAN -- 4 angka ringkasan (Jumlah Transaksi, Pendapatan
     * Total, Komisi, Profit) DITAMBAH rincian per outlet dalam
     * wilayah/area yang dipilih (Nama Outlet, Jumlah Trx, Pendapatan,
     * Komisi, Profit) -- lewat ReportService::outletBreakdown(), fungsi
     * yang SAMA dipakai tabel rincian outlet di menu Laporan Penjualan
     * aplikasi, jadi angkanya konsisten. Ringkasan totalnya tetap dari
     * summarize() (bukan dijumlah manual dari outletBreakdown) supaya
     * SELALU sama persis dengan versi ringkas di chat (/pendapatan).
     */
    protected function kirimLaporanPendapatanPdf(string $chatId, int $messageId, ?\App\Models\Area $area, string $period, TelegramService $telegram, bool $versiGrup = false): void
    {
        $rentang = static::rentangPeriodeLaporan($period);

        if (! $rentang) {
            $telegram->editMessageText($chatId, $messageId, 'Periode laporan tidak dikenali. Ketik '.($versiGrup ? '/laporanoutlet' : '/laporanpdf').' lagi.');

            return;
        }

        [$from, $to, $label] = $rentang;

        $filters = array_filter([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'area_id' => $area?->id,
        ]);

        $telegram->editMessageText($chatId, $messageId, $versiGrup ? '⏳ Menyiapkan PDF laporan profit...' : '⏳ Menyiapkan PDF laporan pendapatan...');

        try {
            $this->buatDanKirimPdfLaporan($chatId, $area, $filters, $label, $period, $versiGrup, $telegram);
        } catch (\Throwable $e) {
            Log::error('Gagal membuat PDF laporan pendapatan dari bot Telegram', ['error' => $e->getMessage()]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat PDF: '.$e->getMessage());
        }
    }

    /**
     * Bangun PDF laporan pendapatan SATU wilayah (atau gabungan kalau $area
     * null) dan kirim ke chat. Dipakai oleh kirimLaporanPendapatanPdf()
     * (satu wilayah pilihan) dan kirimLaporanOutletPerWilayah() (satu PDF per
     * wilayah) supaya isi & penamaan file keduanya SELALU sama.
     *
     * Versi grup WA (laporan PROFIT): nama file memuat BULAN dan WILAYAH
     * (mis. laporan-profit-outlet-september-2026-toya.pdf) supaya file
     * untuk grup yang berbeda tidak tertukar; "Hari Ini"/"Bulan Ini" diberi
     * tanggal. Versi /laporanpdf lama TIDAK diubah penamaannya.
     *
     * @return array{summary: array, outlet: \Illuminate\Support\Collection}
     */
    protected function buatDanKirimPdfLaporan(string $chatId, ?\App\Models\Area $area, array $filters, string $label, string $period, bool $versiGrup, TelegramService $telegram): array
    {
        $summary = app(ReportService::class)->summarize($filters);
        $outletBreakdown = app(ReportService::class)->outletBreakdown($filters);
        $labelWilayah = $area ? $area->name : 'Semua Wilayah';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.laporan-pendapatan-ringkas-pdf', [
            'summary' => $summary,
            'filters' => $filters,
            'labelWilayah' => $labelWilayah,
            'outletBreakdown' => $outletBreakdown,
            'versiGrup' => $versiGrup,
            'labelPeriode' => $label,
        ])->setPaper('a4');

        $namaFile = $versiGrup
            ? 'laporan-profit-outlet-'.\Illuminate\Support\Str::slug($label)
                .(str_starts_with($period, 'm') ? '' : '-'.now()->format('Ymd'))
                .'-'.\Illuminate\Support\Str::slug($labelWilayah).'.pdf'
            : 'laporan-pendapatan-'.$period.'-'.now()->format('His').'.pdf';

        $telegram->sendDocument($chatId, $pdf->output(), $namaFile, '📊 <b>Laporan '.($versiGrup ? 'Profit' : 'Pendapatan')." {$label}</b>\nWilayah: {$labelWilayah}\nTransaksi: {$summary['total_transaksi']}\nOutlet: {$outletBreakdown->count()}");

        return ['summary' => $summary, 'outlet' => $outletBreakdown];
    }

    /**
     * SATU PDF PER WILAYAH dalam sekali tekan (permintaan user 2026-10-01:
     * tiap wilayah punya grup WhatsApp sendiri). Hanya versi TANPA komisi.
     *
     * Wilayah tanpa transaksi di periode itu dilewati (tidak ada PDF kosong).
     * Transaksi yang TIDAK masuk wilayah mana pun -- outlet yang belum diberi
     * wilayah, atau penjualan Stok Pusat -- dihitung (total semua dikurangi
     * jumlah per wilayah) dan DILAPORKAN di pesan penutup, supaya tidak ada
     * outlet yang diam-diam terlewat dari semua grup.
     */
    protected function kirimLaporanOutletPerWilayah(string $chatId, int $messageId, string $period, TelegramService $telegram): void
    {
        $rentang = static::rentangPeriodeLaporan($period);

        if (! $rentang) {
            $telegram->editMessageText($chatId, $messageId, 'Periode laporan tidak dikenali. Ketik /laporanoutlet lagi.');

            return;
        }

        [$from, $to, $label] = $rentang;
        $dasar = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        $telegram->editMessageText($chatId, $messageId, '⏳ Menyiapkan PDF laporan profit per wilayah...');

        try {
            $laporan = app(ReportService::class);
            $terkirim = [];
            $kosong = [];
            $gagal = [];
            $trxTerkirim = 0;

            foreach (\App\Models\Area::orderBy('name')->get(['id', 'name']) as $area) {
                $filters = $dasar + ['area_id' => $area->id];

                try {
                    $ringkas = $laporan->summarize($filters);

                    if ((int) $ringkas['total_transaksi'] === 0) {
                        $kosong[] = $area->name;

                        continue;
                    }

                    $this->buatDanKirimPdfLaporan($chatId, $area, $filters, $label, $period, true, $telegram);
                    $terkirim[] = $area->name;
                    $trxTerkirim += (int) $ringkas['total_transaksi'];
                } catch (\Throwable $e) {
                    Log::error('Gagal membuat PDF laporan per wilayah dari bot Telegram', ['area' => $area->name, 'error' => $e->getMessage()]);
                    $gagal[] = $area->name;
                }
            }

            $semua = $laporan->summarize($dasar);
            $sisaTrx = (int) $semua['total_transaksi'] - $trxTerkirim;

            $teks = '✅ <b>Laporan profit per wilayah — '.$label."</b>\n\n";
            $teks .= $terkirim
                ? 'PDF terkirim ('.count($terkirim).'): '.implode(', ', $terkirim)."\n"
                : "Tidak ada PDF yang terkirim.\n";

            if ($kosong) {
                $teks .= 'Dilewati, tidak ada transaksi: '.implode(', ', $kosong)."\n";
            }

            if ($gagal) {
                $teks .= '❌ Gagal dibuat: '.implode(', ', $gagal)."\n";
            }

            if ($sisaTrx > 0) {
                $teks .= "\n⚠️ {$sisaTrx} transaksi TIDAK masuk wilayah mana pun (outlet belum diberi wilayah, atau penjualan Stok Pusat), jadi tidak ada di PDF di atas. Atur wilayah outlet di menu Mitra supaya ikut masuk ke PDF wilayahnya.";
            }

            $telegram->sendMessage($chatId, $teks);
        } catch (\Throwable $e) {
            Log::error('Gagal membuat laporan per wilayah dari bot Telegram', ['error' => $e->getMessage()]);
            $telegram->sendMessage($chatId, '❌ Gagal membuat laporan: '.$e->getMessage());
        }
    }

    /**
     * BARU (permintaan user): struk/invoice pembayaran SIAP CETAK di
     * printer POS ukuran 58mm, format PDF -- dikirim SETELAH pembayaran
     * lewat /bayar berhasil dicatat (dipanggil dari terapkanPembayaran()
     * & terapkanBayarMuka()).
     *
     * $items = array of ['label' => string, 'jumlah' => float], SATU
     * baris per bulan/invoice yang dilunasi -- dihitung SEBELUM
     * recordPayment() dipanggil di kedua pemanggilnya (lihat komentar di
     * masing-masing), supaya nominalnya masih benar (invoice yang sudah
     * Paid tidak lagi punya remainingAmount() > 0 kalau dihitung ulang
     * belakangan).
     *
     * (Catatan tinggi kertas: lihat ukurTinggiStrukMm() di bawah.)
     */

    /**
     * Ukur tinggi struk thermal (mm) dari hasil render dompdf yang SEBENARNYA.
     *
     * Cara: render sekali di kertas sangat tinggi, tangkap kotak elemen
     * #struk lewat callback begin_frame (pohon frame dompdf sudah
     * dikosongkan begitu render() selesai, jadi TIDAK bisa dibaca
     * sesudahnya), lalu tinggi itu dipakai untuk render kedua yang final.
     * Ditambah 3mm ruang sobek supaya tidak pernah tumpah ke halaman 2.
     *
     * Kalau pengukuran gagal (mis. versi dompdf berubah), jatuh ke
     * perkiraan yang SENGAJA dilebihkan -- lebih baik ada sedikit kertas
     * kosong daripada isi terpotong.
     */
    protected function ukurTinggiStrukMm(string $view, array $data, float $lebarMm, int $jumlahItem, bool $adaCatatan): float
    {
        $pt = 2.83465;
        $cadangan = 90.0 + ($jumlahItem * 8) + ($adaCatatan ? 14 : 0);

        try {
            $tinggiPt = null;

            $ukur = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data)
                ->setPaper([0, 0, $lebarMm * $pt, 3000]);

            $ukur->getDomPDF()->setCallbacks([[
                'event' => 'begin_frame',
                'f' => function ($frame) use (&$tinggiPt) {
                    $node = $frame->get_node();

                    if ($node instanceof \DOMElement && $node->getAttribute('id') === 'struk') {
                        $tinggiPt = $frame->get_border_box()['h'];
                    }
                },
            ]]);

            $ukur->output();

            if ($tinggiPt !== null && $tinggiPt > 0) {
                return max(30.0, ceil($tinggiPt / $pt) + 3);
            }
        } catch (\Throwable $e) {
            Log::notice('Pengukuran tinggi struk gagal, pakai perkiraan cadangan', ['error' => $e->getMessage()]);
        }

        return $cadangan;
    }

    /**
     * Struk pembayaran (baik dari /bayar transaksi baru, maupun cetak
     * ulang lewat /cetakstruk) -- bisa dicetak dalam 3 FORMAT (permintaan
     * user): 'pos58' (thermal 58mm, DEFAULT -- dipakai /bayar seperti
     * biasa supaya tidak mengganggu alur yang sudah jalan), 'pos80'
     * (thermal 80mm, layout SAMA cuma lebih lebar), atau 'a4' (invoice
     * satu halaman penuh, buat outlet yang printernya printer biasa
     * ukuran A4/F4, bukan printer struk kasir).
     *
     * dompdf TIDAK mendukung tinggi kertas "auto" mengikuti isi seperti
     * CSS "size: 58mm auto" di browser biasa (lihat
     * prints/invoice-thermal.blade.php, yang dibuka LANGSUNG lewat
     * browser, bukan lewat dompdf) -- jadi utk format thermal, tingginya
     * diperkirakan KASAR dari jumlah baris di sini, dilebihkan sedikit
     * supaya tidak terpotong. Format 'a4' pakai ukuran kertas baku, tidak
     * perlu dihitung manual.
     */
    protected function kirimStrukPembayaranBot(string $chatId, Customer $pelanggan, array $items, float $total, TelegramService $telegram, float $sisaTagihan = 0, float $saldoLebih = 0, string $caption = '🧾 Struk pembayaran siap cetak (printer POS 58mm)', string $format = 'pos58'): void
    {
        try {
            $outlet = $pelanggan->outlet;
            $data = [
                'pelanggan' => $pelanggan,
                'outletName' => $outlet?->name,
                'items' => $items,
                'total' => $total,
                'metode' => 'Tunai',
                'noStruk' => 'STRK-'.now()->format('YmdHis'),
                'tanggal' => now()->translatedFormat('d M Y H:i'),
                'sisaTagihan' => $sisaTagihan,
                'saldoLebih' => $saldoLebih,
            ];

            if ($format === 'a4') {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.bot-payment-receipt-a4-pdf', $data)->setPaper('a4');
            } else {
                // 'pos58' atau 'pos80' -- view & cara hitung tingginya
                // SAMA PERSIS, cuma lebar kertasnya beda.
                $lebarMm = $format === 'pos80' ? 80 : 58;
                $dataThermal = $data + ['lebarMm' => $lebarMm];

                // Tinggi kertas DIUKUR dari hasil render, bukan ditebak lewat
                // rumus (42mm + 4mm/item) seperti dulu -- rumus itu terlalu
                // kecil sehingga sisa isi tumpah ke HALAMAN 2.
                $tinggiMm = $this->ukurTinggiStrukMm('exports.bot-payment-receipt-pdf', $dataThermal, $lebarMm, count($items), $sisaTagihan > 0 || $saldoLebih > 0);

                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.bot-payment-receipt-pdf', $dataThermal)
                    ->setPaper([0, 0, $lebarMm * 2.83465, $tinggiMm * 2.83465]);
            }

            $namaFile = 'struk-'.\Illuminate\Support\Str::slug($pelanggan->name).'-'.now()->format('His').'.pdf';

            $telegram->sendDocument($chatId, $pdf->output(), $namaFile, $caption);
        } catch (\Throwable $e) {
            // Gagal bikin struk TIDAK BOLEH menggagalkan pencatatan
            // pembayarannya sendiri -- pembayaran SUDAH tercatat lewat
            // InvoiceService sebelum method ini dipanggil oleh
            // pemanggilnya, jadi di sini cukup dicatat ke log & kasih
            // tahu lewat chat biasa (bukan exception yang menjalar).
            Log::warning('Gagal membuat struk PDF pembayaran dari bot Telegram', ['error' => $e->getMessage()]);
            $telegram->sendMessage($chatId, '⚠️ Pembayaran tercatat, tapi gagal membuat struk PDF: '.$e->getMessage());
        }
    }

    // ================================================================
    // BARU (permintaan user): /cetakstruk (alias /cetakinvoice) --
    // cetak ULANG struk pembayaran yang SUDAH tercatat, buat kasus
    // struk gagal/tidak sempat tercetak pas transaksi asalnya (printer
    // mati, kertas habis, dsb). TIDAK mencatat pembayaran baru sama
    // sekali -- cuma membaca ULANG PaymentLog yang paling BARU milik
    // pelanggan itu (lihat cetakUlangStruk()) dan menggambar ulang PDF
    // strukny yang sama persis lewat kirimStrukPembayaranBot() di atas.
    // Setelah pelanggan dipilih, user ditanya dulu FORMAT PRINTERnya
    // (lihat showPilihanPrinterCetakUlang()) sebelum PDF digambar.
    // ================================================================

    /**
     * Tombol pilihan format printer -- langkah PERANTARA sebelum
     * cetakUlangStruk() beneran jalan. customerId disisipkan LANGSUNG di
     * callback_data (bukan Cache) karena cuma satu keping data kecil,
     * tidak perlu state tersimpan seperti alur /pppoe dkk.
     */
    protected function showPilihanPrinterCetakUlang(string $chatId, ?int $messageId, int $customerId, TelegramService $telegram): void
    {
        $markup = ['inline_keyboard' => [[
            ['text' => 'POS 58mm', 'callback_data' => "cetakprn:{$customerId}:pos58"],
            ['text' => 'POS 80mm', 'callback_data' => "cetakprn:{$customerId}:pos80"],
        ], [
            ['text' => '🖨️ Printer Biasa (A4/F4)', 'callback_data' => "cetakprn:{$customerId}:a4"],
        ]]];

        $this->balas($chatId, $messageId, 'Mau dicetak pakai printer apa?', $markup, $telegram);
    }

    /**
     * Cetak ulang struk dari PaymentLog TERBARU milik satu pelanggan.
     * `allocations` (lihat InvoiceService::recordPayment()) berisi
     * rincian invoice mana saja & berapa yang dibayar lewat transaksi
     * itu -- dipetakan balik jadi $items sama seperti struk aslinya.
     * Kalau pembayaran itu murni dari saldo (tidak ada allocations,
     * jarang tapi mungkin), tetap ditampilkan sebagai satu baris umum
     * supaya strukny tidak kosong.
     */
    protected function cetakUlangStruk(string $chatId, ?int $messageId, int $customerId, TelegramService $telegram, string $format = 'pos58'): void
    {
        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($customerId);

        if (! $pelanggan) {
            $this->balas($chatId, $messageId, 'Pelanggan tidak ditemukan.', null, $telegram);

            return;
        }

        $log = \App\Models\PaymentLog::where('customer_id', $pelanggan->id)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            $this->balas($chatId, $messageId, "Belum ada riwayat pembayaran untuk <b>{$pelanggan->name}</b>.", null, $telegram);

            return;
        }

        $items = collect($log->allocations ?? [])
            ->map(function ($alokasi) {
                $invoice = \App\Models\Invoice::find($alokasi['invoice_id'] ?? null);

                $label = $invoice
                    ? \Carbon\Carbon::create()->month($invoice->period_month)->translatedFormat('F').' '.$invoice->period_year
                    : 'Tagihan';

                return ['label' => $label, 'jumlah' => (float) ($alokasi['amount'] ?? 0)];
            })
            ->values()
            ->all();

        if (empty($items)) {
            // Pembayaran murni pakai/nambah saldo, tidak ada invoice yang
            // langsung dialokasikan (jarang terjadi, tapi jaga-jaga).
            $items = [['label' => 'Pembayaran', 'jumlah' => (float) $log->amount]];
        }

        $sisaTagihan = $pelanggan->invoices()
            ->whereIn('status', [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Overdue])
            ->get()
            ->sum(fn ($i) => $i->remainingAmount());

        $saldoLebih = (float) $pelanggan->credit_balance;

        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, '⏳ Menyiapkan cetak ulang struk...');
        }

        $labelFormat = match ($format) {
            'pos80' => 'printer POS 80mm',
            'a4' => 'printer biasa A4/F4',
            default => 'printer POS 58mm',
        };

        $this->kirimStrukPembayaranBot(
            $chatId,
            $pelanggan,
            $items,
            (float) $log->amount,
            $telegram,
            $sisaTagihan,
            $saldoLebih,
            '🔁 <b>Cetak ulang</b> struk pembayaran ('.$log->created_at->translatedFormat('d M Y H:i').") — {$labelFormat}, bukan transaksi baru.",
            $format
        );
    }

    protected function resetConnection(): void
    {
        // Lepas juga binding "currentTenant" yang mungkin diisi di
        // resolveTelegramService() untuk webhook tenant -- kalau tidak,
        // bisa "nyangkut" ke request berikutnya yang diproses worker PHP
        // yang sama (mis. lewat Octane), bikin awalan RADIUS salah
        // dikira masih punya tenant walau requestnya sudah beda.
        if (app()->bound('currentTenant')) {
            app()->forgetInstance('currentTenant');
        }

        // PENTING: balik ke nilai yang DIREKAM DI AWAL __invoke() (lihat
        // $originalDatabase), BUKAN env('DB_DATABASE') -- kalau config
        // lagi di-cache (php artisan config:cache), env() di luar file
        // config selalu bernilai null di sini, dan koneksi 'mysql' tidak
        // akan pernah balik ke database utama yang benar (lihat catatan
        // sama di RunsPerTenant/MigrateTenants, masalah yang persis sama).
        if ($this->originalDatabase) {
            Config::set('database.connections.mysql.database', $this->originalDatabase);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }
    }

    /**
     * Cari pelanggan untuk /redaman. Cakupan mengikuti akun yang bertanya:
     * admin = semua pelanggan; Biller = pelanggan tanggungannya; selain itu
     * (Outlet/Reseller) = pelanggan dengan outlet_id akun itu sendiri.
     */
    protected function cariPelangganUntukRedaman(string $chatId, string $kunci, TelegramService $telegram): void
    {
        $kueri = Customer::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where(function ($q) use ($kunci) {
                $q->where('name', 'like', '%'.$kunci.'%')
                    ->orWhere('username', 'like', '%'.$kunci.'%');
            });

        if (! $this->isAdminChat($chatId)) {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet) {
                $telegram->sendMessage($chatId, 'Akun Telegram ini belum terhubung ke outlet manapun.');

                return;
            }

            if ($outlet->partner_type === \App\Enums\PartnerType::Biller) {
                $kueri->whereIn('id', $this->idPelangganMilikBiller($outlet));
            } else {
                $kueri->where('outlet_id', $outlet->id);
            }
        }

        // Yang sudah ditautkan ke ONU didahulukan.
        $pelanggan = $kueri->orderByRaw('acs_device_id IS NULL')->orderBy('name')->limit(10)->get(['id', 'name', 'username', 'acs_device_id', 'outlet_id']);

        $kunciAman = htmlspecialchars($kunci, ENT_QUOTES, 'UTF-8');

        if ($pelanggan->isEmpty()) {
            $telegram->sendMessage($chatId, "Tidak ada pelanggan yang cocok dengan \"<b>{$kunciAman}</b>\" di akun ini.");

            return;
        }

        if ($pelanggan->count() === 1) {
            $this->kirimRedaman($chatId, null, $pelanggan->first(), $telegram);

            return;
        }

        $tombol = $pelanggan->map(fn ($p) => [[
            'text' => ($p->acs_device_id ? '📶 ' : '').$p->name.' ('.$p->username.')',
            'callback_data' => "rdmcust:{$p->id}",
        ]])->values()->all();

        $telegram->sendMessage(
            $chatId,
            "Ditemukan {$pelanggan->count()} pelanggan. Pilih yang mau dicek redamannya (📶 = sudah tertaut ke ONU):",
            ['inline_keyboard' => $tombol]
        );
    }

    /** Apakah akun Telegram ini BOLEH melihat data ONU pelanggan tsb (aturan cakupan yang sama dengan pencarian). */
    protected function pelangganBolehDilihat(string $chatId, Customer $pelanggan): bool
    {
        if ($this->isAdminChat($chatId)) {
            return true;
        }

        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        if (! $outlet) {
            return false;
        }

        if ($outlet->partner_type === \App\Enums\PartnerType::Biller) {
            return $this->idPelangganMilikBiller($outlet)->contains($pelanggan->id);
        }

        return (int) $pelanggan->outlet_id === (int) $outlet->id;
    }

    /** Kirim (atau ubah pesan jadi) hasil redaman + tombol Cek lagi / Minta data terbaru. */
    protected function kirimRedaman(string $chatId, ?int $messageId, Customer $pelanggan, TelegramService $telegram, string $catatan = ''): void
    {
        $hasil = app(\App\Services\OnuRedamanService::class)->untukPelanggan($pelanggan);

        $teks = ($catatan !== '' ? $catatan."\n\n" : '').$hasil['pesan'];

        $baris = [['text' => '🔄 Cek lagi', 'callback_data' => "rdmcust:{$pelanggan->id}"]];

        if ($hasil['bisa_refresh']) {
            $baris[] = ['text' => '♻️ Minta data terbaru', 'callback_data' => "rdmref:{$pelanggan->id}"];
        }

        $markup = ['inline_keyboard' => [$baris]];

        $messageId
            ? $telegram->editMessageText($chatId, $messageId, $teks, $markup)
            : $telegram->sendMessage($chatId, $teks, $markup);
    }


    protected function wifiFlowKey(string $chatId): string
    {
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return "telegram_wifi_flow:{$namespace}:{$chatId}";
    }

    /** Admin: semua pelanggan. Outlet/Reseller: hanya pelanggannya sendiri. Biller: tidak. */
    protected function pelangganBolehUbahWifi(string $chatId, Customer $pelanggan): bool
    {
        if ($this->isAdminChat($chatId)) {
            return true;
        }

        $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

        if (! $outlet || $outlet->partner_type === \App\Enums\PartnerType::Biller) {
            return false;
        }

        return (int) $pelanggan->outlet_id === (int) $outlet->id;
    }

    protected function cariPelangganUntukWifi(string $chatId, string $kunci, TelegramService $telegram): void
    {
        $kueri = Customer::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where(function ($q) use ($kunci) {
                $q->where('name', 'like', '%'.$kunci.'%')
                    ->orWhere('username', 'like', '%'.$kunci.'%');
            });

        if (! $this->isAdminChat($chatId)) {
            $outlet = Outlet::where('telegram_chat_id', $chatId)->first();

            if (! $outlet || $outlet->partner_type === \App\Enums\PartnerType::Biller) {
                $telegram->sendMessage($chatId, 'Perintah ini khusus admin dan outlet/reseller.');

                return;
            }

            $kueri->where('outlet_id', $outlet->id);
        }

        $pelanggan = $kueri->orderByRaw('acs_device_id IS NULL')->orderBy('name')->limit(10)->get(['id', 'name', 'username', 'acs_device_id', 'outlet_id']);

        $kunciAman = htmlspecialchars($kunci, ENT_QUOTES, 'UTF-8');

        if ($pelanggan->isEmpty()) {
            $telegram->sendMessage($chatId, "Tidak ada pelanggan yang cocok dengan \"<b>{$kunciAman}</b>\" di akun ini.");

            return;
        }

        if ($pelanggan->count() === 1) {
            $this->kirimWifi($chatId, null, $pelanggan->first(), $telegram);

            return;
        }

        $tombol = $pelanggan->map(fn ($p) => [[
            'text' => ($p->acs_device_id ? '📡 ' : '').$p->name.' ('.$p->username.')',
            'callback_data' => "wfv:{$p->id}",
        ]])->values()->all();

        $telegram->sendMessage(
            $chatId,
            "Ditemukan {$pelanggan->count()} pelanggan. Pilih yang mau dilihat Wi-Fi-nya (📡 = sudah tertaut ke ONU):",
            ['inline_keyboard' => $tombol]
        );
    }

    protected function kirimWifi(string $chatId, ?int $messageId, Customer $pelanggan, TelegramService $telegram, string $catatan = ''): void
    {
        $hasil = app(\App\Services\OnuWifiService::class)->pesanTampil($pelanggan);

        $this->balas(
            $chatId,
            $messageId,
            ($catatan !== '' ? $catatan."\n\n" : '').$hasil['pesan'],
            $hasil['tombol'] ? ['inline_keyboard' => $hasil['tombol']] : null,
            $telegram
        );
    }

    /**
     * Semua tombol alur /wifi.
     *
     * @param  array<int,string>  $m  hasil preg_match: [1]=aksi, [2]=id pelanggan, [3]=nomor WLAN
     */
    protected function handleWifiCallback(string $chatId, int $messageId, string $data, array $m, TelegramService $telegram): void
    {
        // ---- batal
        if ($data === 'wfno') {
            Cache::forget($this->wifiFlowKey($chatId));
            $telegram->editMessageText($chatId, $messageId, 'Perubahan Wi-Fi dibatalkan.');

            return;
        }

        // ---- terapkan yang sudah dikonfirmasi
        if ($data === 'wfok') {
            $state = Cache::get($this->wifiFlowKey($chatId));

            if (! $state || ($state['tahap'] ?? '') !== 'konfirmasi') {
                $telegram->editMessageText($chatId, $messageId, 'Permintaan ini sudah kedaluwarsa. Ketik /wifi NAMA lagi untuk mengulang.');

                return;
            }

            $pelanggan = Customer::query()->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find((int) $state['cid']);

            if (! $pelanggan || ! $pelanggan->acs_device_id || ! $this->pelangganBolehUbahWifi($chatId, $pelanggan)) {
                Cache::forget($this->wifiFlowKey($chatId));
                $telegram->editMessageText($chatId, $messageId, 'Pelanggan tidak ditemukan atau bukan tanggungan akun ini.');

                return;
            }

            try {
                $nilai = \Illuminate\Support\Facades\Crypt::decryptString($state['nilai']);
            } catch (\Throwable) {
                Cache::forget($this->wifiFlowKey($chatId));
                $telegram->editMessageText($chatId, $messageId, 'Permintaan ini rusak/kedaluwarsa. Ketik /wifi NAMA lagi untuk mengulang.');

                return;
            }

            Cache::forget($this->wifiFlowKey($chatId));

            $hasil = app(\App\Services\OnuWifiService::class)->terapkan(
                $pelanggan->acs_device_id,
                (string) $state['dasar'],
                $state['aksi'] === 'ssid' ? $nilai : null,
                $state['aksi'] === 'pass' ? $nilai : null,
                'Bot Telegram (chat '.$chatId.')',
                null,
                $pelanggan
            );

            $teks = ! $hasil['ok']
                ? '❌ Gagal mengubah Wi-Fi: '.htmlspecialchars((string) $hasil['pesan'], ENT_QUOTES, 'UTF-8')
                : ($hasil['langsung']
                    ? '✅ Perubahan Wi-Fi sudah diterapkan ke ONU. Perangkat yang tersambung akan terputus dan perlu menyambung ulang.'
                    : "⏳ Perubahan Wi-Fi sudah dikirim ke GenieACS, tetapi ONU belum bisa dihubungi langsung. Perubahan berlaku saat ONU berikutnya menghubungi ACS.");

            $telegram->editMessageText($chatId, $messageId, $teks, [
                'inline_keyboard' => [[['text' => '📡 Lihat Wi-Fi', 'callback_data' => 'wfv:'.$pelanggan->id]]],
            ]);

            return;
        }

        // ---- tombol yang membawa id pelanggan
        $aksi = $m[1] ?? '';
        $pelanggan = Customer::query()->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find((int) ($m[2] ?? 0));

        if (! $pelanggan || ! $this->pelangganBolehUbahWifi($chatId, $pelanggan)) {
            $telegram->editMessageText($chatId, $messageId, 'Pelanggan tidak ditemukan atau bukan tanggungan akun ini.');

            return;
        }

        $wifi = app(\App\Services\OnuWifiService::class);

        if ($aksi === 'wfv') {
            $this->kirimWifi($chatId, $messageId, $pelanggan, $telegram);

            return;
        }

        if ($aksi === 'wfr') {
            $catatan = '';

            if ($pelanggan->acs_device_id) {
                $data = $wifi->muat($pelanggan->acs_device_id);
                $objek = $data['device'] ? app(\App\Services\GenieAcsService::class)->objekWifi($data['device']) : 'InternetGatewayDevice.LANDevice';
                $hasil = app(\App\Services\GenieAcsService::class)->refreshBagian($pelanggan->acs_device_id, $objek);

                $catatan = $hasil['ok']
                    ? '♻️ Permintaan data Wi-Fi sudah dikirim ke ONU. Tunggu 1-2 menit lalu tekan 🔄 Cek lagi.'
                    : '❌ Gagal meminta data Wi-Fi: '.htmlspecialchars((string) $hasil['pesan'], ENT_QUOTES, 'UTF-8');
            }

            $this->kirimWifi($chatId, $messageId, $pelanggan, $telegram, $catatan);

            return;
        }

        // ---- wfs / wfp : mulai ganti SSID / password WLAN nomor N
        $indeks = (int) ($m[3] ?? 0);
        $data = $pelanggan->acs_device_id ? $wifi->muat($pelanggan->acs_device_id) : ['ok' => false, 'pesan' => 'Pelanggan belum ditautkan ke ONU.', 'wlan' => []];

        $wlan = $data['ok'] ? collect($data['wlan'])->firstWhere('indeks', $indeks) : null;

        if (! $wlan) {
            $telegram->sendMessage($chatId, '❌ '.htmlspecialchars($data['ok'] ? 'Wi-Fi nomor itu tidak ada di ONU ini.' : (string) $data['pesan'], ENT_QUOTES, 'UTF-8'));

            return;
        }

        if ($aksi === 'wfp' && ! $wlan['jalur_password']) {
            $telegram->sendMessage($chatId, '❌ Jalur kata sandi Wi-Fi ONU ini belum dikenal. Tekan ♻️ Muat data Wi-Fi dulu, lalu coba lagi.');

            return;
        }

        Cache::put($this->wifiFlowKey($chatId), [
            'cid' => $pelanggan->id,
            'dasar' => $wlan['dasar'],
            'indeks' => $indeks,
            'aksi' => $aksi === 'wfs' ? 'ssid' : 'pass',
            'tahap' => 'nilai',
        ], now()->addMinutes(10));

        $telegram->sendMessage(
            $chatId,
            $aksi === 'wfs'
                ? "✏️ Ketik <b>SSID baru</b> untuk WLAN {$indeks} (sekarang: <code>".htmlspecialchars($wlan['ssid'], ENT_QUOTES, 'UTF-8')."</code>).\nMaksimal 32 karakter.\n\n/batal untuk membatalkan."
                : "🔑 Ketik <b>password Wi-Fi baru</b> untuk WLAN {$indeks} (8-63 karakter, huruf/angka/simbol biasa).\nPesan Anda akan dihapus otomatis dari chat setelah dibaca.\n\n/batal untuk membatalkan."
        );
    }

    /** Teks yang diketik sebagai SSID/password baru -> validasi -> minta konfirmasi. */
    protected function handleWifiFlowInput(string $chatId, string $text, int $pesanId, array $state, TelegramService $telegram): void
    {
        $acs = app(\App\Services\GenieAcsService::class);
        $ssid = $state['aksi'] === 'ssid';

        // Pesan berisi kata sandi dihapus SEGERA -- juga kalau ternyata tidak sah.
        if (! $ssid && $pesanId) {
            $telegram->deleteMessage($chatId, $pesanId);
        }

        $galat = $ssid ? $acs->validasiSsid($text) : $acs->validasiKataSandi($text);

        if ($galat) {
            $telegram->sendMessage($chatId, '❌ '.htmlspecialchars($galat, ENT_QUOTES, 'UTF-8')."\n\nCoba ketik lagi, atau /batal.");

            return;
        }

        $pelanggan = Customer::query()->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find((int) $state['cid']);

        if (! $pelanggan || ! $pelanggan->acs_device_id || ! $this->pelangganBolehUbahWifi($chatId, $pelanggan)) {
            Cache::forget($this->wifiFlowKey($chatId));
            $telegram->sendMessage($chatId, 'Pelanggan tidak ditemukan atau bukan tanggungan akun ini.');

            return;
        }

        $data = app(\App\Services\OnuWifiService::class)->muat($pelanggan->acs_device_id);
        $wlan = $data['ok'] ? collect($data['wlan'])->firstWhere('dasar', $state['dasar']) : null;

        if (! $wlan) {
            Cache::forget($this->wifiFlowKey($chatId));
            $telegram->sendMessage($chatId, '❌ '.htmlspecialchars($data['ok'] ? 'Wi-Fi itu sudah tidak ada di ONU.' : (string) $data['pesan'], ENT_QUOTES, 'UTF-8'));

            return;
        }

        Cache::put($this->wifiFlowKey($chatId), array_merge($state, [
            'tahap' => 'konfirmasi',
            'nilai' => \Illuminate\Support\Facades\Crypt::encryptString($text),
        ]), now()->addMinutes(10));

        $h = fn (string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $nama = $h($pelanggan->name).' ('.$h($pelanggan->username).')';

        $isi = $ssid
            ? "Ganti <b>SSID</b> WLAN {$state['indeks']}\nDari: <code>".$h($wlan['ssid'])."</code>\nMenjadi: <code>".$h($text).'</code>'
            : "Ganti <b>password</b> WLAN {$state['indeks']} (SSID <code>".$h($wlan['ssid'])."</code>)\nPassword baru: <tg-spoiler>".$h($text).'</tg-spoiler>';

        $telegram->sendMessage(
            $chatId,
            "📡 <b>Konfirmasi ubah Wi-Fi</b>\nPelanggan: {$nama}\n\n{$isi}\n\n⚠️ Perangkat yang tersambung ke Wi-Fi ini akan terputus dan perlu menyambung ulang.",
            ['inline_keyboard' => [[
                ['text' => '✅ Terapkan', 'callback_data' => 'wfok'],
                ['text' => '❌ Batal', 'callback_data' => 'wfno'],
            ]]]
        );
    }

}
