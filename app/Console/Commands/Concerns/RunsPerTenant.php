<?php

namespace App\Console\Commands\Concerns;

use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Dipakai command TERJADWAL (cron) yang perlu jalan buat SETIAP tenant,
 * bukan cuma instalasi utama. Tanpa ini, command jalan hanya sekali
 * (memakai koneksi default dari .env — instalasi utama saja), tenant
 * lain TIDAK PERNAH ikut ter-proses (voucher tidak pernah dideteksi
 * aktif, invoice tidak pernah digenerate, dst).
 */
trait RunsPerTenant
{
    /**
     * Jalankan $callback SEKALI untuk instalasi utama, LALU sekali lagi
     * untuk setiap tenant aktif — mengalihkan koneksi 'mysql' ke
     * database yang benar sebelum tiap panggilan, dan MENGEMBALIKAN ke
     * database utama lagi di akhir (baik berhasil maupun gagal),
     * supaya tidak "nyangkut" ke request/command lain setelahnya.
     *
     * @param  callable(?Tenant $tenant): int  $callback  menerima Tenant aktif (null = instalasi utama), kembalikan jumlah baris diproses
     * @return int total gabungan dari semua tenant
     */
    protected function runForEachTenant(callable $callback): int
    {
        $total = 0;

        // Diambil dari CONFIG, bukan env(). Kalau config sedang di-cache
        // (php artisan config:cache), env() di luar file config bernilai
        // null — akibatnya koneksi TIDAK pernah balik ke database utama
        // setelah looping tenant selesai.
        $databaseUtama = config('database.connections.mysql.database');

        try {
            // Instalasi utama dulu (tanpa alih koneksi — pakai .env apa adanya).
            // PENTING: lepas dulu binding 'currentTenant' kalau ada sisa dari
            // iterasi tenant sebelumnya (jaga-jaga command ini dipanggil lagi
            // dalam proses PHP yang sama) — instalasi utama TIDAK PUNYA tenant.
            if (app()->bound('currentTenant')) {
                app()->forgetInstance('currentTenant');
            }

            $total += (int) $callback(null);

            foreach (Tenant::where('status', 'active')->get() as $tenant) {
                Config::set('database.connections.mysql.database', $tenant->database_name);
                DB::purge('mysql');
                DB::reconnect('mysql');

                // WAJIB diisi di sini juga (bukan cuma waktu request web biasa)
                // — App\Support\RadiusUsername::forRadius() (dipakai buat
                // urusan RADIUS/radacct) baca binding ini buat tau awalan
                // tenant mana yang harus dipakai. Tanpa ini, command
                // terjadwal yang urusan radacct/radcheck SELALU salah
                // (mengira lagi di instalasi utama, walau lagi proses tenant).
                app()->instance('currentTenant', $tenant);

                try {
                    $total += (int) $callback($tenant);
                } catch (\Throwable $e) {
                    // Satu tenant gagal TIDAK BOLEH menghentikan tenant lain —
                    // catat & lanjut, jangan biarkan seluruh command mati.
                    if (method_exists($this, 'error')) {
                        $this->error("Tenant {$tenant->subdomain}: {$e->getMessage()}");
                    }
                }
            }
        } finally {
            if (app()->bound('currentTenant')) {
                app()->forgetInstance('currentTenant');
            }

            Config::set('database.connections.mysql.database', $databaseUtama);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        return $total;
    }
}
