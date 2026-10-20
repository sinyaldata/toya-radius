<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Radacct;
use App\Services\MikrotikService;
use App\Services\RouterResolver;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tutup otomatis sesi RADIUS "hantu" -- baris radacct yang masih tercatat
 * ONLINE (acctstoptime NULL) tapi sudah lama TIDAK ADA kabar, tanda-tanda
 * router/pelanggannya sudah putus tanpa sempat mengirim Accounting-Stop
 * (mati listrik, router reboot mendadak, kabel putus, dst.).
 *
 * KENAPA PERLU: status Online/Offline aplikasi ini HANYA mengecek
 * "acctstoptime IS NULL" (lihat Radacct::scopeOnline()) -- TIDAK ADA
 * pengecekan basi/tidaknya baris itu. Tanpa pembersihan berkala, sesi
 * hantu akan tercatat "Online" SELAMANYA sampai ada yang menutupnya
 * manual -- ini akar masalah insiden "aplikasi bilang online, MikroTik
 * bilang authentication failed" yang pernah terjadi di tenant kflink.
 *
 * KOREKSI BESAR #2 (2026-09-28): versi PERTAMA (ambang batas waktu SAJA,
 * 15 menit) TERBUKTI SALAH -- menutup 232 sesi yang SEBENARNYA masih
 * hidup (kasus nyata: "abdulrohman@170726", uptime router 15 jam 51
 * menit). Dicoba naikkan ke 120 menit, TAPI itu masih cuma tebakan --
 * pengaturan Interim-Update di router ternyata SUDAH 5 menit (jauh
 * lebih pendek dari yang diduga), jadi keterlambatan >15 menit itu
 * kemungkinan besar dari paket UDP accounting yang KADANG HILANG di
 * jaringan (wajar terjadi, bukan berarti sesinya putus) -- database
 * sendiri sudah dicek sehat (index lengkap, tidak ada antrean macet).
 *
 * DESAIN BARU: waktu HANYA dipakai untuk mempersempit KANDIDAT (supaya
 * tidak perlu tanya ke SEMUA router tiap 5 menit) -- sesi kandidat TIDAK
 * LANGSUNG ditutup dari itu saja. Sebelum benar-benar ditutup, DITANYAKAN
 * LANGSUNG ke router aslinya (satu panggilan API per router, bukan per
 * pelanggan) apakah pelanggan itu MASIH ada di daftar aktifnya. Kalau
 * router bilang MASIH ADA -> TIDAK DITUTUP, apa pun kata waktunya --
 * inilah yang menghilangkan tebak-tebakan soal jaringan/interval sama
 * sekali. Kalau router TIDAK BISA dihubungi -> juga TIDAK DITUTUP (aman
 * dulu, coba lagi 5 menit berikutnya) daripada salah tutup karena tidak
 * bisa memastikan.
 *
 * DIJADWALKAN OTOMATIS setiap 5 menit (lihat routes/console.php).
 */
