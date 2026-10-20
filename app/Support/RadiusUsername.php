<?php

namespace App\Support;

/**
 * Tabel RADIUS (radcheck/radreply/radacct/dst) sekarang DIPAKAI BERSAMA
 * semua tenant (satu FreeRADIUS, satu database pusat) — jadi username
 * di SITU wajib unik lintas tenant. Data bisnis tenant sendiri (voucher,
 * pelanggan) TETAP simpan username APA ADANYA (tanpa awalan) — biar UI
 * mereka tetap bersih & tidak berubah dari sebelumnya.
 *
 * Tenant UTAMA ("sinyaldata", diakses lewat domain pusat, tanpa
 * subdomain) SENGAJA TIDAK dapat awalan — supaya voucher yang sudah
 * dicetak/beredar sekarang (sebelum multi-tenant ada) tetap jalan tanpa
 * perlu diubah satu-satu.
 */
class RadiusUsername
{
    /**
     * Username ASLI (dari voucher/pelanggan) → username yang BENERAN
     * ditulis ke tabel RADIUS pusat.
     */
    public static function forRadius(string $username): string
    {
        $prefix = static::currentPrefix();

        return $prefix ? "{$prefix}_{$username}" : $username;
    }

    /**
     * Kebalikannya — username dari tabel RADIUS pusat → username ASLI
     * (buat dicocokkan balik ke voucher/pelanggan tenant ini).
     */
    public static function stripPrefix(string $radiusUsername): string
    {
        $prefix = static::currentPrefix();

        if ($prefix && str_starts_with($radiusUsername, "{$prefix}_")) {
            return substr($radiusUsername, strlen($prefix) + 1);
        }

        return $radiusUsername;
    }

    /**
     * Awalan tenant yang SEDANG AKTIF sekarang (null kalau instalasi
     * utama/domain pusat — tidak pakai awalan sama sekali).
     */
    public static function currentPrefix(): ?string
    {
        if (! app()->bound('currentTenant')) {
            return null;
        }

        $tenant = app('currentTenant');

        return $tenant?->subdomain;
    }
}
