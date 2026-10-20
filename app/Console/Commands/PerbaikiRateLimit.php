<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Radreply;
use App\Models\Scopes\OutletScope;
use App\Models\Voucher;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * Perintah SEKALI JALAN -- tulis ulang atribut Mikrotik-Rate-Limit yang SUDAH
 * tersimpan di radreply dengan urutan yang benar (UPLOAD/DOWNLOAD).
 *
 * Kenapa perlu: perbaikan di Bandwidth::getMikrotikRateLimitAttribute()
 * hanya berlaku untuk pelanggan/voucher yang di-provisioning SETELAH kode
 * baru terpasang. Yang sudah tersimpan tetap membawa urutan lama
 * (download/upload = terbalik di MikroTik) sampai ditulis ulang.
 *
 * Aman dijalankan BERKALI-KALI (idempoten): nilainya selalu dihitung ulang
 * dari paket/bandwidth saat ini, BUKAN "ditukar" dari nilai lama -- jadi
 * menjalankannya dua kali tidak membalik lagi.
 *
 * Hanya MENGUBAH baris yang sudah ada (tidak membuat baris baru) dan hanya
 * kalau nilainya memang berbeda. Sesi yang sedang online baru memakai nilai
 * baru setelah konek ulang.
 *
 * Pelanggan Static IP tidak lewat radreply (batasnya ada di Simple Queue
 * router) -- untuk mereka pakai:
 *   php artisan radius:sync-customers --type=static_ip --force [--tenant=...]
 */
class PerbaikiRateLimit extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:perbaiki-rate-limit {--dry-run : Cuma tampilkan berapa yang akan diubah, tidak menulis apa pun}';

    protected $description = 'Perbaikan SEKALI JALAN: tulis ulang Mikrotik-Rate-Limit di radreply (pelanggan PPPoE/Member & voucher) dengan urutan UPLOAD/DOWNLOAD yang benar. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(): int
    {
        $simulasi = (bool) $this->option('dry-run');

        $total = $this->runForEachTenant(function ($tenant) use ($simulasi) {
            [$pelanggan, $voucher] = $this->perbaikiTenantIni($simulasi);

            $this->line(sprintf(
                '[%s] pelanggan diubah %d, voucher diubah %d',
                $tenant?->subdomain ?? 'utama',
                $pelanggan,
                $voucher
            ));

            return $pelanggan + $voucher;
        });

        $this->info($simulasi
            ? "{$total} baris AKAN diubah (dry-run, belum ada yang ditulis) -- gabungan semua tenant."
            : "{$total} baris Mikrotik-Rate-Limit ditulis ulang -- gabungan semua tenant.");

        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int}  [jumlah pelanggan diubah, jumlah voucher diubah]
     */
    public function perbaikiTenantIni(bool $simulasi): array
    {
        $pelanggan = 0;
        $voucher = 0;

        Customer::withoutGlobalScope(OutletScope::class)
            ->with('servicePackage.bandwidth')
            ->where('type', '!=', CustomerType::StaticIp->value)
            ->whereNotNull('service_package_id')
            ->chunkById(500, function ($kelompok) use (&$pelanggan, $simulasi) {
                foreach ($kelompok as $c) {
                    if (! $c->servicePackage) {
                        continue;
                    }

                    $pelanggan += $this->tulisUlang(
                        RadiusUsername::forRadius($c->username),
                        $c->servicePackage->effective_rate_limit,
                        $simulasi
                    );
                }
            });

        Voucher::withoutGlobalScope(OutletScope::class)
            ->with('profile.bandwidth')
            ->whereNotNull('voucher_profile_id')
            ->chunkById(500, function ($kelompok) use (&$voucher, $simulasi) {
                foreach ($kelompok as $v) {
                    if (! $v->profile) {
                        continue;
                    }

                    $voucher += $this->tulisUlang(
                        RadiusUsername::forRadius($v->username),
                        $v->profile->effective_rate_limit,
                        $simulasi
                    );
                }
            });

        return [$pelanggan, $voucher];
    }

    /** 1 kalau barisnya ada & nilainya berbeda (diubah), 0 kalau tidak. */
    protected function tulisUlang(string $usernameRadius, string $nilaiBenar, bool $simulasi): int
    {
        $baris = Radreply::where('username', $usernameRadius)
            ->where('attribute', 'Mikrotik-Rate-Limit')
            ->first();

        if (! $baris || $baris->value === $nilaiBenar) {
            return 0;
        }

        if (! $simulasi) {
            $baris->update(['value' => $nilaiBenar]);
        }

        return 1;
    }
}
