<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu kali setoran uang dari outlet/reseller/biller ke
 * kantor (diterima kasir). Lihat catatan lengkap di migration
 * create_cashier_deposits_table soal kenapa ini tabel terpisah dari
 * payment_logs dan saldo/topup outlet.
 */
class CashierDeposit extends Model
{
    protected $fillable = [
        'outlet_id',
        'amount',
        'deposit_date',
        'payment_method',
        'reference',
        'notes',
        'received_by',
        'biller_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_date' => 'date',
        ];
    }

    /**
     * Mitra yang menyetor — bisa Outlet, Reseller, atau Biller
     * (dibedakan lewat kolom partner_type di tabel outlets).
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Kasir/petugas yang menerima setoran ini.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Biller yang mencatat setoran ini lewat bot Telegram (mis. biller
     * yang keliling menagih outlet lalu melapor lewat bot) — null kalau
     * dicatat kasir langsung dari aplikasi.
     */
    public function biller(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'biller_id');
    }
}
