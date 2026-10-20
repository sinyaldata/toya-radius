<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Radacct;
use App\Services\MikrotikService;
use App\Services\RouterResolver;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * PEMULIH korban bug ambang-batas radius:tutup-sesi-hantu yang dulu
 * terlalu ketat (15 menit, lihat catatan di TutupSesiHantu.php) --
 * menutup sesi yang SEBENARNYA masih hidup.
 *
 * TANDA PASTI sebuah sesi SALAH DITUTUP (dibuktikan langsung lewat kasus
 * nyata "abdulrohman@170726", 2026-09-28): acctterminatecause =
 * 'Stale-Session-Timeout' TAPI acctupdatetime-nya LEBIH BARU dari
 * acctstoptime. Ini TIDAK MUNGKIN terjadi pada sesi yang genuinely
 * sudah putus -- artinya router/pelanggan itu TERUS mengirim kabar
 * (Interim-Update) SETELAH kita "tutup" sesinya secara keliru; baris
 * radacct-nya diperbarui lagi oleh FreeRADIUS (acctupdatetime maju),
 * tapi acctstoptime yang sudah terlanjur terisi TIDAK ikut dikosongkan
 * lagi oleh FreeRADIUS (bukan tanggung jawabnya).
 *
 * Pemulihan: acctstoptime & acctterminatecause dikosongkan lagi
 * (kembali "Online"), acctsessiontime ikut direset (tidak akurat lagi
 * untuk dihitung, dibiarkan NULL daripada menyesatkan).
 *
 * SETELAH pemulihan ini, sesi yang MEMANG BENAR SUDAH HANTU (bukan
 * korban bug) akan tertutup lagi secara wajar oleh jadwal
 * radius:tutup-sesi-hantu (ambang batas barunya 120 menit) begitu
 * kabar terakhirnya benar-benar lewat 2 jam.
 *
 * Contoh:
 *   php artisan radius:pulihkan-sesi-salah-tutup --dry-run
 *   php artisan radius:pulihkan-sesi-salah-tutup
 */
