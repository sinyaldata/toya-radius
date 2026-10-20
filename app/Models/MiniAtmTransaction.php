<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiniAtmTransaction extends Model
{
    use BelongsToStore;

    public const TYPES = [
        'tarik' => 'Tarik Tunai',
        'setor' => 'Setor Tunai',
        'transfer' => 'Transfer',
        'isi_saldo' => 'Isi Saldo Rekening',
        'tarik_saldo' => 'Tarik Saldo ke Kas',
        'pindah' => 'Pindah Antar Rekening',
        'koreksi' => 'Koreksi Saldo',
    ];

    /** Jenis yang menghasilkan pendapatan (jasa). */
    public const INCOME_TYPES = ['tarik', 'setor', 'transfer'];

    protected $table = 'mini_atm_transactions';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'fee' => 'float',
        'bank_fee' => 'float',
        'cash_effect' => 'float',
        'bank_effect' => 'float',
        'is_void' => 'boolean',
        'transacted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MiniAtmAccount::class, 'account_id')->withoutGlobalScopes();
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(MiniAtmAccount::class, 'to_account_id')->withoutGlobalScopes();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_void', false);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getProfitAttribute(): float
    {
        return $this->fee - $this->bank_fee;
    }
}
