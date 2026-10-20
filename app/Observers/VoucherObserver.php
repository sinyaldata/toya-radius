<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Voucher;

class VoucherObserver
{
    public function updated(Voucher $voucher): void
    {
        if (! $voucher->isDirty('status')) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'log_name' => 'voucher',
            'description' => "Status voucher {$voucher->username} berubah menjadi {$voucher->status->label()}",
            'subject_type' => Voucher::class,
            'subject_id' => $voucher->id,
            'properties' => [
                'old_status' => $voucher->getOriginal('status'),
                'new_status' => $voucher->status->value,
            ],
            'ip_address' => request()?->ip(),
        ]);
    }

    public function deleted(Voucher $voucher): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'log_name' => 'voucher',
            'description' => "Voucher {$voucher->username} dihapus",
            'subject_type' => Voucher::class,
            'subject_id' => $voucher->id,
            'ip_address' => request()?->ip(),
        ]);
    }
}
