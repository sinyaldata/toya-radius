<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga akses halaman VPN khusus owner — SENGAJA terpisah total dari
 * login Filament/Toya Radius (guard/session key sendiri: "vpn_owner_ok"),
 * supaya walau ada akun Admin/SuperAdmin yang bocor/disalahgunakan,
 * halaman ini tetap butuh password terpisah yang cuma owner tau.
 */
class EnsureVpnOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session('vpn_owner_ok')) {
            return redirect()->route('vpn-owner.login');
        }

        return $next($request);
    }
}