class PulihkanSesiSalahTutup extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:pulihkan-sesi-salah-tutup
        {--dry-run : Cuma tampilkan berapa yang akan dipulihkan, tidak menulis apa pun}
        {--tanpa-router : Lewati pemeriksaan ke router (cuma pakai tanda acctupdatetime > acctstoptime)}';

    protected $description = 'Pulihkan sesi yang salah ditutup radius:tutup-sesi-hantu versi lama (ambang batas 15 menit, terbukti terlalu ketat) -- kembalikan jadi Online lagi kalau terbukti masih ada kabar sesudahnya.';

    public function handle(): int
    {
        $simulasi = (bool) $this->option('dry-run');

        $pakaiRouter = ! $this->option('tanpa-router');

        $total = $this->runForEachTenant(function ($tenant) use ($simulasi, $pakaiRouter) {
            $label = $tenant?->subdomain ?? 'utama';
            $jumlah = $this->pulihkanTenantIni($simulasi);

            if ($jumlah > 0) {
                $this->line(sprintf('[%s] %d sesi %s dipulihkan (terbukti masih ada kabar sesudah "ditutup")', $label, $jumlah, $simulasi ? 'AKAN' : ''));
            }

            if ($pakaiRouter) {
                $lewatRouter = $this->pulihkanLewatRouter($simulasi, app(RouterResolver::class), app(MikrotikService::class));

                if ($lewatRouter > 0) {
                    $this->line(sprintf('[%s] %d sesi %s dipulihkan (router mengonfirmasi pelanggannya MASIH online)', $label, $lewatRouter, $simulasi ? 'AKAN' : ''));
                }

                $jumlah += $lewatRouter;
            }

            return $jumlah;
        });

        $this->newLine();
        $this->info($simulasi
            ? "{$total} sesi AKAN dipulihkan (dry-run, belum ada yang ditulis) -- gabungan semua tenant."
            : "{$total} sesi dipulihkan -- gabungan semua tenant.");

        return self::SUCCESS;
    }

    public function pulihkanTenantIni(bool $simulasi): int
    {
        $query = Radacct::milikTenantIni()
            ->where('acctterminatecause', 'Stale-Session-Timeout')
            ->whereNotNull('acctstoptime')
            ->whereColumn('acctupdatetime', '>', 'acctstoptime');

        if ($simulasi) {
            return $query->count();
        }

        $dipulihkan = 0;

        $query->chunkById(500, function ($baris) use (&$dipulihkan) {
            foreach ($baris as $sesi) {
                $sesi->update([
                    'acctstoptime' => null,
                    // '' (BUKAN null) -- kolom acctterminatecause di
                    // database TIDAK BOLEH NULL (NOT NULL constraint).
                    // Sesi yang genuinely masih berjalan pun nilainya ''
                    // (string kosong), bukan NULL -- ini yang dipakai
                    // FreeRADIUS sendiri untuk "belum ada penyebab
                    // berhenti", jadi konsisten dengan sesi yang tidak
                    // pernah disentuh sama sekali.
                    'acctterminatecause' => '',
                    'acctsessiontime' => null,
                ]);
                $dipulihkan++;
            }
        }, column: 'radacctid');

        return $dipulihkan;
    }

    /**
     * Pemulihan TAHAP 2 (2026-09-29): sesi yang ditutup pembersih
     * (Stale-Session-Timeout, 3 hari terakhir) padahal pelanggannya MASIH
     * ADA di daftar aktif router. Dibutuhkan karena versi pembersih
     * sebelumnya salah mencocokkan username tenant (router "dira", radacct
     * "goodnet_dira"), dan kalau router tidak mengirim Interim-Update, tanda
     * acctupdatetime > acctstoptime tidak pernah muncul.
     *
     * Hanya baris TERAKHIR per username, dan hanya kalau username itu tidak
     * punya sesi terbuka lain (kalau sudah konek ulang, sesi barunya yang
     * dipakai -- tidak dipulihkan). Router tidak bisa dihubungi -> dilewati.
     */
    public function pulihkanLewatRouter(bool $simulasi, RouterResolver $routerResolver, MikrotikService $mikrotikService): int
    {
        $tertutup = Radacct::milikTenantIni()
            ->where('acctterminatecause', 'Stale-Session-Timeout')
            ->whereNotNull('acctstoptime')
            ->where('acctstoptime', '>=', now()->subDays(3))
            ->orderByDesc('radacctid')
            ->get()
            ->unique('username');

        if ($tertutup->isEmpty()) {
            return 0;
        }

        $masihTerbuka = Radacct::online()->milikTenantIni()
            ->whereIn('username', $tertutup->pluck('username'))
            ->pluck('username')
            ->flip();

        $kandidat = $tertutup->reject(fn ($s) => isset($masihTerbuka[$s->username]));
        $dipulihkan = 0;

        foreach ($kandidat->groupBy('nasipaddress') as $nasIp => $sesiSatuNas) {
            $router = $routerResolver->resolveForNasIp($nasIp);

            if (! $router) {
                continue;
            }

            try {
                $aktif = collect($mikrotikService->getActivePppSessions($router))->pluck('username')
                    ->merge(collect($mikrotikService->getActiveHotspotSessions($router))->pluck('username'))
                    ->filter()->flip();
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            foreach ($sesiSatuNas as $sesi) {
                if (! isset($aktif[$sesi->username]) && ! isset($aktif[RadiusUsername::stripPrefix($sesi->username)])) {
                    continue;
                }

                if (! $simulasi) {
                    $sesi->update(['acctstoptime' => null, 'acctterminatecause' => '', 'acctsessiontime' => null]);
                }

                $dipulihkan++;
            }
        }

        return $dipulihkan;
    }
}
