<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Radacct extends Model
{
    protected $connection = 'central';

    protected $table = 'radacct';

    protected $primaryKey = 'radacctid';

    public $timestamps = false;

    protected $fillable = [
        'acctsessionid',
        'acctuniqueid',
        'username',
        'groupname',
        'realm',
        'nasipaddress',
        'nasportid',
        'nasporttype',
        'acctstarttime',
        'acctupdatetime',
        'acctstoptime',
        'acctinterval',
        'acctsessiontime',
        'acctauthentic',
        'connectinfo_start',
        'connectinfo_stop',
        'acctinputoctets',
        'acctoutputoctets',
        'calledstationid',
        'callingstationid',
        'acctterminatecause',
        'servicetype',
        'framedprotocol',
        'framedipaddress',
    ];

    protected function casts(): array
    {
        return [
            'acctstarttime' => 'datetime',
            'acctupdatetime' => 'datetime',
            'acctstoptime' => 'datetime',
        ];
    }

    /**
     * BUKAN relasi Eloquent biasa (BelongsTo) lagi — username di radacct
     * sekarang bisa dapat awalan tenant (lihat App\Support\RadiusUsername),
     * jadi harus dilepas dulu sebelum dicocokkan ke voucher.username milik
     * tenant sendiri (yang TETAP tanpa awalan).
     */
    public function voucher(): ?Voucher
    {
        return Voucher::where('username', \App\Support\RadiusUsername::stripPrefix($this->username))->first();
    }

    public function scopeOnline($query)
    {
        return $query->whereNull('acctstoptime');
    }

    /**
     * Batasi query hanya ke baris milik tenant yang SEDANG aktif.
     *
     * Menggantikan cara lama yang memuat SELURUH username voucher +
     * pelanggan ke memori lalu mengirimkannya sebagai daftar IN(...)
     * raksasa. Cara itu mahal di dua sisi sekaligus: PHP menyimpan
     * ratusan ribu string, dan MariaDB harus mengurai daftar IN yang
     * panjangnya bisa megabyte -- untuk hasil yang sama persis.
     *
     * Penyaringannya memakai AWALAN username, karena itulah yang
     * membedakan pemilik baris di radacct (lihat App\Support\RadiusUsername):
     *
     *   - Tenant  : username-nya PASTI berawalan "<subdomain>_".
     *   - Instalasi utama: TIDAK berawalan apa pun, jadi disaring dengan
     *     membuang semua yang berawalan salah satu subdomain tenant.
     *     Daftar subdomain itu jumlahnya sedikit (sebanyak tenant), beda
     *     jauh dengan daftar username.
     *
     * Garis bawah "_" adalah karakter khusus di LIKE (cocok dengan satu
     * karakter apa pun), jadi WAJIB di-escape -- tanpa itu awalan
     * "sinyalta_" juga akan cocok dengan "sinyaltaX".
     */
    public function scopeMilikTenantIni($query)
    {
        $prefix = \App\Support\RadiusUsername::currentPrefix();

        if ($prefix) {
            return $query->where('username', 'like', str_replace('_', '\\_', $prefix).'\\_%');
        }

        // Instalasi utama: buang baris milik tenant manapun.
        try {
            $subdomains = \App\Models\Central\Tenant::query()->pluck('subdomain');
        } catch (\Throwable $e) {
            // Tabel pusat tidak terbaca (mis. instalasi lama tanpa
            // multi-tenant). Lebih baik tidak menyaring apa-apa daripada
            // menjatuhkan halaman -- hasilnya sama seperti sebelum fitur
            // multi-tenant ada.
            return $query;
        }

        foreach ($subdomains as $sub) {
            if ($sub) {
                $query->where('username', 'not like', str_replace('_', '\\_', $sub).'\\_%');
            }
        }

        return $query;
    }

    public function getDownloadMbAttribute(): float
    {
        return round(((int) $this->acctoutputoctets) / 1048576, 2);
    }

    public function getUploadMbAttribute(): float
    {
        return round(((int) $this->acctinputoctets) / 1048576, 2);
    }
}
