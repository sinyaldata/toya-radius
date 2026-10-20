<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'max_concurrent_online',
        'price_per_month',
        'active_until',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'active_until' => 'date',
            'is_active' => 'boolean',
            'price_per_month' => 'decimal:2',
        ];
    }

    /**
     * Paket yang lagi berlaku sekarang — kalau belum ada sama sekali,
     * dianggap paket default longgar (supaya instalasi baru tidak
     * langsung terkunci sebelum owner sempat atur paketnya).
     */
    public static function current(): self
    {
        return static::where('is_active', true)->latest('id')->first()
            ?? new self(['name' => 'Belum diatur', 'max_concurrent_online' => 999999, 'is_active' => true]);
    }

    /**
     * Jumlah user (Hotspot + PPPoE) yang sedang online SEKARANG —
     * dihitung dari session radacct yang masih berjalan (acctstoptime
     * masih kosong).
     */
    /**
     * PENTING: radacct itu tabel PUSAT (dipakai bersama semua tenant,
     * tidak ada kolom tenant_id) — WAJIB filter cuma username milik
     * tenant SEKARANG (lihat App\Support\RadiusUsername), kalau tidak
     * ini menghitung user online SEMUA tenant, bukan cuma tenant ini.
     */
    public static function currentOnlineCount(): int
    {
        // Disaring lewat AWALAN username langsung di SQL (lihat
        // Radacct::scopeMilikTenantIni()). Cara lama memuat seluruh
        // username voucher + pelanggan ke memori dulu -- boros dan tidak
        // perlu, hasilnya sama.
        return Radacct::whereNull('acctstoptime')
            ->milikTenantIni()
            ->distinct('username')
            ->count('username');
    }

    public function isOverLimit(): bool
    {
        return static::currentOnlineCount() >= $this->max_concurrent_online;
    }
}