class TutupSesiHantu extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:tutup-sesi-hantu
        {--menit=20 : Sesi jadi KANDIDAT diperiksa kalau acctupdatetime lebih tua dari ini (BUKAN langsung ditutup -- lihat penjelasan di atas)}
        {--dry-run : Cuma tampilkan berapa yang akan ditutup, tidak menulis apa pun}';

    /**
     * Minimal sesi terbuka pada satu NAS agar pengaman pemetaan router
     * (lihat tutupTenantIni) berlaku. Di bawah ini, nol irisan masih
     * wajar (NAS kecil / memang sedang sepi).
     */
    protected const MIN_CURIGA = 5;

    /** @var array<int,string> NAS yang dilewati karena pemetaan routernya mencurigakan (untuk ditampilkan) */
    public array $peringatan = [];

    protected $description = 'Tutup sesi radacct yang masih "Online" padahal TERBUKTI sudah tidak ada di daftar aktif router-nya (diverifikasi langsung ke router, bukan cuma tebak dari waktu).';

    public function handle(RouterResolver $routerResolver, MikrotikService $mikrotikService): int
    {
        $menit = max(1, (int) $this->option('menit'));
        $simulasi = (bool) $this->option('dry-run');
        $batas = now()->subMinutes($menit);

        $total = $this->runForEachTenant(function ($tenant) use ($batas, $menit, $simulasi, $routerResolver, $mikrotikService) {
            $this->peringatan = [];
            $diubah = $this->tutupTenantIni($batas, $simulasi, $routerResolver, $mikrotikService);

            foreach ($this->peringatan as $p) {
                $this->warn('['.($tenant?->subdomain ?? 'utama').'] '.$p);
            }

            if ($diubah > 0) {
                $this->line(sprintf(
                    '[%s] %d sesi %s ditutup (terverifikasi TIDAK ADA lagi di router, kandidat dari >%d menit tanpa kabar)',
                    $tenant?->subdomain ?? 'utama',
                    $diubah,
                    $simulasi ? 'AKAN' : '',
                    $menit
                ));
            }

            return $diubah;
        });

        $this->info($simulasi
            ? "{$total} sesi hantu AKAN ditutup (dry-run, belum ada yang ditulis) -- gabungan semua tenant."
            : "{$total} sesi hantu ditutup -- gabungan semua tenant.");

        return self::SUCCESS;
    }

    /**
     * @return int jumlah baris yang ditutup (atau akan ditutup, saat simulasi)
     */
    public function tutupTenantIni(Carbon $batas, bool $simulasi, RouterResolver $routerResolver, MikrotikService $mikrotikService): int
    {
        // WAJIB ->milikTenantIni() -- radacct itu TABEL PUSAT dipakai
        // bersama SEMUA tenant, lihat catatan di versi sebelumnya kenapa
        // ini wajib (pernah lupa, akibatnya 104 dihitung 7x lipat).
        $kandidat = Radacct::online()->milikTenantIni()->where('acctupdatetime', '<', $batas)->get();

        if ($kandidat->isEmpty()) {
            return 0;
        }

        // Satu panggilan API PER ROUTER (bukan per pelanggan) -- kumpulkan
        // dulu daftar aktif tiap router yang RELEVAN saja (yang benar2
        // punya kandidat), simpan di memori sebentar untuk seluruh
        // pemeriksaan tenant ini.
        $daftarAktifPerRouter = [];
        $diubah = 0;

        foreach ($kandidat->groupBy('nasipaddress') as $nasIp => $sesiSatuNas) {
            $router = $routerResolver->resolveForNasIp($nasIp);

            if (! $router) {
                // Tidak tahu router mana yang punya IP NAS ini -- tidak
                // bisa diverifikasi, JANGAN ditutup (aman dulu).
                continue;
            }

            if (! isset($daftarAktifPerRouter[$router->id])) {
                try {
                    $ppp = $mikrotikService->getActivePppSessions($router);
                    $hotspot = $mikrotikService->getActiveHotspotSessions($router);
                    $daftarAktifPerRouter[$router->id] = collect($ppp)->pluck('username')
                        ->merge(collect($hotspot)->pluck('username'))
                        ->filter()
                        ->unique()
                        ->all();
                } catch (\Throwable $e) {
                    // Router TIDAK BISA dihubungi -- tidak bisa
                    // dipastikan, JANGAN ditutup (aman dulu, dicoba lagi
                    // jadwal berikutnya). Ditandai null (beda dari array
                    // kosong "berhasil terhubung tapi memang tidak ada
                    // yang aktif") supaya baris di bawah tahu harus
                    // dilewati, bukan dianggap "terbukti kosong".
                    $daftarAktifPerRouter[$router->id] = null;
                    report($e);
                }
            }

            $daftarAktif = $daftarAktifPerRouter[$router->id];

            if ($daftarAktif === null) {
                continue;
            }

            // PENGAMAN PEMETAAN ROUTER (2026-10-02). Pemetaan NAS -> Router bisa
            // salah (mis. NAS tenant jatuh ke "satu-satunya router aktif" yang
            // sebenarnya BUKAN router mereka). Router yang salah daftarnya tidak
            // memuat satu pun pelanggan NAS ini, sehingga SEMUA sesi yang kabarnya
            // telat tampak "hantu" dan akan ditutup -- padahal pelanggannya online.
            // Router yang benar PASTI mengenali sebagian sesi terbuka NAS itu
            // (termasuk yang kabarnya masih segar). Jadi kalau NAS punya banyak
            // sesi terbuka tapi NOL yang dikenali router -> pemetaan dicurigai,
            // tidak ada yang ditutup untuk NAS ini sampai ada irisan.
            $terbukaNas = Radacct::online()->milikTenantIni()->where('nasipaddress', $nasIp)->pluck('username')->unique();

            if ($terbukaNas->count() >= self::MIN_CURIGA) {
                $irisan = $terbukaNas->filter(fn ($u) => in_array($u, $daftarAktif, true)
                    || in_array(RadiusUsername::stripPrefix($u), $daftarAktif, true))->count();

                if ($irisan === 0) {
                    $pesan = "NAS {$nasIp} -> router \"{$router->name}\" ({$router->ip_address}): {$terbukaNas->count()} sesi terbuka, router mengenali 0 -- pemetaan dicurigai salah, tidak ada yang ditutup.";
                    $this->peringatan[] = $pesan;
                    Log::warning('TutupSesiHantu: '.$pesan);

                    continue;
                }
            }

            foreach ($sesiSatuNas as $sesi) {
                // KOREKSI (2026-09-29): router mencatat username ASLI
                // pelanggan tenant ("dira"), radacct memakai awalan tenant
                // ("goodnet_dira"). Dulu dibandingkan apa adanya -> pelanggan
                // tenant TIDAK PERNAH dikenali online di router -> sesinya
                // ikut ditutup walau masih online. Cocokkan dua-duanya.
                if (in_array($sesi->username, $daftarAktif, true)
                    || in_array(RadiusUsername::stripPrefix($sesi->username), $daftarAktif, true)) {
                    // MASIH ADA di router -- jangan ditutup, apa pun kata
                    // waktunya. Paket accounting-nya yang terlambat/hilang,
                    // bukan sesinya yang putus.
                    continue;
                }

                if ($simulasi) {
                    $diubah++;

                    continue;
                }

                $sesi->update([
                    'acctstoptime' => $sesi->acctupdatetime,
                    'acctsessiontime' => $sesi->acctstarttime
                        ? (int) $sesi->acctstarttime->diffInSeconds($sesi->acctupdatetime, absolute: true)
                        : $sesi->acctsessiontime,
                    'acctterminatecause' => 'Stale-Session-Timeout',
                ]);
                $diubah++;
            }
        }

        return $diubah;
    }
}
