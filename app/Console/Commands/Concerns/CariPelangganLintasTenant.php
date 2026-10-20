<?php

namespace App\Console\Commands\Concerns;

use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\Scopes\OutletScope;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Dipakai perintah artisan yang bertindak atas SATU pelanggan tertentu
 * lewat username (isolir, buka isolir, nonaktifkan, dst) -- supaya admin
 * tidak perlu tahu/menyebutkan tenant mana pelanggan itu berada.
 *
 * Tanpa --tenant: dicari berurutan mulai dari instalasi utama, lalu
 * setiap tenant aktif, berhenti di kecocokan PERTAMA (username dianggap
 * unik dalam satu instalasi/tenant, tapi TIDAK dijamin unik lintas
 * tenant -- kalau perlu presisi, admin bisa menyebutkan --tenant).
 *
 * Koneksi database SENGAJA dibiarkan menempel ke tenant tempat
 * pelanggan itu ditemukan (tidak dikembalikan ke database asal) --
 * supaya query/update SETELAHNYA di dalam command yang sama tetap
 * mengenai database yang benar. Ini aman karena tiap pemanggilan
 * `artisan` adalah proses PHP yang berdiri sendiri dan berhenti total
 * begitu command selesai -- tidak ada risiko "nyangkut" ke request
 * lain seperti pada proses PHP-FPM/queue yang berumur panjang.
 */
trait CariPelangganLintasTenant
{
    protected ?Tenant $tenantDitemukan = null;

    protected function cariPelanggan(string $username): ?Customer
    {
        $subdomain = $this->option('tenant');

        if ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();

            if (! $tenant) {
                $this->error("Tenant '{$subdomain}' tidak ditemukan.");
                exit(self::FAILURE);
            }

            $this->pindahKe($tenant);

            return Customer::withoutGlobalScope(OutletScope::class)
                ->where('username', $username)
                ->first();
        }

        // Instalasi utama dulu (tanpa alih koneksi -- pakai .env apa adanya).
        if (app()->bound('currentTenant')) {
            app()->forgetInstance('currentTenant');
        }

        $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
            ->where('username', $username)
            ->first();

        if ($pelanggan) {
            return $pelanggan;
        }

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $this->pindahKe($tenant);

            $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
                ->where('username', $username)
                ->first();

            if ($pelanggan) {
                return $pelanggan;
            }
        }

        return null;
    }

    protected function pindahKe(Tenant $tenant): void
    {
        Config::set('database.connections.mysql.database', $tenant->database_name);
        DB::purge('mysql');
        DB::reconnect('mysql');
        app()->instance('currentTenant', $tenant);
        $this->tenantDitemukan = $tenant;
    }

    protected function labelTenantAktif(): string
    {
        return $this->tenantDitemukan
            ? "tenant {$this->tenantDitemukan->name} ({$this->tenantDitemukan->subdomain})"
            : 'instalasi utama';
    }
}
