<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OLT — perangkat induk FTTH. Satu OLT menaungi banyak ODP.
 */
class Olt extends Model
{
    protected $fillable = [
        'name', 'code', 'brand', 'model', 'ip_address', 'pon_ports',
        'latitude', 'longitude', 'address', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pon_ports' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function odps(): HasMany
    {
        return $this->hasMany(Odp::class);
    }
}
