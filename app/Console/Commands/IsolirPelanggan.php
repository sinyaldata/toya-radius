<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CariPelangganLintasTenant;
use App\Enums\CustomerStatus;
use App\Services\CustomerRadiusService;
use Illuminate\Console\Command;

/**
 * Isolir manual satu pelanggan lewat terminal -- dipakai kalau kebijakan
 * "isolir otomatis" dimatikan (lihat Pengaturan > Tagihan Otomatis) dan
 * admin memutuskan sendiri kapan pelanggan yang menunggak benar-benar
 * diputus, atau untuk kasus mendadak yang tidak mau menunggu jadwal
 * `invoices:cek-overdue`.
 */
class IsolirPelanggan extends Command
{
    use CariPelangganLintasTenant;

    protected $signature = 'pelanggan:isolir
                            {username : Username pelanggan (bukan nama)}
                            {--tenant= : Subdomain tenant, kalau tahu persis -- kalau tidak, otomatis dicari ke semua tenant}';

    protected $description = 'Isolir manual satu pelanggan (tutup akses RADIUS-nya) tanpa harus lewat aplikasi web.';

    public function handle(CustomerRadiusService $radius): int
    {
        $username = (string) $this->argument('username');
        $pelanggan = $this->cariPelanggan($username);

        if (! $pelanggan) {
            $this->error("Pelanggan dengan username '{$username}' tidak ditemukan (sudah dicari di instalasi utama + semua tenant aktif).");

            return self::FAILURE;
        }

        $this->line("Ditemukan: {$pelanggan->name} ({$pelanggan->username}) -- {$this->labelTenantAktif()}.");

        if ($pelanggan->status === CustomerStatus::Isolir) {
            $this->warn('Pelanggan ini sudah berstatus Isolir -- tidak ada perubahan.');

            return self::SUCCESS;
        }

        $statusSebelumnya = $pelanggan->status->label();

        $pelanggan->update(['status' => CustomerStatus::Isolir]);
        $radius->suspend($pelanggan);

        $this->info("Selesai. Status diubah dari '{$statusSebelumnya}' menjadi 'Isolir (Tunggakan)', akses RADIUS ditutup.");

        return self::SUCCESS;
    }
}
