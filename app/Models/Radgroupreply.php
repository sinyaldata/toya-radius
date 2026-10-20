<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Radgroupreply extends Model
{
    protected $table = 'radgroupreply';

    public $timestamps = false;

    protected $fillable = [
        'groupname',
        'attribute',
        'op',
        'value',
    ];
}
