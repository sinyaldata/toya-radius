<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Radcheck;
use App\Models\Radreply;
use App\Models\Radusergroup;
use App\Models\Scopes\OutletScope;
use App\Models\ServicePackage;
use App\Actions\DisconnectUserAction;
use App\Models\Setting;
use App\Services\RouterResolver;
use App\Support\RadiusUsername;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi Pelanggan tetap (Hotspot & PPPoE) ke tabel standar FreeRADIUS.
 * Beda dengan voucher: pelanggan tetap TIDAK punya Session-Timeout (tidak
 * dibatasi durasi), tapi bisa di-suspend/isolir kapan saja lewat Auth-Type Reject.
 *
 * PELANGGAN STATIC IP (CustomerType::StaticIp) TIDAK ikut lewat jalur
 * RADIUS di atas sama sekali -- tipe ini tidak login (tidak ada
 * username/password yang benar-benar dipakai autentikasi), jadi
 * ke-4 method publik di sini (provision/deprovision/suspend/reactivate)
 * SEKARANG bercabang di awal: kalau tipe pelanggannya Static IP,
 * dialihkan ke MikrotikService (DHCP Static Lease + Simple Queue +
 * address-list isolir) lewat method static*() di bawah, BUKAN menulis
 * radcheck/radreply/radusergroup. Percabangan ditaruh di SINI (bukan di
 * setiap tempat yang memanggil provisionCustomer/suspend/dst) supaya
 * SEMUA pemanggil yang sudah ada (Filament, bot Telegram, command
 * artisan, import CSV, dst -- jumlahnya banyak) otomatis berlaku benar
 * untuk pelanggan Static IP tanpa perlu diubah satu-satu.
 *
 * Panggilan ke MikrotikService dibungkus try/catch supaya kalau router
 * tidak bisa dihubungi (mati/salah kredensial/dll), aksi simpan
 * pelanggan di aplikasi TETAP berhasil -- errornya dicatat ke log biasa
 * (dan ke tabel Log API lewat MikrotikService sendiri), tidak sampai
 * menggagalkan seluruh proses seperti kalau exception dibiarkan lolos.
 */
class CustomerRadiusService
{
    /**
     * Nama PPP Profile bawaan MikroTik. Aplikasi TIDAK PERNAH mengirim
     * Mikrotik-Group berisi nama-nama ini: profile bawaan (mis. "default")
     * tidak punya local/remote-address, jadi pelanggan yang diarahkan ke
     * sana bisa tersambung tanpa IP. Paket bernama begini (mis. "DEFAULT"
     * dari nilai bawaan form lama) tetap berperilaku seperti sebelumnya:
     * router memakai default-profile PPPoE Server-nya.
     */
    public const PROFILE_BAWAAN_MIKROTIK = ['default', 'default-encryption'];

    public function __construct(
        protected MikrotikService $mikrotikService,
        protected ?RouterResolver $routerResolver = null,
        protected ?DisconnectUserAction $disconnectUserAction = null,
    ) {
        $this->routerResolver ??= new RouterResolver;
        $this->disconnectUserAction ??= new DisconnectUserAction($mikrotikService);
    }

    public function provisionCustomer(Customer $customer, ServicePackage $package): void
    {
        if ($customer->type === CustomerType::StaticIp) {
            $this->provisionStaticIpCustomer($customer, $package);

            return;
        }

        $username = RadiusUsername::forRadius($customer->username);

        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Cleartext-Password'],
            ['op' => ':=', 'value' => $customer->password, 'customer_id' => $customer->id]
        );

