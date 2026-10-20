<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\VoucherGeneratorService;
use Illuminate\Console\Command;

class SyncVoucherActivations extends Command
{
    use RunsPerTenant;

    protected $signature = 'vouchers:sync-activation';

    protected $description = 'Deteksi voucher Terjual yang sudah login pertama kali (dari radacct) dan ubah statusnya jadi Aktif + hitung expired_at. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(VoucherGeneratorService $service): int
    {
        $count = $this->runForEachTenant(fn () => $service->syncActivations());
        $this->info("{$count} voucher terdeteksi baru aktif (dipakai) — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
