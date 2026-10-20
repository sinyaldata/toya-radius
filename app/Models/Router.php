<?php

namespace App\Models;

use App\Enums\RouterConnectionStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    use HasFactory;

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'name',
        'ip_address',
        'api_port',
        'api_mode',
        'www_port',
        'username',
        'password',
        'secret',
        'use_ssl',
        'connection_status',
        'last_checked_at',
        'routeros_version',
        'identity',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            // PENTING: BUKAN cast 'encrypted' bawaan Laravel lagi. Ditemukan
            // lewat error "The MAC is invalid" (DecryptException) yang bikin
            // SELURUH halaman Edit Router down cuma gara-gara satu baris
            // password gagal didekripsi (mis. APP_KEY server sempat
            // berganti). Cast kustom di bawah GAGAL DENGAN AMAN — kalau
            // tidak bisa didekripsi, isinya kosong (bukan error), supaya
            // halaman tetap kebuka dan password bisa diisi ulang.
            'password' => \App\Casts\SafeEncrypted::class,
            'use_ssl' => 'boolean',
            'is_active' => 'boolean',
            'connection_status' => RouterConnectionStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function nas(): HasMany
    {
        return $this->hasMany(Nas::class);
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $router) {
            if (empty($router->tenant_id) && app()->bound('currentTenant')) {
                $router->tenant_id = app('currentTenant')?->id;
            }
        });
    }
}
