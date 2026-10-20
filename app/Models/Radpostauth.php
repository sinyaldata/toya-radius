<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Radpostauth extends Model
{
    protected $table = 'radpostauth';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'pass',
        'reply',
        'authdate',
        'class',
    ];

    protected function casts(): array
    {
        return [
            'authdate' => 'datetime',
        ];
    }
}
