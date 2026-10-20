<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nas extends Model
{
    protected $connection = 'central';

    protected $table = 'nas';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'tenant_id',
        'nasname',
        'shortname',
        'type',
        'connection_type',
        'vpn_type',
        'api_username',
        'api_password',
        'ports',
        'secret',
        'auth_port',
        'acct_port',
        'timezone',
        'server',
        'community',
        'description',
        'router_id',
    ];

    protected function casts(): array
    {
        return [
            // Sama seperti Router::password — cast aman, tidak bikin
            // halaman error kalau APP_KEY pernah berganti.
            'api_password' => \App\Casts\SafeEncrypted::class,
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected static function boot(): void
    {
        parent::boot();

        // Username & password API MikroTik, DAN RADIUS Secret,
        // di-generate OTOMATIS begitu NAS baru dibuat — supaya user
        // awam tidak perlu mikirin sendiri, langsung dipakai di script
        // setup yang bisa di-copy-paste.
        static::creating(function (self $nas) {
            if (empty($nas->api_username)) {
                $nas->api_username = 'toya_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
            }

            if (empty($nas->api_password)) {
                $nas->api_password = \Illuminate\Support\Str::random(12);
            }

            if (empty($nas->secret)) {
                $nas->secret = \Illuminate\Support\Str::random(20);
            }

            // Tabel `nas` dipakai bersama SEMUA tenant (FreeRADIUS cuma
            // baca dari satu database) — tandai baris ini punya tenant
            // mana, supaya TenantScope bisa memfilternya dengan benar.
            if (empty($nas->tenant_id) && app()->bound('currentTenant')) {
                $nas->tenant_id = app('currentTenant')?->id;
            }
        });
    }
}
