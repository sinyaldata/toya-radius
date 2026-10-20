<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessPermission extends Model
{
    protected $fillable = [
        'role_key',
        'allowed_groups',
    ];

    protected function casts(): array
    {
        return [
            'allowed_groups' => 'array',
        ];
    }

    /**
     * Semua nama grup menu yang ada di aplikasi (harus disamakan manual
     * kalau ada NavigationGroup baru ditambahkan di AdminPanelProvider).
     */
    public const ALL_GROUPS = [
        'Mitra', 'Profile Paket', 'Hotspot', 'Invoice', 'Keuangan', 'Pelanggan',
        'Area / Wilayah', 'Master Data', 'Transaksi', 'Laporan', 'Pengaturan', 'Log',
    ];

    public const ROLE_LABELS = [
        'user' => 'User (Staff/Admin)',
        'reseller' => 'Reseller',
        'outlet' => 'Outlet',
        'biller' => 'Biller',
    ];

    /**
     * Ambil "role_key" untuk pengguna yang sedang login — dipakai untuk
     * cek hak aksesnya. Admin/SuperAdmin/Teknisi selalu dianggap 'user'
     * (akses penuh, tidak dibatasi tabel ini sama sekali — tabel ini
     * cuma membatasi login Outlet/Reseller/Biller).
     */
    public static function roleKeyFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($user->role !== \App\Enums\RoleType::Outlet) {
            return 'user';
        }

        // Login Outlet/Reseller/Biller semua RoleType::Outlet yang sama —
        // dibedakan lewat partner_type di data Outlet yang terhubung.
        return match ($user->outlet?->partner_type?->value) {
            'reseller' => 'reseller',
            'biller' => 'biller',
            default => 'outlet',
        };
    }

    /**
     * Cek apakah pengguna yang sedang login boleh lihat grup menu
     * tertentu. Admin/SuperAdmin/Teknisi ('user') SELALU boleh — tabel
     * ini cuma untuk membatasi Outlet/Reseller/Biller.
     */
    public static function userCanAccessGroup(?User $user, string $group): bool
    {
        $roleKey = self::roleKeyFor($user);

        if ($roleKey === null || $roleKey === 'user') {
            return true;
        }

        $permission = self::where('role_key', $roleKey)->first();

        // Belum diatur sama sekali = default boleh semua (supaya tidak
        // tiba-tiba mengunci akses begitu fitur ini dipasang, sebelum
        // admin sempat atur di halaman Hak Akses).
        if (! $permission || $permission->allowed_groups === null) {
            return true;
        }

        return in_array($group, $permission->allowed_groups, true);
    }
}
