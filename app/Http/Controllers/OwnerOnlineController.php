<?php

namespace App\Http\Controllers;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerType;
use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\StaticIpOnlineService;
use App\Support\RadiusUsername;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerOnlineController extends Controller
{
    // Dipakai untuk berpindah ke konteks tiap tenant (database + awalan
    // username) saat mengklasifikasi sesi -- mekanisme yang sama dengan
    // perintah terjadwal (lihat trait-nya).
    use RunsPerTenant;

    protected const KUNCI_UTAMA = '__utama__';

    public function index()
    {
        // PENTING: awalnya kode ini JOIN radacct<->nas lewat IP
        // (nasipaddress = nasname) -- TERNYATA tidak selalu bisa
        // diandalkan, soalnya atribut NAS-IP-Address yang dikirim
        // router bisa beda dari IP publik yang terdaftar di NAS
        // (mis. IP lokal/LAN router, bukan IP yang kita daftarkan).
        // Username yang online justru SELALU benar kena awalan tenant
        // (itu ditentukan FreeRADIUS sendiri waktu login, terbukti
        // benar) -- jadi lebih aman cocokkan lewat AWALAN USERNAME,
        // bukan lewat IP NAS.
        $onlineUsernames = DB::connection('central')
            ->table('radacct')
            ->whereNull('acctstoptime')
            ->distinct()
            ->pluck('username');

        $tenants = Tenant::orderBy('name')->get();
        $subdomains = $tenants->pluck('subdomain')->filter()->values()->all();

        // 1) Kelompokkan username online per tenant lewat AWALAN-nya.
        $grup = array_fill_keys(array_merge([self::KUNCI_UTAMA], $subdomains), []);

        foreach ($onlineUsernames as $username) {
            $kunci = self::KUNCI_UTAMA;

            foreach ($subdomains as $subdomain) {
                if (str_starts_with($username, $subdomain.'_')) {
                    $kunci = $subdomain;
                    break;
                }
            }

            $grup[$kunci][] = $username;
        }

        // Nilai awal: semua sesi RADIUS dianggap Hotspot, Static IP belum
        // diketahui. Tenant yang tidak aktif / gagal diklasifikasi tetap
        // tampil dengan angka ini (ditandai "galat" di bawah).
        $rincian = [];
        foreach ($grup as $kunci => $daftar) {
            $rincian[$kunci] = ['total_radius' => count($daftar), 'pppoe' => 0, 'hotspot' => count($daftar), 'static' => null, 'galat' => false];
        }

        // 2) Klasifikasi di dalam konteks tiap tenant aktif (butuh data
        //    pelanggan tenant itu sendiri). Konteks dikembalikan ke
        //    instalasi utama setelah selesai -- itu sebabnya Setting::get()
        //    di bawah baru dipanggil sesudah loop ini.
        $diproses = [];

        $this->runForEachTenant(function ($tenant) use (&$rincian, &$diproses, $grup) {
            $kunci = $tenant?->subdomain ?? self::KUNCI_UTAMA;

            if (! array_key_exists($kunci, $grup)) {
                return 0;
            }

            $diproses[$kunci] = true;

            try {
                $rincian[$kunci] = $this->rincianDiKonteksIni($grup[$kunci]) + ['galat' => false];
            } catch (\Throwable $e) {
                Log::warning('VPN Owner > Online: gagal merinci tenant '.$kunci.': '.$e->getMessage());
                $rincian[$kunci]['galat'] = true;
            }

            return 0;
        });

        // Tenant yang TIDAK ikut diklasifikasi (status bukan "active", mis. pending/
        // suspended) -- rinciannya tidak diketahui, bukan "semua Hotspot".
        // Ditandai galat supaya tampil "–"; sesi RADIUS-nya tetap terhitung di Total.
        foreach (array_keys($rincian) as $kunci) {
            if (empty($diproses[$kunci])) {
                $rincian[$kunci]['galat'] = true;
            }
        }

        $susun = function (array $r): array {
            if ($r['galat']) {
                // Tidak bisa dirinci: Hotspot & PPPoE dikosongkan (tampil "–"),
                // tapi sesi RADIUS-nya TETAP terhitung di Total.
                $r['hotspot'] = null;
                $r['pppoe'] = null;
                $r['total'] = $r['total_radius'] + ($r['static'] ?? 0);

                return $r;
            }

            $r['total'] = $r['pppoe'] + $r['hotspot'] + ($r['static'] ?? 0);

            return $r;
        };

        $utama = $susun($rincian[self::KUNCI_UTAMA]);

        $barisTenant = $tenants->map(fn ($t) => $susun($rincian[$t->subdomain] ?? ['total_radius' => 0, 'pppoe' => 0, 'hotspot' => 0, 'static' => null, 'galat' => false]) + [
            'name' => $t->name,
            'subdomain' => $t->subdomain,
            'status' => $t->status,
        ]);

        $semua = collect([$utama])->merge($barisTenant);
        $jumlah = [
            'hotspot' => (int) $semua->sum(fn ($r) => $r['hotspot'] ?? 0),
            'pppoe' => (int) $semua->sum(fn ($r) => $r['pppoe'] ?? 0),
            'static' => (int) $semua->sum(fn ($r) => $r['static'] ?? 0),
            'total' => (int) $semua->sum('total'),
        ];

        // Nama & domain instalasi utama dulu ditulis LANGSUNG (hardcode)
        // di file view-nya ("Sinyaldata", "sinyaldata.web.id") -- jadi
        // waktu domain aplikasi ganti (mis. pindah VPS + ganti domain),
        // tampilan di sini tidak ikut berubah walau .env sudah benar.
        // Diambil dari APP_URL & Setting supaya otomatis ikut domain
        // yang sedang aktif, tidak perlu diedit manual lagi tiap ganti
        // domain.
        $mainDomain = parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url');
        $mainName = Setting::get('isp_name', 'Instalasi Utama').' (Instalasi Utama)';

        return view('vpn-owner.online', [
            'tenants' => $barisTenant,
            'utama' => $utama,
            'jumlah' => $jumlah,
            'mainDomain' => $mainDomain,
            'mainName' => $mainName,
        ]);
    }

    /**
     * Rincian online untuk SATU tenant, dihitung di konteks tenant yang
     * SEDANG aktif (database + awalan username sudah dialihkan pemanggil).
     *
     * Definisi sengaja sama dengan halaman-halaman di panel tenant:
     *  - PPPoE    : sesi RADIUS milik pelanggan bertipe PPPoE
     *               (lihat PppoeOnlineResource).
     *  - Hotspot  : SISA sesi RADIUS = voucher + pelanggan member Hotspot.
     *               (Halaman "User Online" Hotspot di panel tenant hanya
     *               menampilkan voucher; member tetap dihitung di sini supaya
     *               PPPoE + Hotspot = seluruh sesi RADIUS yang online.)
     *  - Static IP: tidak lewat RADIUS. Dibaca dari hasil ARP-ping terakhir
     *               (perintah static-ip:cek-online, tiap 5 menit). Yang hasil
     *               pengecekannya lebih dari 20 menit tidak dihitung.
     *
     * @param  array<int, string>  $usernameRadius  username online (berawalan tenant)
     * @return array{total_radius:int, pppoe:int, hotspot:int, static:?int}
     */
    public function rincianDiKonteksIni(array $usernameRadius): array
    {
        $total = count($usernameRadius);
        $asli = collect($usernameRadius)
            ->map(fn ($u) => RadiusUsername::stripPrefix((string) $u))
            ->unique()
            ->values();

        $pppoe = 0;

        foreach ($asli->chunk(800) as $potong) {
            $pppoe += Customer::withoutGlobalScopes()
                ->where('type', CustomerType::Pppoe)
                ->whereIn('username', $potong->all())
                ->count();
        }

        $pppoe = min($pppoe, $total);

        return [
            'total_radius' => $total,
            'pppoe' => $pppoe,
            'hotspot' => $total - $pppoe,
            'static' => $this->hitungStaticIpOnline(),
        ];
    }

    protected function hitungStaticIpOnline(): ?int
    {
        if (! class_exists(StaticIpOnlineService::class)) {
            return null;
        }

        $idOnline = StaticIpOnlineService::freshSets()['online'] ?? [];

        if ($idOnline === []) {
            return 0;
        }

        // Dicocokkan dengan pelanggan Static IP yang MASIH ADA -- hasil
        // cek untuk pelanggan yang sudah dihapus tidak ikut terhitung.
        return Customer::withoutGlobalScopes()
            ->where('type', CustomerType::StaticIp)
            ->whereIn('id', $idOnline)
            ->count();
    }
}
