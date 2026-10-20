<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Voucher;
use App\Models\Scopes\OutletScope;
use App\Services\FreeRadiusService;
use Illuminate\Console\Command;

/**
 * Menyusulkan atribut "Expiration" ke FreeRADIUS untuk voucher LAMA.
 *
 * Voucher yang dibuat/diimpor SETELAH fitur ini ada otomatis dapat
 * atributnya. Tapi voucher yang sudah lama ada di database (punya
 * tanggal kadaluarsa, tapi didaftarkan ke RADIUS sebelum fitur ini)
 * belum punya — jadi FreeRADIUS belum tahu tanggal kadaluarsanya.
 *
 * Cukup dijalankan SEKALI setelah update ini dipasang:
 *
 *     php artisan vouchers:sync-expiration
 *
 * Aman diulang berkali-kali (idempoten) — voucher yang sudah benar
 * tinggal ditimpa nilai yang sama.
 */
class SyncVoucherExpiration extends Command
{
    use RunsPerTenant;

    protected $signature = 'vouchers:sync-expiration';

    protected $description = 'Kirim tanggal kadaluarsa voucher lama ke FreeRADIUS (atribut Expiration). Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(FreeRadiusService $freeRadiusService): int
    {
        $total = $this->runForEachTenant(function () use ($freeRadiusService) {
            $jumlah = 0;

            Voucher::withoutGlobalScope(OutletScope::class)
                ->whereNotNull('expired_at')
                ->chunkById(200, function ($vouchers) use ($freeRadiusService, &$jumlah) {
                    foreach ($vouchers as $voucher) {
                        try {
                            $freeRadiusService->syncExpiration($voucher);
                            $jumlah++;
                        } catch (\Throwable $e) {
                            // Satu voucher gagal tidak boleh menghentikan sisanya.
                            $this->warn("  Lewati {$voucher->username}: {$e->getMessage()}");
                        }
                    }
                });

            return $jumlah;
        });

        $this->info("{$total} voucher disinkronkan tanggal kadaluarsanya ke FreeRADIUS — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
