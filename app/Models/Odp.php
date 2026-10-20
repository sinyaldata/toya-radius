<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ODP — kotak pembagi di lapangan.
 */
class Odp extends Model
{
    protected $fillable = [
        'olt_id', 'name', 'code', 'total_ports', 'pon_port',
        'latitude', 'longitude', 'address', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'total_ports' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Sisa port yang masih kosong. Dihitung, bukan disimpan — lihat
     * alasannya di migration create_odps_table.
     *
     * Memakai relationCount kalau tersedia (hasil withCount('customers')
     * di query tabel), supaya menampilkan 100 baris ODP tidak jadi 100
     * query terpisah.
     */
    public function sisaPort(): int
    {
        $terpakai = $this->customers_count ?? $this->customers()->count();

        return max(0, (int) $this->total_ports - (int) $terpakai);
    }

    public function portTerpakai(): int
    {
        return (int) ($this->customers_count ?? $this->customers()->count());
    }
}
