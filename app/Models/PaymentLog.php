<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLog extends Model
{
    protected $fillable = [
        'customer_id',
        'amount',
        'payment_method',
        'notes',
        'allocations',
        'credit_used',
        'credit_added',
        'recorded_by',
        'biller_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit_used' => 'decimal:2',
            'credit_added' => 'decimal:2',
            'allocations' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Biller yang mencatat pembayaran ini lewat bot Telegram (null kalau
     * dicatat admin/aplikasi langsung).
     */
    public function biller(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'biller_id');
    }
}
