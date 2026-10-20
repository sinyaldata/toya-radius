<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Scopes\OutletScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_number',
        'voucher_id',
        'outlet_id',
        'cashier_id',
        'price',
        'discount_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'transaction_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'transaction_date' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OutletScope);

        static::creating(function (self $transaction) {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = 'TRX-'.now()->format('Ymd').'-'.str_pad(
                    (string) (static::whereDate('created_at', now())->count() + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }
        });
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
