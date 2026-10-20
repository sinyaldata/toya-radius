<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CariPelangganLintasTenant;
use App\Enums\CustomerStatus;
use App\Services\CustomerRadiusService;
use Illuminate\Console\Command;

/**
 * Nonaktifkan (suspend manual) satu pelanggan lewat terminal -- BEDA
 * dengan isolir: dipakai untuk alasan DI LUAR tunggakan (mis. pelanggan
 * minta berhenti sementara/permanen), bukan karena telat bayar. Beda
 * praktisnya: InvoiceService::generateMonthlyInvoices() melewati
 * pelanggan berstatus Suspend, jadi selama suspend dia TIDAK ditagih
 * invoice bulanan otomatis -- kalau memakai pelanggan:isolir, tetap
 * ditagih seperti biasa.
 */
class NonaktifkanPelanggan extends Command
{
    use CariPelangganLintasTenant;

    protected $signature = 'pelanggan:nonaktifkan
                            {username : Username pelanggan (bukan nama)}
                            {--tenant= : Subdomain tenant, kalau tahu persis -- kalau tidak, otomatis dicari ke semua tenant}';

    protected $description = 'Nonaktifkan (suspend manual, BUKAN karena tunggakan) satu pelanggan -- akses RADIUS ditutup dan tagihan bulanan otomatis berhenti selama berstatus ini.';

    public function handle(CustomerRadiusService $radius): int
    {
        $username = (string) $this->argument('username');
        $pelanggan = $this->cariPelanggan($username);

        if (! $pelanggan) {
            $this->error("Pelanggan dengan username '{$username}' tidak ditemukan (sudah dicari di instalasi utama + semua tenant aktif).");

            return self::FAILURE;
        }

        $this->line("Ditemukan: {$pelanggan->name} ({$pelanggan->username}) -- {$this->labelTenantAktif()}.");

        if ($pelanggan->status === CustomerStatus::Suspended) {
            $this->warn('Pelanggan ini sudah berstatus Suspend (Manual) -- tidak ada perubahan.');

            return self::SUCCESS;
        }

        $statusSebelumnya = $pelanggan->status->label();

        $pelanggan->update(['status' => CustomerStatus::Suspended]);
        $radius->suspend($pelanggan);

        $this->info("Selesai. Status diubah dari '{$statusSebelumnya}' menjadi 'Suspend (Manual)', akses RADIUS ditutup. Pelanggan ini TIDAK akan ditagih invoice bulanan otomatis selama berstatus ini.");

        return self::SUCCESS;
    }
}
