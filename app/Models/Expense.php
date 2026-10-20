<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{

    protected $fillable = [
        'outlet_id',
        'expense_number',
        'expense_date',
        'category',
        'title',
        'amount',
        'payment_method',
        'recipient',
        'notes',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'category' => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $expense) {
            if (empty($expense->expense_number)) {
                $period = ($expense->expense_date ?? now())->format('Ym');
                $count = static::query()
                    ->whereYear('expense_date', ($expense->expense_date ?? now())->year)
                    ->whereMonth('expense_date', ($expense->expense_date ?? now())->month)
                    ->count() + 1;
                $expense->expense_number = "EXP-{$period}-".str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            }

            if (empty($expense->created_by)) {
                $expense->created_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Outlet pelapor -- HANYA terisi untuk pengeluaran yang dicatat
     * outlet sendiri lewat bot Telegram (/pengeluaran). Pengeluaran
     * pusat yang dicatat lewat aplikasi tetap null di sini seperti
     * sebelumnya.
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
