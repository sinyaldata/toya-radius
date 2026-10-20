<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PlanCatalog extends Model
{
    protected $table = 'plan_catalog';

    protected $connection = 'central';

    protected $fillable = [
        'name',
        'max_concurrent_online',
        'price_per_month',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_per_month' => 'decimal:2',
        ];
    }
}
