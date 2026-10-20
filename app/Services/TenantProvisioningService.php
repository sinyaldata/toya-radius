<?php

namespace App\Services;

use App\Enums\RoleType;
use App\Models\Central\PlanCatalog;
use App\Models\Central\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    /**
     * Dipakai form PENDAFTARAN MANDIRI — cuma catat niat daftar sebagai
     * status "pending", TIDAK bikin database apapun. Owner yang review
     * & approve dulu (lewat provision()) baru database beneran dibuat.
     */
    public function registerPending(array $data): Tenant
    {
        $subdomain = Str::slug($data['subdomain']);
        $databaseName = 'toyaradius_'.str_replace('-', '_', $subdomain);

        return Tenant::create([
            'name' => $data['name'],
            'owner_name' => $data['owner_name'] ?? null,
            'subdomain' => $subdomain,
            'database_name' => $databaseName,
            'plan_catalog_id' => $data['plan_catalog_id'] ?? null,
            'billing_due_date' => $data['billing_due_date'] ?? null,
            'owner_email' => $data['owner_email'] ?? null,
            'owner_phone' => $data['owner_phone'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * Bikin tenant baru dari nol DAN LANGSUNG di-provision (dipakai
     * owner waktu bikin tenant manual dari panel — beda dari pendaftaran
     * mandiri yang butuh approve dulu).
     */
    public function createTenant(array $data): Tenant
    {
        $tenant = $this->registerPending($data);

        return $this->provision($tenant);
    }

    /**
     * Provisioning BENERAN: bikin database MySQL, jalankan semua
     * migration aplikasi, buat akun SuperAdmin pertama, isi batas
     * paket sesuai yang dipilih. Dipanggil setelah owner APPROVE
     * pendaftaran (atau langsung, kalau tenant dibuat manual).
     *
     * SYARAT SERVER: user database aplikasi (mis. toyaradius_app) HARUS
     * punya hak CREATE DATABASE — defaultnya kemungkinan TIDAK ada
     * (biasa cuma dikasih akses ke 1 database produksi).
     */
    public function provision(Tenant $tenant): Tenant
    {
        // Diambil dari CONFIG di awal (SEBELUM koneksi dialihkan ke
        // database tenant), bukan env() di resetToMainConnection() --
        // kalau config sedang di-cache (php artisan config:cache), env()
        // di luar file config selalu bernilai null, jadi database malah
        // ke-reset jadi KOSONG, bukan balik ke database utama. Pola yang
        // sama seperti RunsPerTenant::runForEachTenant() & MigrateTenants.
        $mainDatabase = config('database.connections.mysql.database');

        try {
            $this->createDatabase($tenant->database_name);
            $this->runMigrationsForTenant($tenant->database_name);
            $adminPassword = $this->createFirstAdmin($tenant);
            $this->seedSubscriptionPlan($tenant);

            $tenant->update(['status' => 'active', 'provisioned_at' => now()]);
        } finally {
            // WAJIB dikembalikan ke database instalasi UTAMA (yang lagi
            // dipakai owner buat buka halaman ini) — kalau tidak, sisa
            // request ini (dan mungkin request berikutnya di worker PHP
            // yang sama) bisa salah nyasar ke database tenant baru.
            $this->resetToMainConnection($mainDatabase);
        }

        // SSL subdomain — SENGAJA di LUAR try/finally di atas & TIDAK
        // BOLEH menggagalkan seluruh provisioning kalau gagal (mis.
        // Let's Encrypt lagi rate-limit, DNS belum propagate, dst) —
        // tenant TETAP aktif & bisa dipakai lewat HTTP biasa, owner
        // tinggal jalankan skripnya manual kalau ini gagal.
        $sslMessage = $this->provisionSsl($tenant->subdomain);

        return $tenant->fresh()
            ->setAttribute('_generated_admin_password', $adminPassword ?? null)
            ->setAttribute('_ssl_message', $sslMessage);
    }

    /**
     * Jalankan skrip `/usr/local/bin/toya-provision-ssl.sh` — terbitkan
     * sertifikat Let's Encrypt + pasang konfigurasi Nginx khusus
     * subdomain ini secara otomatis. Butuh izin sudo NOPASSWD khusus
     * skrip ini (lihat /etc/sudoers.d/toya-radius-ssl-provision).
     */
    protected function provisionSsl(string $subdomain): ?string
    {
        try {
            $result = \Illuminate\Support\Facades\Process::timeout(120)
                ->run(['sudo', '/usr/local/bin/toya-provision-ssl.sh', $subdomain]);

            if (! $result->successful()) {
                \Illuminate\Support\Facades\Log::warning('Gagal provisioning SSL otomatis untuk tenant baru', [
                    'subdomain' => $subdomain,
                    'output' => $result->errorOutput() ?: $result->output(),
                ]);

                return 'Gagal setup HTTPS otomatis — jalankan manual: sudo /usr/local/bin/toya-provision-ssl.sh '.$subdomain;
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Error provisioning SSL otomatis untuk tenant baru', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage(),
            ]);

            return 'Gagal setup HTTPS otomatis — jalankan manual: sudo /usr/local/bin/toya-provision-ssl.sh '.$subdomain;
        }
    }

    protected function createDatabase(string $databaseName): void
    {
        DB::connection('central')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    /**
     * Alihkan koneksi 'mysql' ke database tenant baru, jalankan migration
     * UTAMA aplikasi (folder database/migrations/ level atas saja —
     * migration khusus database pusat di subfolder central/ SENGAJA
     * tidak ikut ter-scan, itu jalan sendiri terpisah).
     */
    protected function runMigrationsForTenant(string $databaseName): void
    {
        Config::set('database.connections.mysql.database', $databaseName);
        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', [
            '--database' => 'mysql',
            '--force' => true,
        ]);
    }

    protected function createFirstAdmin(Tenant $tenant): string
    {
        $password = Str::random(12);

        User::create([
            'name' => $tenant->owner_name ?: $tenant->name,
            'email' => $tenant->owner_email,
            'username' => $tenant->subdomain.'_admin',
            'password' => Hash::make($password),
            'role' => RoleType::SuperAdmin,
            'is_active' => true,
        ]);

        return $password;
    }

    /**
     * Isi tabel `subscription_plans` DI DALAM database tenant (bukan
     * central) dengan batas online sesuai paket yang dipilih waktu
     * daftar — ini yang dibaca FreeRADIUS buat menegakkan batasnya.
     * Kalau tidak pilih paket apapun, pakai batas default yang longgar
     * (999999) supaya tenant tidak tiba-tiba terkunci.
     */
    protected function seedSubscriptionPlan(Tenant $tenant): void
    {
        $plan = $tenant->plan_catalog_id ? PlanCatalog::find($tenant->plan_catalog_id) : null;

        DB::connection('mysql')->table('subscription_plans')->insert([
            'name' => $plan?->name ?? 'Default',
            'max_concurrent_online' => $plan?->max_concurrent_online ?? 999999,
            'price_per_month' => $plan?->price_per_month,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function resetToMainConnection(string $mainDatabase): void
    {
        Config::set('database.connections.mysql.database', $mainDatabase);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}
