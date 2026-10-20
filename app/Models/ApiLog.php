<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    protected $fillable = [
        'service',
        'router_id',
        'action',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'duration_ms',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
