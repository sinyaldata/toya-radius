<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Radusergroup extends Model
{
    protected $table = 'radusergroup';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'groupname',
        'priority',
        'voucher_id',
        'customer_id',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