        // Batas login sekaligus (Simultaneous-Use) HANYA untuk Hotspot
        // Member, besarnya = "Shared Users" di Profile Hotspot paketnya
        // (sama seperti voucher, lihat FreeRadiusService::provisionVoucher).
        // PPPoE SENGAJA TIDAK diberi (dan yang sudah ada ikut dihapus tiap
        // kali provisionCustomer dipanggil).
        //
        // KOREKSI (2026-09-21): dulu nilainya SELALU "1" tanpa melihat paket,
        // jadi member dengan Shared Users 2/3/dst tetap cuma bisa login di
        // satu perangkat. Baru ketahuan setelah FreeRADIUS mulai menegakkan
        // Simultaneous-Use (2026-09-20); sebelumnya batas itu tidak pernah
        // dicek sehingga semua perangkat lolos.
        //
        // ALASAN (2026-09-20): sejak FreeRADIUS menegakkan Simultaneous-
        // Use (session { sql } aktif, dipakai untuk membatasi voucher),
        // pelanggan PPPoE yang koneksinya putus lalu konek ulang (listrik
        // padam, modem restart, kabel goyang) DITOLAK dengan "You are
        // already logged in" selama baris sesi lamanya masih menggantung
        // di radacct (router tidak sempat kirim Accounting-Stop). Di sisi
        // pelanggan/MikroTik tampilnya cuma "authentication failed".
        // Kalau perlu dibatasi satu sesi per akun, atur only-one=yes di
        // PPP Profile MikroTik -- router yang tahu pasti sesi mana hidup.
        if ($customer->type === CustomerType::Pppoe) {
            Radcheck::where('username', $username)
                ->where('attribute', 'Simultaneous-Use')
                ->delete();
        } else {
            Radcheck::updateOrCreate(
                ['username' => $username, 'attribute' => 'Simultaneous-Use'],
                ['op' => ':=', 'value' => (string) static::batasLoginMember($package), 'customer_id' => $customer->id]
            );
        }

        Radreply::updateOrCreate(
            ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
            ['op' => '=', 'value' => $package->effective_rate_limit, 'customer_id' => $customer->id]
        );

        if ($customer->type === CustomerType::Pppoe) {
            Radreply::updateOrCreate(
                ['username' => $username, 'attribute' => 'Service-Type'],
                ['op' => '=', 'value' => 'Framed-User', 'customer_id' => $customer->id]
            );
            Radreply::updateOrCreate(
                ['username' => $username, 'attribute' => 'Framed-Protocol'],
                ['op' => '=', 'value' => 'PPP', 'customer_id' => $customer->id]
            );

            // BARU DITEMUKAN lewat tes langsung: tanpa ini, MikroTik terima
            // login RADIUS tapi TIDAK TAHU harus pakai PPP Profile yang
            // mana � jatuh ke default MikroTik sendiri (bukan profile kita
            // yang sudah benar remote-address/pool-nya), makanya IP jadi
            // 0.0.0.0. Filter-Id ini yang secara eksplisit memberi tahu
            // MikroTik "pakai profile bernama X untuk sesi ini".
            Radreply::updateOrCreate(
                ['username' => $username, 'attribute' => 'Filter-Id'],
                ['op' => '=', 'value' => $package->mikrotik_profile, 'customer_id' => $customer->id]
            );

            // Framed-IP-Address DIMATIKAN LAGI � sudah dites ulang dengan
            // Filter-Id di atas, TETAP ditolak router ("could not determine
            // remote IP address"). Jadi dugaan sebelumnya (Filter-Id yang
            // jadi penyebab) SALAH � router ini memang selalu menolak IP
            // spesifik dari RADIUS untuk PPPoE, apa pun kondisinya.
            // Filter-Id TETAP dipertahankan (beda hal, itu untuk pemilihan
            // profile, bukan IP) � kombinasi "Filter-Id ada + Framed-IP-
            // Address tidak ada" ini belum pernah dites tuntas sebelumnya.
            Radreply::where('username', $username)
                ->where('attribute', 'Framed-IP-Address')
                ->delete();

            // PILIH PPP PROFILE (dan lewat itu: IP Pool, gateway, DNS) LEWAT
            // RADIUS -- atribut Mikrotik-Group.
            //
            // Dokumentasi MikroTik: Mikrotik-Group = "PPP profile for PPP
            // users". Filter-Id di atas BUKAN atribut yang dipakai router
            // untuk memilih profile, jadi tanpa ini SEMUA pelanggan PPPoE
            // jatuh ke default-profile milik PPPoE Server (pool yang sama,
            // apa pun paketnya).
            //
            // SENGAJA hati-hati, karena nama profile yang dikirim HARUS ada
            // di router (kalau tidak, perilaku router belum terjamin):
            //  - Dikendalikan per instalasi/tenant lewat menu Pengaturan >
            //    PPPoE (tabel settings, kunci pppoe_mikrotik_group). Kalau
            //    belum pernah diatur di sana, mengikuti PPPOE_MIKROTIK_GROUP
            //    di .env (bawaan: mati).
            //  - Hanya dikirim untuk paket yang punya IP Pool DAN Router
            //    (artinya profile+pool-nya memang dibuatkan di router oleh
            //    syncPppProfile), dan namanya BUKAN profile bawaan MikroTik
            //    (default / default-encryption -- lihat PROFILE_BAWAAN_MIKROTIK).
            //    Paket lain tidak berubah sama sekali.
            //  - Kalau flag mati, baris ini TIDAK disentuh sama sekali
            //    (termasuk baris uji yang dipasang manual lewat SQL).
            if (static::mikrotikGroupAktif()) {
                if (static::paketMengirimMikrotikGroup($package)) {
                    Radreply::updateOrCreate(
                        ['username' => $username, 'attribute' => 'Mikrotik-Group'],
                        ['op' => '=', 'value' => $package->mikrotik_profile, 'customer_id' => $customer->id]
                    );
                } else {
                    Radreply::where('username', $username)
                        ->where('attribute', 'Mikrotik-Group')
                        ->delete();
                }
            }
        }

