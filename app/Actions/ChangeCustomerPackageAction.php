<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Radacct;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use App\Services\RouterResolver;
use Illuminate\Support\Facades\Log;

/**
 * GANTI PAKET PELANGGAN (PPPoE & Hotspot member) — sekali jalan:
 * ganti paketnya, tulis ulang atribut RADIUS-nya, lalu PUTUS sesi yang
 * sedang aktif supaya pelanggan konek lagi memakai paket BARU.
 *
 * ============================================================
 * KENAPA HARUS DIPUTUS DULU? (ini inti masalahnya)
 * ============================================================
 * Atribut RADIUS (Mikrotik-Rate-Limit, Filter-Id/profile, group)
 * HANYA dibaca router SATU KALI, yaitu pada saat AUTENTIKASI —
 * ketika sesi mulai. Sesudah sesi berjalan, router tidak pernah
 * menanyakannya lagi ke server RADIUS.
 *
 * Akibatnya, kalau paket diganti sementara pelanggan sedang online:
 * datanya di database & RADIUS SUDAH berubah, tapi kecepatan yang
 * benar-benar dipakai router masih yang LAMA — bisa bertahan
 * berhari-hari sampai kebetulan sesinya putus sendiri. Inilah yang
 * bikin operator mengira "ganti paket tidak berfungsi".
 *
 * Ada dua cara membuat paket baru langsung berlaku:
 *
 *  1. CoA / Disconnect-Message RADIUS (paket UDP ke port 3799 router).
 *     Standar resmi, tapi butuh: port 3799 dibuka & bisa dijangkau
 *     server, secret CoA disetel benar di kedua sisi, dan router
 *     dikonfigurasi menerima incoming RADIUS. Kalau salah satu belum
 *     siap, kegagalannya SENYAP (paket UDP hilang tanpa jawaban).
 *
 *  2. Putus sesi lewat API MikroTik — hapus entri di /ppp/active
 *     (PPPoE) atau /ip/hotspot/active (Hotspot).
 *
 * DIPILIH CARA KE-2, karena aplikasi ini SUDAH punya jalur itu dan
 * sudah terbukti dipakai tombol "Kick" di menu PPPoE Online / Hotspot
 * Online (MikroTikService::disconnectUser + RouterResolver). Jadi
 * tidak ada infrastruktur baru yang perlu disiapkan di sisi jaringan,
 * dan kegagalannya kelihatan jelas (ada pesan error, bukan senyap).
 *
 * Setelah diputus:
 *  - PPPoE: modem/router pelanggan otomatis dial ulang dalam hitungan
 *    detik, dan sesi barunya AUTENTIKASI ULANG — di situlah paket baru
 *    terbaca. Pelanggan praktis cuma "kedip" sebentar.
 *  - Hotspot: perlu login ulang, KECUALI perangkatnya sudah terikat
 *    MAC/cookie login otomatis.
 *
 * Kalau pelanggan sedang OFFLINE, tidak ada yang perlu diputus —
 * paket baru otomatis terpakai pada login berikutnya.
 */
class ChangeCustomerPackageAction
{
    public function __construct(
        protected CustomerRadiusService $customerRadiusService,
        protected RouterResolver $routerResolver,
        protected DisconnectUserAction $disconnectUserAction,
    ) {}

    /**
     * @return array{success: bool, message: string, disconnected: bool, warning: ?string}
     */
    public function execute(Customer $customer, ServicePackage $newPackage): array
    {
        $paketLama = $customer->servicePackage;

        // 1) Simpan paket barunya di database.
        $customer->update(['service_package_id' => $newPackage->id]);
        $customer->refresh();

        // 2) Tulis ulang atribut RADIUS-nya. SENGAJA memakai
        //    provisionCustomer() yang sama persis dipakai saat pelanggan
        //    dibuat — jadi tidak ada logika radcheck/radreply versi
        //    kedua yang bisa berbeda perilakunya. Method itu memakai
        //    updateOrCreate, jadi atribut lama ditimpa, bukan dobel.
        try {
            $this->customerRadiusService->provisionCustomer($customer, $newPackage);
        } catch (\Throwable $e) {
            Log::error('Gagal menulis atribut RADIUS saat ganti paket', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Paket sudah diganti di aplikasi, TAPI gagal disinkron ke RADIUS. Perbaiki dulu sebelum memutus sesi.',
                'disconnected' => false,
                'warning' => $e->getMessage(),
            ];
        }

        // 3) Cari sesi yang MASIH aktif (acctstoptime kosong = online).
        $sesi = Radacct::query()
            ->where('username', $customer->username)
            ->online()
            ->latest('acctstarttime')
            ->first();

        $namaPaketLama = $paketLama?->name ?? '-';

        if (! $sesi) {
            return [
                'success' => true,
                'message' => "Paket diubah dari {$namaPaketLama} ke {$newPackage->name}. Pelanggan sedang OFFLINE — paket baru otomatis dipakai saat login berikutnya.",
                'disconnected' => false,
                'warning' => null,
            ];
        }

        // 4) Putus sesinya lewat API MikroTik supaya autentikasi ulang.
        $router = $this->routerResolver->resolveForNasIp($sesi->nasipaddress);

        if (! $router) {
            return [
                'success' => true,
                'message' => "Paket diubah dari {$namaPaketLama} ke {$newPackage->name}, tapi sesi aktifnya TIDAK bisa diputus otomatis.",
                'disconnected' => false,
                'warning' => 'Router untuk NAS '.$sesi->nasipaddress.' tidak ditemukan. Daftarkan IP ini di menu NAS FreeRADIUS dan hubungkan ke Router yang sesuai. Sementara ini paket baru baru berlaku setelah pelanggan putus/konek sendiri.',
            ];
        }

        $hasil = $this->disconnectUserAction->execute($router, $customer->username);

        if (! ($hasil['success'] ?? false)) {
            return [
                'success' => true,
                'message' => "Paket diubah dari {$namaPaketLama} ke {$newPackage->name}, tapi sesi aktifnya gagal diputus.",
                'disconnected' => false,
                // Penyebab paling sering: data online di aplikasi
                // tertinggal (accounting RADIUS belum update) padahal
                // pelanggannya sebenarnya sudah offline.
                'warning' => $hasil['error'] ?? 'Sesi tidak bisa diputus di router.',
            ];
        }

        return [
            'success' => true,
            'message' => "Paket diubah dari {$namaPaketLama} ke {$newPackage->name}. Sesi aktif diputus — pelanggan akan konek ulang otomatis dengan paket baru.",
            'disconnected' => true,
            'warning' => null,
        ];
    }
}
