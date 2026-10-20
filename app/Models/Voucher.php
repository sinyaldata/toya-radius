<?php

namespace App\Models;

use App\Enums\VoucherStatus;
use App\Models\Scopes\OutletScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'password',
        'batch_code',
        'batch_label',
        'voucher_profile_id',
        'outlet_id',
        'status',
        'cost_price',
        'sale_price',
        'sold_at',
        'activated_at',
        'expired_at',
        'generated_by',
        'generated_via',
        'login_method',
        'code_combination',
        'auto_bind_mac',
        'bound_mac_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            // Nullable: voucher lama (sebelum fitur ini ada) nilainya
            // NULL, jadi jangan diasumsikan selalu terisi.
            'generated_via' => \App\Enums\VoucherSource::class,
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'sold_at' => 'datetime',
            'activated_at' => 'datetime',
            'expired_at' => 'datetime',
            'auto_bind_mac' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OutletScope);

        // Voucher yang dibuat lewat jalur mana pun DI DALAM aplikasi
        // (form Tambah User, Generate, dsb) otomatis ditandai 'app'.
        // Pemanggil dari bot Telegram sudah mengisi 'telegram' sendiri,
        // jadi tidak tertimpa di sini.
        static::creating(function (self $voucher) {
            if (blank($voucher->generated_via)) {
                $voucher->generated_via = \App\Enums\VoucherSource::Aplikasi;
            }
        });

        // Hapus voucher (dari mana pun) WAJIB ikut bersihkan SEMUA data
        // terkaitnya di RADIUS & log — kalau tidak, foreign key
        // constraint di database MENOLAK penghapusan. Riwayat TRANSAKSI
        // (penjualan) TETAP DILINDUNGI TERPISAH — lihat
        // HotspotUserResource::hapusVoucherAman(), yang sengaja
        // MELEWATI voucher yang sudah pernah terjual SEBELUM sampai ke
        // titik ini, supaya laporan pendapatan tidak pernah hilang.
        static::deleting(function (self $voucher) {
            \App\Models\Radcheck::where('username', $voucher->username)->delete();
            \App\Models\Radreply::where('username', $voucher->username)->delete();
            \App\Models\Radusergroup::where('username', $voucher->username)->delete();
            \Illuminate\Support\Facades\DB::table('voucher_logs')->where('voucher_id', $voucher->id)->delete();
            \App\Models\RadiusSessionLog::where('voucher_id', $voucher->id)->delete();
        });
    }

    public function profile(): BelongsTo
    {
        // Sejak "Produk Voucher" dihapus, profile voucher sekarang menunjuk
        // ke Profile Hotspot (service_packages), bukan lagi voucher_profiles.
        return $this->belongsTo(ServicePackage::class, 'voucher_profile_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VoucherLog::class);
    }

    public function radcheck(): HasMany
    {
        return $this->hasMany(Radcheck::class);
    }

    public function radreply(): HasMany
    {
        return $this->hasMany(Radreply::class);
    }

    public function radusergroup(): HasMany
    {
        return $this->hasMany(Radusergroup::class);
    }

    public function radacct(): HasMany
    {
        return $this->hasMany(Radacct::class, 'username', 'username');
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }
}
