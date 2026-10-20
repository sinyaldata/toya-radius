<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\VoucherGeneratorService;
use Illuminate\Console\Command;

class MarkExpiredVouchers extends Command
{
    use RunsPerTenant;

    protected $signature = 'vouchers:mark-expired';

    protected $description = 'Tandai voucher aktif yang sudah melewati masa berlaku (expired_at) sebagai Expired, dan reject di FreeRADIUS. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(VoucherGeneratorService $service): int
    {
        $count = $this->runForEachTenant(fn () => $service->markExpiredVouchers());

        $this->info("{$count} voucher ditandai expired — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
