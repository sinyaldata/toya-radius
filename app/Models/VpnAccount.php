<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnAccount extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'username',
        'password',
        'tunnel_ip',
        'vpn_type',
        'hub_name',
        'nas_id',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'password' => SafeEncrypted::class,
            'is_active' => 'boolean',
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function isUsed(): bool
    {
        return $this->nas_id !== null;
    }

    // CATATAN: SENGAJA TIDAK pakai TenantScope global (beda dari Nas &
    // Router) — model ini diakses dari 2 konteks berbeda: (1) dari
    // dalam panel tenant sendiri (butuh filter otomatis berdasar
    // subdomain), (2) dari /vpn-owner (owner PILIH SENDIRI tenant mana
    // lewat dropdown, harus BISA lihat tenant manapun). Kedua tempat
    // itu SUDAH filter tenant_id secara eksplisit sendiri-sendiri.
}
