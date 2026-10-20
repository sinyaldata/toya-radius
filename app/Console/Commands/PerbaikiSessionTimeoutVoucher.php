<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Radcheck;
use App\Models\Radreply;
use Illuminate\Console\Command;

/**
 * Perintah SEKALI JALAN (bukan terjadwal) -- pindahkan baris "Session-Timeout"
 * voucher yang MASIH nyangkut di radcheck (peninggalan bug lama, lihat
 * FreeRadiusService::provisionVoucher()) ke radreply, tempat yang benar.
 *
 * Kenapa perlu perintah terpisah: perbaikan di FreeRadiusService hanya
 * berlaku untuk voucher yang di-provisioning ULANG setelah kode baru
 * terpasang (dibuat baru, atau diaktifkan lagi). Voucher yang SUDAH
 * terlanjur aktif sebelum perbaikan ini tidak otomatis ter-provisioning
 * ulang -- baris radcheck-nya akan tetap salah tempat sampai dipindahkan
 * manual lewat perintah ini.
 *
 * Aman dijalankan berkali-kali (idempotent): kalau tidak ada lagi baris
 * radcheck Session-Timeout yang tersisa, perintah ini tidak melakukan
 * apa-apa.
 */
class PerbaikiSessionTimeoutVoucher extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:perbaiki-session-timeout {--dry-run : Cuma tampilkan berapa yang akan dipindah, tidak mengubah apa pun}';

    protected $description = 'Perbaikan SEKALI JALAN: pindahkan Session-Timeout voucher yang salah tempat di radcheck ke radreply (radcheck tidak pernah dikirim ke NAS, jadi Mikrotik tidak pernah menerima batas waktunya).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $total = $this->runForEachTenant(function () use ($dryRun) {
            $barisLama = Radcheck::where('attribute', 'Session-Timeout')->get();

            foreach ($barisLama as $baris) {
                if (! $dryRun) {
                    Radreply::updateOrCreate(
                        ['username' => $baris->username, 'attribute' => 'Session-Timeout'],
                        ['op' => '=', 'value' => $baris->value, 'voucher_id' => $baris->voucher_id]
                    );

                    $baris->delete();
                }
            }

            return $barisLama->count();
        });

        if ($dryRun) {
            $this->info("{$total} baris Session-Timeout di radcheck AKAN dipindah ke radreply (dry-run, belum ada yang diubah) -- gabungan semua tenant.");
        } else {
            $this->info("{$total} baris Session-Timeout dipindah dari radcheck ke radreply -- gabungan semua tenant.");
        }

        return self::SUCCESS;
    }
}
