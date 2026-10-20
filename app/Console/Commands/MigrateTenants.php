<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan migration aplikasi ke SEMUA database tenant.
 *
 * KENAPA PERLU: tiap tenant (subdomain) punya database sendiri.
 * `php artisan migrate` biasa HANYA mengenai database utama (.env
 * DB_DATABASE) — database tenant TIDAK ikut. Migration tenant selama
 * ini cuma dijalankan sekali waktu tenant dibuat (lihat
 * TenantProvisioningService), jadi setiap ada migration BARU setelah
 * itu, database tenant ketinggalan dan halaman yang memakai kolom/tabel
 * baru itu langsung Error 500 di subdomain tenant.
 *
 * Pakai perintah ini SETIAP KALI habis menambah migration baru:
 *
 *     php artisan migrate --force        # database utama
 *     php artisan tenants:migrate        # semua database tenant
 *
 * Migration khusus database pusat ada di database/migrations/central/
 * dan TIDAK ikut terjalankan di sini (Laravel hanya memindai folder
 * database/migrations/ level atas, tidak masuk ke subfolder).
 */
class MigrateTenants extends Command
{
    protected $signature = 'tenants:migrate
        {--tenant= : Jalankan untuk satu subdomain saja, mis. --tenant=sinyalta}
        {--status : Tampilkan status migration tiap tenant, TANPA menjalankan apa pun}
        {--include-inactive : Ikutkan tenant yang statusnya bukan "active"}';

    protected $description = 'Menjalankan migration ke semua database tenant (subdomain)';

    public function handle(): int
    {
        $query = Tenant::query();

        if ($subdomain = $this->option('tenant')) {
            $query->where('subdomain', $subdomain);
        }

        if (! $this->option('include-inactive')) {
            $query->where('status', 'active');
        }

        $tenants = $query->orderBy('subdomain')->get();

        if ($tenants->isEmpty()) {
            $this->warn('Tidak ada tenant yang cocok. Tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        $isStatusOnly = (bool) $this->option('status');
        // Diambil dari CONFIG, bukan env() — kalau config sedang di-cache
        // (php artisan config:cache), env() di luar file config bernilai
        // null, dan koneksi tidak akan pernah balik ke database utama.
        $databaseUtama = config('database.connections.mysql.database');

        $this->info(($isStatusOnly ? 'Mengecek status' : 'Menjalankan migration')." untuk {$tenants->count()} tenant...");
        $this->newLine();

        $berhasil = 0;
        $gagal = 0;

        try {
            foreach ($tenants as $tenant) {
                $this->line("<fg=cyan>▸ {$tenant->subdomain}</> <fg=gray>({$tenant->database_name})</>");

                try {
                    Config::set('database.connections.mysql.database', $tenant->database_name);
                    DB::purge('mysql');
                    DB::reconnect('mysql');

                    // Pastikan databasenya benar-benar ada & bisa diakses
                    // sebelum menjalankan migration — kalau tidak, error
                    // koneksinya lebih membingungkan daripada perlu.
                    DB::connection('mysql')->getPdo();

                    Artisan::call($isStatusOnly ? 'migrate:status' : 'migrate', array_filter([
                        '--database' => 'mysql',
                        '--force' => $isStatusOnly ? null : true,
                    ]));

                    $output = trim(Artisan::output());

                    $this->line($output !== '' ? $output : '<fg=gray>  (tidak ada perubahan)</>');
                    $berhasil++;
                } catch (\Throwable $e) {
                    // Satu tenant gagal TIDAK BOLEH menghentikan tenant lain.
                    $this->error('  GAGAL: '.$e->getMessage());
                    $gagal++;
                }

                $this->newLine();
            }
        } finally {
            // WAJIB dikembalikan ke database utama, apa pun yang terjadi —
            // supaya proses ini tidak meninggalkan koneksi "nyangkut" ke
            // database tenant terakhir.
            Config::set('database.connections.mysql.database', $databaseUtama);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$berhasil}".($gagal > 0 ? ", GAGAL: {$gagal}" : ''));

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
