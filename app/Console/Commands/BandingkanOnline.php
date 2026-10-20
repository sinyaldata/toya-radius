<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Radacct;
use App\Services\MikrotikService;
use App\Services\RouterResolver;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * PEMERIKSA (hanya membaca): bandingkan jumlah "online" menurut database
 * RADIUS (radacct yang acctstoptime-nya kosong -- inilah yang dihitung
 * halaman VPN Owner > User Online) dengan daftar aktif SEBENARNYA di tiap
 * router (/ppp/active + /ip/hotspot/active).
 *
 * Per tenant & per NAS IP menampilkan:
 *  - DB      : sesi terbuka di radacct
 *  - Router  : pengguna aktif di router
 *  - Hantu   : terbuka di DB tapi TIDAK ada di router (angka DB kebesaran)
 *  - Hilang  : aktif di router tapi TIDAK terbuka di DB (angka DB kekecilan)
 *  - NAS IP yang tidak bisa dipetakan ke Router (tidak bisa dicek sama
 *    sekali -- sesinya juga tidak pernah dibersihkan pembersih otomatis)
 *
 * Catatan "Hilang": dihitung dari pengguna router yang username-nya
 * dikenali milik tenant ini (ada di radacct tenant ini dalam 3 hari
 * terakhir), supaya pelanggan tenant lain di router yang sama tidak
 * ikut terhitung.
 */
class BandingkanOnline extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:bandingkan-online {--detail : Tampilkan contoh username hantu/hilang}';

    protected $description = 'Bandingkan jumlah online di database RADIUS dengan daftar aktif router (per tenant & NAS). Hanya membaca.';

    public function handle(RouterResolver $routerResolver, MikrotikService $mikrotikService): int
    {
        $this->runForEachTenant(function ($tenant) use ($routerResolver, $mikrotikService) {
            $this->periksaTenantIni($tenant?->subdomain ?? 'utama', $routerResolver, $mikrotikService);

            return 0;
        });

        return self::SUCCESS;
    }

    public function periksaTenantIni(string $label, RouterResolver $routerResolver, MikrotikService $mikrotikService): array
    {
        $terbuka = Radacct::online()->milikTenantIni()->get(['username', 'nasipaddress']);

        // username tenant ini yang "dikenal" (3 hari terakhir) -> untuk
        // memilah pengguna router yang memang milik tenant ini.
        $dikenal = Radacct::milikTenantIni()
            ->where('acctstarttime', '>=', now()->subDays(3))
            ->distinct()->pluck('username')
            ->mapWithKeys(fn ($u) => [RadiusUsername::stripPrefix($u) => $u]);

        $baris = [];
        $contoh = [];

        // NAS = yang punya sesi terbuka SEKARANG + yang dipakai tenant ini dalam
        // 3 hari terakhir. Bagian kedua penting: tenant tanpa sesi terbuka di
        // database (angka VPN Owner 0) tetap dicek ke routernya -- justru itu
        // yang menunjukkan "ada pelanggan online di router tapi tidak tercatat".
        $nasTiga = Radacct::milikTenantIni()->where('acctstarttime', '>=', now()->subDays(3))->distinct()->pluck('nasipaddress');
        $semuaNas = $terbuka->pluck('nasipaddress')->merge($nasTiga)->filter()->unique()->values();

        foreach ($semuaNas as $nasIp) {
            $db = $terbuka->where('nasipaddress', $nasIp)->pluck('username')->unique();
            $router = $routerResolver->resolveForNasIp($nasIp);

            if (! $router) {
                $nas = \App\Models\Nas::where('nasname', $nasIp)->first();
                $ket = $nas
                    ? "NAS '{$nas->shortname}' ada, tapi router_id ".($nas->router_id ?: 'kosong').' tidak menunjuk Router aktif'
                    : 'IP ini TIDAK ada di tabel NAS maupun Router';
                $baris[] = [$nasIp, "(tidak terpetakan: {$ket})", $db->count(), '-', '-', '?', '?'];

                continue;
            }

            try {
                $aktifRouter = collect($mikrotikService->getActivePppSessions($router))->pluck('username')
                    ->merge(collect($mikrotikService->getActiveHotspotSessions($router))->pluck('username'))
                    ->filter()->unique();
            } catch (\Throwable $e) {
                $baris[] = [$nasIp, $router->name.' @'.$router->ip_address.' (GAGAL dihubungi)', $db->count(), '-', '-', '?', '?'];

                continue;
            }

            $aktifSet = $aktifRouter->flip();
            $hantu = $db->reject(fn ($u) => isset($aktifSet[$u]) || isset($aktifSet[RadiusUsername::stripPrefix($u)]));

            $dbPolos = $db->map(fn ($u) => RadiusUsername::stripPrefix($u))->flip();
            $milikTenant = $aktifRouter->filter(fn ($u) => isset($dikenal[$u]) || $dikenal->contains($u));
            $hilang = $milikTenant->reject(fn ($u) => isset($dbPolos[$u]) || $db->contains($u));

            $baris[] = [$nasIp, $router->name.' @'.$router->ip_address, $db->count(), $aktifRouter->count(), $milikTenant->count(), $hantu->count(), $hilang->count()];
            $contoh[$nasIp] = ['hantu' => $hantu->take(5)->values()->all(), 'hilang' => $hilang->take(5)->values()->all()];
        }

        $this->newLine();
        $this->line("<options=bold>[{$label}]</> total online menurut DB (yang tampil di VPN Owner): ".$terbuka->pluck('username')->unique()->count());

        if ($baris === []) {
            $this->line('  (tidak ada sesi terbuka dan tidak ada aktivitas 3 hari terakhir)');

            return $baris;
        }

        $this->table(['NAS IP', 'Router (nama @IP API)', 'DB', 'Aktif di router (semua)', 'Milik tenant ini', 'Hantu (DB saja)', 'Hilang (router saja)'], $baris);

        if ($this->option('detail')) {
            foreach ($contoh as $nasIp => $c) {
                if ($c['hantu'] || $c['hilang']) {
                    $this->line("  {$nasIp} contoh hantu: ".implode(', ', $c['hantu']).' | contoh hilang: '.implode(', ', $c['hilang']));
                }
            }
        }

        return $baris;
    }
}
