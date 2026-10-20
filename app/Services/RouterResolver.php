<?php

namespace App\Services;

use App\Models\Nas;
use App\Models\Router;

/**
 * Mencari Router MikroTik yang tepat untuk sebuah alamat NAS dari radacct.
 *
 * Kenapa perlu kelas sendiri: `radacct.nasipaddress` sering BUKAN IP yang sama
 * dengan yang kita simpan di master data Router. Kalau router mengakses server
 * RADIUS lewat internet, IP yang terlihat FreeRADIUS adalah IP publik hasil NAT
 * (mis. 103.x.x.x), sedangkan di master data kita simpan IP LAN/API-nya
 * (mis. 192.168.x.x). Kalau dicocokkan mentah-mentah, router tidak akan pernah
 * ketemu dan tombol Disconnect diam-diam gagal.
 */
class RouterResolver
{
    public function resolveForNasIp(?string $nasIpAddress): ?Router
    {
        if ($nasIpAddress) {
            // 1. Cocok persis dengan IP di master data Router
            $router = Router::where('ip_address', $nasIpAddress)->where('is_active', true)->first();

            if ($router) {
                return $router;
            }

            // 2. Lewat tabel NAS FreeRADIUS: nas.nasname = IP yang terlihat radius,
            //    lalu ikuti relasi router_id-nya.
            $nas = Nas::where('nasname', $nasIpAddress)->first();

            if ($nas?->router_id) {
                $router = Router::where('id', $nas->router_id)->where('is_active', true)->first();

                if ($router) {
                    return $router;
                }
            }
        }

        // 3. Fallback: kalau cuma ada SATU router aktif, sudah pasti itu pelakunya.
        $activeRouters = Router::where('is_active', true)->limit(2)->get();

        return $activeRouters->count() === 1 ? $activeRouters->first() : null;
    }
}
