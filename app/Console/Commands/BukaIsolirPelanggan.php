<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CariPelangganLintasTenant;
use App\Enums\CustomerStatus;
use App\Services\CustomerRadiusService;
use Illuminate\Console\Command;

/**
 * Buka isolir/suspend satu pelanggan lewat terminal -- setara tombol
 * "Buka Blokir" di halaman Isolir (App\Filament\Pages\IsolirPage), bekerja
 * untuk KEDUA sebab (Isolir tunggakan maupun Suspend manual): dari sisi
 * pelanggan keduanya sama saja -- internetnya mati -- jadi cara membukanya
 * juga sama.
 */
class BukaIsolirPelanggan extends Command
{
    use CariPelangganLintasTenant;

    protected $signature = 'pelanggan:buka-isolir
                            {username : Username pelanggan (bukan nama)}
                            {--tenant= : Subdomain tenant, kalau tahu persis -- kalau tidak, otomatis dicari ke semua tenant}';

    protected $description = 'Buka isolir/suspend satu pelanggan (aktifkan lagi akses RADIUS-nya) tanpa harus lewat aplikasi web.';

    public function handle(CustomerRadiusService $radius): int
    {
        $username = (string) $this->argument('username');
        $pelanggan = $this->cariPelanggan($username);

        if (! $pelanggan) {
            $this->error("Pelanggan dengan username '{$username}' tidak ditemukan (sudah dicari di instalasi utama + semua tenant aktif).");

            return self::FAILURE;
        }

        $this->line("Ditemukan: {$pelanggan->name} ({$pelanggan->username}) -- {$this->labelTenantAktif()}.");

        if ($pelanggan->status === CustomerStatus::Active) {
            $this->warn('Pelanggan ini sudah Aktif -- tidak ada perubahan.');

            return self::SUCCESS;
        }

        $statusSebelumnya = $pelanggan->status->label();

        $pelanggan->update(['status' => CustomerStatus::Active]);
        $radius->reactivate($pelanggan);

        $this->info("Selesai. Status diubah dari '{$statusSebelumnya}' menjadi 'Aktif', akses RADIUS dibuka lagi.");

        // invoices:cek-overdue hanya menyisir invoice yang MASIH berstatus
        // Unpaid saat lewat jatuh tempo -- begitu sebuah invoice sudah
        // berubah jadi Overdue (seperti yang terjadi tepat sebelum
        // pelanggan ini diisolir), invoice yang SAMA tidak akan pernah
        // membuatnya diisolir ulang secara otomatis oleh command itu.
        // Kalau alasan bukanya adalah tunggakan dan tetap belum dibayar,
        // isolir berikutnya untuk pelanggan ini harus manual juga.
        $adaTunggakan = $pelanggan->invoices()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->exists();

        if ($adaTunggakan) {
            $this->warn("Catatan: pelanggan ini masih punya tagihan yang belum lunas. Sistem TIDAK akan mengisolir ulang tagihan yang sama secara otomatis -- kalau tetap tidak dibayar, isolir berikutnya juga harus manual (pakai 'php artisan pelanggan:isolir {$pelanggan->username}').");
        }

        return self::SUCCESS;
    }
}
