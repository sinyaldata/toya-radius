<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

class CheckOverdueInvoices extends Command
{
    use RunsPerTenant;

    protected $signature = 'invoices:cek-overdue';

    protected $description = 'Tandai invoice yang lewat jatuh tempo sebagai overdue, lalu isolir otomatis pelanggan terkait di FreeRADIUS KECUALI kebijakan "Isolir Otomatis" dimatikan per tenant (Pengaturan > Tagihan Otomatis). Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(InvoiceService $service): int
    {
        $count = $this->runForEachTenant(fn () => $service->markOverdueAndIsolir());
        $this->info("{$count} invoice ditandai overdue & pelanggan terkait diisolir — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
