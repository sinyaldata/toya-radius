<?php

namespace App\Http\Middleware;

use App\Models\AccessPermission;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokir akses (bukan cuma sembunyikan menu) ke Resource yang grup
 * menu-nya tidak diizinkan untuk role pengguna yang sedang login
 * (dicek lewat AccessPermission::userCanAccessGroup()).
 *
 * Dipasang di route group panel admin (lihat AdminPanelProvider).
 * TIDAK berlaku untuk Dashboard, dan otomatis TIDAK berlaku untuk
 * role 'user' (staff/Admin) — cuma membatasi login Outlet/Reseller/
 * Biller.
 */
class EnsureAccessPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return $next($request);
        }

        // Cari Resource yang match dengan route ini, lewat daftar
        // resource terdaftar di panel (bukan hardcode manual — supaya
        // otomatis ikut kalau ada Resource baru ditambahkan nanti).
        foreach (Filament::getResources() as $resourceClass) {
            $slug = $resourceClass::getSlug();

            if (! str_contains($routeName, ".resources.{$slug}.")) {
                continue;
            }

            $group = $resourceClass::getNavigationGroup();

            if ($group === null) {
                break;
            }

            $groupName = is_string($group) ? $group : (string) $group;

            if (! AccessPermission::userCanAccessGroup($user, $groupName)) {
                abort(403, 'Kamu tidak punya izin akses ke halaman ini.');
            }

            break;
        }

        return $next($request);
    }
}