        Radusergroup::updateOrCreate(
            ['username' => $customer->username],
            ['groupname' => $package->mikrotik_profile, 'priority' => 1, 'customer_id' => $customer->id]
        );
    }

    /**
     * Batas login sekaligus untuk Member = Shared Users paketnya, minimal 1
     * (kosong/0/negatif dianggap 1 supaya akun tidak pernah terkunci total).
     */
    public static function batasLoginMember(ServicePackage $paket): int
    {
        return max(1, (int) $paket->shared_users);
    }

    /**
     * Apakah instalasi/tenant yang sedang aktif mengirim Mikrotik-Group untuk
     * pelanggan PPPoE? Pengaturan di menu Pengaturan > PPPoE (tabel settings,
     * kunci pppoe_mikrotik_group: '1'/'0') MENANG; kalau belum pernah diatur,
     * mengikuti PPPOE_MIKROTIK_GROUP di .env.
     *
     * Dibaca LANGSUNG dari tabel (bukan lewat Setting::get) supaya tidak
     * tergantung cache "app_settings" -- perintah artisan yang berputar antar
     * tenant harus selalu membaca pengaturan tenant yang sedang aktif.
     */
    public static function mikrotikGroupAktif(): bool
    {
        $nilai = Setting::query()->where('key', 'pppoe_mikrotik_group')->value('value');

        if ($nilai === null) {
            return (bool) config('services.pppoe.mikrotik_group', false);
        }

        return (string) $nilai === '1';
    }

    /**
     * Paket mana yang BOLEH mengirim Mikrotik-Group: punya IP Pool DAN Router
     * (profile+pool-nya memang dibuatkan di router), dan namanya BUKAN
     * profile bawaan MikroTik.
     */
    public static function paketMengirimMikrotikGroup(ServicePackage $paket): bool
    {
        return $paket->ip_pool_id
            && $paket->router_id
            && ! in_array(
                strtolower(trim((string) $paket->mikrotik_profile)),
                self::PROFILE_BAWAAN_MIKROTIK,
                true
            );
    }

    /**
     * Terapkan pengaturan Mikrotik-Group ke SEMUA pelanggan PPPoE tenant yang
     * sedang aktif SEKARANG (dipanggil saat toggle di menu Pengaturan
     * disimpan) -- supaya pemilik tenant tidak perlu SSH/artisan.
     *
     *  - $aktif = true : tulis/perbarui baris Mikrotik-Group untuk pelanggan di
     *    paket yang memenuhi syarat; hapus baris lama untuk pelanggan di paket
     *    yang TIDAK memenuhi syarat.
     *  - $aktif = false: hapus SEMUA baris Mikrotik-Group milik pelanggan PPPoE
     *    tenant ini (kalau tidak, baris lama tetap terkirim walau saklar mati).
     *
     * Hanya menyentuh atribut Mikrotik-Group (tidak menulis ulang kredensial /
     * rate-limit), dan memakai operasi massal per kelompok 500 pelanggan.
     * Sesi yang sedang online baru berpindah setelah konek ulang.
     *
     * @return int jumlah baris RADIUS yang ditulis/diubah/dihapus
     */
    public function terapkanMikrotikGroupTenantIni(bool $aktif): int
    {
        $jumlah = 0;

        $pelangganPppoe = fn () => Customer::withoutGlobalScope(OutletScope::class)
            ->where('type', CustomerType::Pppoe->value)
            ->select(['id', 'username', 'service_package_id']);

        if (! $aktif) {
            $pelangganPppoe()->chunkById(500, function ($kelompok) use (&$jumlah) {
                $jumlah += Radreply::where('attribute', 'Mikrotik-Group')
                    ->whereIn('username', $kelompok->map(fn ($c) => RadiusUsername::forRadius($c->username))->all())
                    ->delete();
            });

            return $jumlah;
        }

        foreach (ServicePackage::whereIn('type', ['pppoe', 'both'])->get() as $paket) {
            $memenuhi = static::paketMengirimMikrotikGroup($paket);

            $pelangganPppoe()
                ->where('service_package_id', $paket->id)
                ->chunkById(500, function ($kelompok) use ($paket, $memenuhi, &$jumlah) {
                    $peta = [];
                    foreach ($kelompok as $c) {
                        $peta[RadiusUsername::forRadius($c->username)] = $c->id;
                    }

                    if (! $peta) {
                        return;
                    }

                    if (! $memenuhi) {
                        $jumlah += Radreply::where('attribute', 'Mikrotik-Group')
                            ->whereIn('username', array_keys($peta))
                            ->delete();

                        return;
                    }

                    $sudahAda = Radreply::where('attribute', 'Mikrotik-Group')
                        ->whereIn('username', array_keys($peta))
                        ->pluck('username')
                        ->all();

                    if ($sudahAda) {
                        $jumlah += Radreply::where('attribute', 'Mikrotik-Group')
                            ->whereIn('username', $sudahAda)
                            ->where('value', '!=', $paket->mikrotik_profile)
                            ->update(['value' => $paket->mikrotik_profile]);
                    }

                    $baru = [];
                    foreach (array_diff(array_keys($peta), $sudahAda) as $u) {
                        $baru[] = [
                            'username' => $u,
                            'attribute' => 'Mikrotik-Group',
                            'op' => '=',
                            'value' => $paket->mikrotik_profile,
                            'customer_id' => $peta[$u],
                        ];
                    }

                    if ($baru) {
                        Radreply::insert($baru);
                        $jumlah += count($baru);
                    }
                });
        }

        return $jumlah;
    }

    public function deprovisionCustomer(Customer $customer): void
    {
        if ($customer->type === CustomerType::StaticIp) {
            $this->deprovisionStaticIpCustomer($customer);

            return;
        }

        Radcheck::where('customer_id', $customer->id)->delete();
        Radreply::where('customer_id', $customer->id)->delete();
        Radusergroup::where('customer_id', $customer->id)->delete();
    }

    /**
     * Mode isolir PPPoE/Hotspot -- lihat App\Filament\Pages\IsolirSettingsPage
     * untuk penjelasan lengkap kedua mode ini.
     *
     *  - 'reject' (bawaan): Auth-Type := Reject, pelanggan tidak bisa
     *    connect sama sekali. Perilaku yang SUDAH ADA sejak awal, tidak
     *    berubah kalau pengaturan ini tidak pernah disentuh.
     *  - 'walled_garden': Mikrotik-Group diisi nama PPP profile isolir
     *    (dipakai mekanisme YANG SAMA dengan Mikrotik-Group per-bandwidth
     *    -- lihat mikrotikGroupAktif() di bawah), supaya pelanggan TETAP
     *    BISA connect tapi masuk ke pool/profile isolir yang trafiknya
     *    dialihkan router ke halaman "mohon bayar tagihan".
     */
    public static function modeIsolir(): string
    {
        $mode = Setting::get('isolir_mode', 'reject');

        return in_array($mode, ['reject', 'walled_garden'], true) ? $mode : 'reject';
    }

    public function suspend(Customer $customer): void
    {
        if ($customer->type === CustomerType::StaticIp) {
            $this->suspendStaticIpCustomer($customer);

            return;
        }

        $username = RadiusUsername::forRadius($customer->username);

        if (static::modeIsolir() === 'walled_garden' && filled($profil = Setting::get('isolir_profile_mikrotik'))) {
            // Mode Halaman Isolir: JANGAN Auth-Type Reject (supaya tetap
            // bisa connect) -- hapus dulu kalau ada sisa dari mode 'reject'
            // sebelumnya, baru tulis Mikrotik-Group ke profile isolir.
            Radcheck::where('username', $username)->where('attribute', 'Auth-Type')->delete();

            Radreply::updateOrCreate(
                ['username' => $username, 'attribute' => 'Mikrotik-Group'],
                ['op' => '=', 'value' => $profil, 'customer_id' => $customer->id]
            );

            // Diputus juga di sini (bukan cuma di mode 'reject' di bawah) --
            // supaya pelanggan LANGSUNG masuk ke profile isolir begitu
            // konek ulang, bukan menunggu dia disconnect sendiri lebih
            // dulu (bisa berjam-jam kalau routernya tidak di-reboot).
            $this->putuskanSesiAktifJikaAda($customer);

            return;
        }

        // Mode Tolak Total (bawaan) -- hapus dulu sisa Mikrotik-Group isolir
        // kalau sebelumnya sempat memakai mode Halaman Isolir, supaya tidak
        // ada baris nyangkut dari mode yang sudah tidak dipakai lagi.
        Radreply::where('username', $username)->where('attribute', 'Mikrotik-Group')
            ->where('value', Setting::get('isolir_profile_mikrotik'))->delete();

        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject', 'customer_id' => $customer->id]
        );

        $this->putuskanSesiAktifJikaAda($customer);
    }

    /**
     * Putuskan sesi PPPoE/Hotspot pelanggan ini SEKARANG JUGA lewat API
     * MikroTik, kalau dia sedang online -- dipanggil dari suspend().
     *
     * KENAPA PERLU (2026-09-26): menulis Auth-Type Reject atau
     * Mikrotik-Group isolir ke RADIUS TIDAK memutus sesi yang SUDAH
     * terhubung -- RADIUS cuma dicek ulang saat pelanggan LOGIN, bukan
     * untuk koneksi yang sedang berjalan. Tanpa ini, admin menekan
     * Suspend tapi pelanggan yang SEDANG ONLINE tetap bisa internetan
     * normal sampai dia sendiri disconnect (mati router/reboot), membuat
     * suspend TERLIHAT seperti tidak bereaksi sama sekali -- padahal
     * RADIUS-nya sudah benar tertulis. Ini mekanisme YANG SAMA dengan
     * tombol Kick Koneksi (App\Actions\DisconnectUserAction) di menu
     * User Online.
     *
     * Diam-diam berhenti (tidak melempar error) kalau pelanggan memang
     * tidak online, atau kalau router untuk sesi itu tidak dikenali --
     * suspend() TETAP DIANGGAP BERHASIL (RADIUS-nya sudah benar) baik
     * pemutusan paksa ini berhasil maupun tidak.
     */
    protected function putuskanSesiAktifJikaAda(Customer $customer): void
    {
        $sesi = $customer->latestSession;

        if (! $sesi || $sesi->acctstoptime !== null) {
            return;
        }

        $router = $this->routerResolver->resolveForNasIp($sesi->nasipaddress);

        if (! $router) {
            return;
        }

        try {
            $this->disconnectUserAction->execute($router, $customer->username, $sesi);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function reactivate(Customer $customer): void
    {
        if ($customer->type === CustomerType::StaticIp) {
            $this->reactivateStaticIpCustomer($customer);

            return;
        }

        $username = RadiusUsername::forRadius($customer->username);

        // Bersihkan jejak KEDUA mode isolir sekaligus -- pelanggan bisa saja
        // diisolir waktu mode-nya masih 'reject', lalu admin ganti mode ke
        // 'walled_garden' SEBELUM pelanggan itu dibuka isolirnya (atau
        // sebaliknya). reactivate() harus bersih dari kondisi apa pun.
        Radcheck::where('username', $username)->where('attribute', 'Auth-Type')->delete();
        Radreply::where('username', $username)->where('attribute', 'Mikrotik-Group')->delete();

        // Tulis ulang SEMUA atribut RADIUS normal pelanggan sesuai paketnya
        // SEKARANG (rate-limit, Mikrotik-Group per-bandwidth kalau
        // paketnya pakai itu, dst.) -- BUKAN cuma menghapus jejak isolir.
        //
        // KENAPA WAJIB (bukan opsional): baris di atas menghapus SELURUH
        // baris Mikrotik-Group milik pelanggan ini, termasuk yang BUKAN
        // dari isolir -- soalnya suspend() mode 'walled_garden' MENIMPA
        // (updateOrCreate) baris Mikrotik-Group yang sudah ada, jadi nilai
        // ASLINYA (kalau paketnya memang pakai pool per-bandwidth) sudah
        // HILANG sejak isolir dipasang, bukan sekadar "tertutup sementara".
        // Tanpa baris ini, pelanggan yang paketnya pakai Mikrotik-Group
        // akan kembali aktif TANPA Mikrotik-Group sampai ada yang
        // provisioning ulang manual -- salah pool tanpa disadari.
        if ($customer->servicePackage) {
            $this->provisionCustomer($customer, $customer->servicePackage);
        }
    }

    // ================================================================
    // PELANGGAN STATIC IP — lewat MikrotikService, BUKAN radcheck/dst.
    // Lihat catatan panjang di atas class ini.
    // ================================================================

    protected function provisionStaticIpCustomer(Customer $customer, ServicePackage $package): void
    {
        $router = $package->router;

        if (! $router) {
            Log::warning('CustomerRadiusService: pelanggan Static IP tidak diprovisioning ke MikroTik -- paketnya belum diisi Router.', [
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
            ]);

            return;
        }

        if (! $customer->mac_address || ! $customer->static_ip) {
            Log::warning('CustomerRadiusService: pelanggan Static IP tidak diprovisioning ke MikroTik -- MAC Address atau IP belum diisi.', [
                'customer_id' => $customer->id,
            ]);

            return;
        }

        $komentar = 'Toya Radius - '.$customer->name.' ('.$customer->customer_number.')';

        try {
            $this->mikrotikService->syncStaticLease($router, $customer->mac_address, $customer->static_ip, $komentar);
            $this->mikrotikService->syncStaticQueue($router, $customer->static_ip, $package->effective_rate_limit, $komentar);

            // Provisioning ulang (mis. lewat Edit) untuk pelanggan yang
            // SEDANG isolir/suspend TIDAK boleh diam-diam membuka
            // blokirnya -- status blokir/buka tetap murni dikendalikan
            // lewat suspend()/reactivate() di bawah (dipanggil terpisah
            // oleh pemanggilnya, persis pola yang sama dengan PPPoE).
        } catch (\Throwable $e) {
            Log::error('CustomerRadiusService: gagal provisioning pelanggan Static IP ke MikroTik.', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function deprovisionStaticIpCustomer(Customer $customer): void
    {
        $router = $customer->servicePackage?->router;

        if (! $router || ! $customer->mac_address) {
            return;
        }

        try {
            $this->mikrotikService->removeStaticLease($router, $customer->mac_address);

            if ($customer->static_ip) {
                $this->mikrotikService->removeStaticQueue($router, $customer->static_ip);
                $this->mikrotikService->unblockStaticCustomer($router, $customer->static_ip);
            }
        } catch (\Throwable $e) {
            Log::error('CustomerRadiusService: gagal deprovisioning pelanggan Static IP dari MikroTik.', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function suspendStaticIpCustomer(Customer $customer): void
    {
        $router = $customer->servicePackage?->router;

        if (! $router || ! $customer->static_ip) {
            Log::warning('CustomerRadiusService: pelanggan Static IP tidak bisa diisolir di MikroTik -- Router atau IP belum diisi.', [
                'customer_id' => $customer->id,
            ]);

            return;
        }

        try {
            $this->mikrotikService->blockStaticCustomer($router, $customer->static_ip, 'Isolir - '.$customer->name);
        } catch (\Throwable $e) {
            Log::error('CustomerRadiusService: gagal mengisolir pelanggan Static IP di MikroTik.', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function reactivateStaticIpCustomer(Customer $customer): void
    {
        $router = $customer->servicePackage?->router;

        if (! $router || ! $customer->static_ip) {
            return;
        }

        try {
            $this->mikrotikService->unblockStaticCustomer($router, $customer->static_ip);
        } catch (\Throwable $e) {
            Log::error('CustomerRadiusService: gagal membuka isolir pelanggan Static IP di MikroTik.', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
