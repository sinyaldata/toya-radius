<?php

namespace App\Filament\Concerns;

use App\Models\AccessPermission;

/**
 * Menyambungkan resource ke sistem "Hak Akses" (lihat AccessRightsPage +
 * AccessPermission) — TANPA trait ini, checkbox yang diatur admin di
 * halaman Pengaturan > Hak Akses tersimpan ke database tapi TIDAK
 * benar-benar menyembunyikan apa pun (cuma data, belum diterapkan).
 *
 * Pakai `use ChecksGroupAccess;` di resource mana pun yang perlu ikut
 * dibatasi lewat halaman Hak Akses. Resource yang SUDAH punya logic
 * shouldRegisterNavigation()/canViewAny() sendiri (mis. blokir Outlet
 * total) TIDAK perlu/TIDAK pakai trait ini, biar tidak bentrok.
 *
 * Login staff (Admin/SuperAdmin/Teknisi) selalu tembus (lihat
 * AccessPermission::userCanAccessGroup()) — trait ini cuma benar-benar
 * membatasi login Outlet/Reseller/Biller.
 */
trait ChecksGroupAccess
{
    public static function shouldRegisterNavigation(): bool
    {
        $group = property_exists(static::class, 'accessNavigationGroup') && static::$accessNavigationGroup !== null
            ? static::$accessNavigationGroup
            : static::$navigationGroup;

        return AccessPermission::userCanAccessGroup(auth()->user(), (string) $group);
    }

    public static function canViewAny(): bool
    {
        $group = property_exists(static::class, 'accessNavigationGroup') && static::$accessNavigationGroup !== null
            ? static::$accessNavigationGroup
            : static::$navigationGroup;

        return AccessPermission::userCanAccessGroup(auth()->user(), (string) $group);
    }
}
