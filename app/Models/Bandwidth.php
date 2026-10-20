<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bandwidth extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'min_upload',
        'max_upload',
        'min_download',
        'max_download',
        'parent_queue_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_upload' => 'integer',
            'max_upload' => 'integer',
            'min_download' => 'integer',
            'max_download' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    /**
     * PERBAIKAN BUG (500 error): kolom "Dipakai Voucher" di tabel
     * Bandwidth (lihat BandwidthResource::table(), ->counts('voucherProfiles'))
     * memanggil relasi ini, tapi relasinya TIDAK PERNAH dibuat sejak
     * awal -- makanya begitu halaman daftar Bandwidth dibuka, Laravel
     * langsung error "Call to undefined method Bandwidth::voucherProfiles()".
     * Ini BUKAN akibat redesain form (form() tidak menyentuh relasi
     * model sama sekali) -- cuma baru ketahuan sekarang karena
     * (kemungkinan) baru sekarang halaman ini benar-benar dibuka.
     *
     * "Voucher" di sini artinya ServicePackage yang BUKAN "Khusus
     * Member" (is_member_only = false, lihat migration
     * add_is_member_only_to_service_packages_table) -- sama definisi
     * "voucher" yang dipakai di seluruh aplikasi & bot Telegram untuk
     * membedakan Profile Voucher vs Profile Member.
     */
    public function voucherProfiles(): HasMany
    {
        return $this->hasMany(ServicePackage::class)->where('is_member_only', false);
    }

    /**
     * Bangun string Mikrotik-Rate-Limit standar dari Min/Max Upload & Download.
     * Format: max-upload/max-download [burst-rx/tx burst-threshold-rx/tx burst-time-rx/tx priority min-upload/min-download]
     *
     * URUTAN: UPLOAD dulu, baru DOWNLOAD. Dokumentasi resmi MikroTik
     * (PPP Profile & atribut RADIUS Mikrotik-Rate-Limit): "rx-rate[/tx-rate]
     * ... from the point of view of the router (so 'rx' is client upload,
     * and 'tx' is client download)". Router MENERIMA (rx) apa yang
     * di-UPLOAD pelanggan, dan MENGIRIM (tx) apa yang di-DOWNLOAD
     * pelanggan.
     *
     * KOREKSI (2026-09-21): versi sebelumnya menulis download/upload dengan
     * komentar "rx = download (ke client)" -- itu KEBALIKAN dari yang
     * dimaksud MikroTik, sehingga di router upload & download tiap
     * pelanggan tertukar (mis. paket Upload 10M / Download 5M tampil dan
     * bekerja sebagai 5M/10M). Paket simetris (10/10) tidak terlihat
     * masalahnya, karena kedua sisinya sama.
     *
     * Kalau min_upload/min_download diisi (>0) dan beda dari max, dianggap sebagai
     * jaminan kecepatan minimum (limit-at) dan ditulis lengkap dengan format burst.
     * Kalau tidak, cukup format simpel "max_upload/max_download".
     */
    public function getMikrotikRateLimitAttribute(): string
    {
        $maxDown = $this->formatRate($this->max_download);
        $maxUp = $this->formatRate($this->max_upload);

        $hasMin = ($this->min_download > 0 && $this->min_download != $this->max_download)
            || ($this->min_upload > 0 && $this->min_upload != $this->max_upload);

        if (! $hasMin) {
            return "{$maxUp}/{$maxDown}";
        }

        $minDown = $this->formatRate($this->min_download ?: $this->max_download);
        $minUp = $this->formatRate($this->min_upload ?: $this->max_upload);

        // burst & threshold dikosongkan (0/0), priority default 8 (normal)
        return "{$maxUp}/{$maxDown} 0/0 0/0 0/0 8 {$minUp}/{$minDown}";
    }

    protected function formatRate(mixed $mbps): string
    {
        $value = (float) $mbps;

        if ($value <= 0) {
            return '0'; // unlimited di Mikrotik
        }

        // Buang desimal .00 kalau bilangan bulat, biar rapi: "5M" bukan "5.00M"
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return "{$formatted}M";
    }

    public function label(): string
    {
        return "{$this->name} ({$this->formatRate($this->max_download)}/{$this->formatRate($this->max_upload)})";
    }
}
