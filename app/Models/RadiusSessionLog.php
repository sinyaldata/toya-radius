<?php

namespace App\Models;

use App\Enums\RadiusUserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiusSessionLog extends Model
{
    protected $fillable = [
        'username',
        'user_type',
        'event',
        'acctsessionid',
        'framedipaddress',
        'callingstationid',
        'nasipaddress',
        'session_time',
        'input_octets',
        'output_octets',
        'terminate_cause',
        'voucher_id',
        'customer_id',
        'event_at',
    ];

    protected function casts(): array
    {
        return [
            'user_type' => RadiusUserType::class,
            'event_at' => 'datetime',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getDownloadMbAttribute(): float
    {
        return round(((int) $this->output_octets) / 1048576, 2);
    }

    public function getUploadMbAttribute(): float
    {
        return round(((int) $this->input_octets) / 1048576, 2);
    }
}
