<?php

namespace App\Models;

use App\Enums\LimitType;
use App\Enums\ServicePackageType;
use App\Enums\TimeUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'bandwidth_id',
        'limit_type',
        'time_limit_value',
        'time_limit_unit',
        'quota_limit_mb',
        'validity_days',
        'validity_unit',
        'priority',
        'shared_users',
        'mikrotik_profile',
        'router_id',
        'ip_pool_id',
        'cost_price',
        'sale_price',
        'description',
        'is_active',
        'is_member_only',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServicePackageType::class,
            'limit_type' => LimitType::class,
            'time_limit_unit' => TimeUnit::class,
            'validity_unit' => TimeUnit::class,
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_member_only' => 'boolean',
        ];
    }

    public function bandwidth(): BelongsTo
    {
        return $this->belongsTo(Bandwidth::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function ipPool(): BelongsTo
    {
        return $this->belongsTo(\App\Models\IpPool::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'voucher_profile_id');
    }

    /**
     * Rate limit efektif: utamakan dari relasi Bandwidth, fallback ke kolom
     * rate_limit lama (kolom lama masih ada di DB, cuma tidak dipakai di form
     * baru) untuk paket yang dibuat sebelum modul Bandwidth ada.
     */
    public function getEffectiveRateLimitAttribute(): string
    {
        return $this->bandwidth?->mikrotik_rate_limit ?? ($this->getRawOriginal('rate_limit') ?? '0/0');
    }

    /**
     * Konversi Batas Waktu (time_limit_value + time_limit_unit) ke detik,
     * dipakai sebagai RADIUS Session-Timeout saat provisioning voucher.
     * Null kalau tipe limit bukan "waktu" atau nilainya kosong (unlimited).
     */
    public function getSessionTimeoutSecondsAttribute(): ?int
    {
        if ($this->limit_type !== LimitType::Waktu || ! $this->time_limit_value || ! $this->time_limit_unit) {
            return null;
        }

        return $this->time_limit_unit->toSeconds((int) $this->time_limit_value);
    }

    /**
     * Outlet yang BOLEH pakai paket ini — kalau relasi ini KOSONG,
     * artinya paket boleh dipakai SEMUA outlet (perilaku lama tetap
     * jalan, lihat isAvailableForOutlet() di bawah).
     */
    public function outlets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Outlet::class, 'service_package_outlet');
    }

    /**
     * true kalau paket ini boleh dipakai outlet dengan id tsb — SELALU
     * true kalau paket tidak dibatasi ke outlet manapun (relasi outlets
     * kosong), atau kalau $outletId null (dipakai instalasi/admin
     * pusat, bukan lewat akun outlet tertentu).
     */
    public function isAvailableForOutlet(?int $outletId): bool
    {
        if (! $outletId) {
            return true;
        }

        $restrictedTo = $this->outlets()->pluck('outlets.id');

        return $restrictedTo->isEmpty() || $restrictedTo->contains($outletId);
    }
}
