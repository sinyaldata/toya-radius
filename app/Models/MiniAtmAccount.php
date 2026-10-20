<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class MiniAtmAccount extends Model
{
    use BelongsToStore;

    protected $table = 'mini_atm_accounts';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
