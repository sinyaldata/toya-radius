<?php

namespace App\Actions;

use App\Models\Radacct;
use App\Models\Router;
use App\Services\MikrotikService;

class DisconnectUserAction
{
    public function __construct(protected MikrotikService $mikrotikService) {}

    /**
     * Putuskan koneksi user lewat API MikroTik. Kalau $sesi diberikan DAN
     * pemutusan berhasil, baris sesi itu LANGSUNG ditutup juga di database
     * (acctstoptime diisi sekarang) -- TIDAK MENUNGGU MikroTik mengirim
     * balik Accounting-Stop ke FreeRADIUS.
     *
     * KENAPA PERLU (2026-09-23): sebelumnya method ini HANYA bicara ke
     * MikroTik, lalu berhenti -- baris radacct dibiarkan menunggu paket
     * Accounting-Stop dari router sendiri, sebuah proses TERPISAH yang
     * asinkron. Kalau paket itu telat atau tidak pernah sampai (jaringan
     * lambat, listrik berkedip, atau kondisi lain seperti "sesi hantu"
     * yang sudah pernah ditemukan sebelumnya), sesi yang SUDAH DIPUTUS di
     * router tetap tampil "Online" di aplikasi -- persis laporan user:
     * "kick voucher online tapi sesinya tidak ikut terhapus".
     *
     * Menutup baris di sini itu SEARAH SAJA (PHP -> database) dan aman
     * diulang: kalau MikroTik BELAKANGAN tetap mengirim Accounting-Stop
     * untuk sesi yang sama, FreeRADIUS cuma menimpa acctstoptime dengan
     * nilai yang mirip -- tidak ada baris ganda atau error.
     */
    public function execute(Router $router, string $username, ?Radacct $sesi = null): array
    {
        $hasil = $this->mikrotikService->disconnectUser($router, $username);

        if ($hasil['success'] && $sesi && $sesi->acctstoptime === null) {
            $sesi->update([
                'acctstoptime' => now(),
                'acctsessiontime' => $sesi->acctstarttime ? (int) $sesi->acctstarttime->diffInSeconds(now(), absolute: true) : $sesi->acctsessiontime,
                'acctterminatecause' => 'Admin-Reset',
            ]);
        }

        return $hasil;
    }
}
