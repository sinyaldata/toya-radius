<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerStatus;
use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\Scopes\OutletScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Perintah INFORMASI SAJA (tidak mengubah apa pun) -- menjawab
 * pertanyaan "berapa banyak pelanggan yang sedang isolir sekarang?"
 * tanpa harus buka aplikasi web.
 *
 * Tanpa --tenant: jalan untuk instalasi utama DAN setiap tenant aktif,
 * lalu ditampilkan per baris plus total gabungan.
 */
class HitungPelangganIsolir extends Command
{
    use RunsPerTenant;

    protected $signature = 'pelanggan:hitung-isolir {--tenant= : Batasi hanya ke satu tenant (kosongkan untuk instalasi utama + semua tenant)}';

    protected $description = 'Tampilkan jumlah pelanggan Isolir (tunggakan) dan Suspend (manual) saat ini -- per tenant, plus totalnya.';

    public function handle(): int
    {
        $subdomain = $this->option('tenant');

        if ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();

            if (! $tenant) {
                $this->error("Tenant '{$subdomain}' tidak ditemukan.");

                return self::FAILURE;
            }

            Config::set('database.connections.mysql.database', $tenant->database_name);
            DB::purge('mysql');
            DB::reconnect('mysql');
            app()->instance('currentTenant', $tenant);

            $this->table(
                ['Tenant', 'Isolir (Tunggakan)', 'Suspend (Manual)', 'Aktif'],
                [$this->hitungBaris($tenant->name)]
            );

            return self::SUCCESS;
        }

        $baris = [];
        $totalIsolir = 0;
        $totalSuspend = 0;
        $totalAktif = 0;

        $this->runForEachTenant(function (?Tenant $tenant) use (&$baris, &$totalIsolir, &$totalSuspend, &$totalAktif) {
            $nama = $tenant ? "{$tenant->name} ({$tenant->subdomain})" : 'Instalasi utama';
            $b = $this->hitungBaris($nama);
            $baris[] = $b;

            $totalIsolir += $b[1];
            $totalSuspend += $b[2];
            $totalAktif += $b[3];

            return 0; // nilai balik dijumlah oleh runForEachTenant, tidak dipakai di sini.
        });

        $baris[] = ['— TOTAL —', $totalIsolir, $totalSuspend, $totalAktif];

        $this->table(['Tenant', 'Isolir (Tunggakan)', 'Suspend (Manual)', 'Aktif'], $baris);

        return self::SUCCESS;
    }

    /**
     * OutletScope SENGAJA dilepas -- ini rekap keseluruhan tenant/instalasi,
     * bukan pandangan satu outlet.
     */
    protected function hitungBaris(string $namaKolom): array
    {
        $dasar = fn () => Customer::withoutGlobalScope(OutletScope::class);

        return [
            $namaKolom,
            $dasar()->where('status', CustomerStatus::Isolir)->count(),
            $dasar()->where('status', CustomerStatus::Suspended)->count(),
            $dasar()->where('status', CustomerStatus::Active)->count(),
        ];
    }
}
