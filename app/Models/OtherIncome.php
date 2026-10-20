<?php

namespace App\Models;

use App\Enums\IncomeCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendapatan di LUAR penjualan voucher & tagihan PPPoE — mis. biaya pasang
 * baru, penjualan alat, jasa perbaikan. Dipakai juga oleh Laporan Laba/Rugi.
 */
class OtherIncome extends Model
{

    protected $fillable = [
        'income_number',
        'income_date',
        'category',
        'title',
        'amount',
        'payment_method',
        'source',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'income_date' => 'date',
            'category' => IncomeCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $income) {
            if (empty($income->income_number)) {
                $date = $income->income_date ?? now();
                $count = static::query()
                    ->whereYear('income_date', $date->year)
                    ->whereMonth('income_date', $date->month)
                    ->count() + 1;

                $income->income_number = 'INC-'.$date->format('Ym').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            }

            if (empty($income->created_by)) {
                $income->created_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
