<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Area / Wilayah — pengelompokan mitra (Outlet, Reseller, Biller)
 * berdasarkan wilayah kerja. Dipakai untuk menyaring laporan per
 * wilayah, mis. Laporan Penjualan & Laporan Komisi.
 */
class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Semua mitra (Outlet/Reseller/Biller) di wilayah ini.
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }
}
