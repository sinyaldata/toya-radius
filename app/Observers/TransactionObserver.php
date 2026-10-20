<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Transaction;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'log_name' => 'transaction',
            'description' => "Transaksi {$transaction->transaction_number} dibuat senilai Rp".number_format((float) $transaction->total_amount, 0, ',', '.'),
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'ip_address' => request()?->ip(),
        ]);
    }
}
