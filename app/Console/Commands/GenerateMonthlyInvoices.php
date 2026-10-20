<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    use RunsPerTenant;

    protected $signature = 'invoices:generate-bulanan {--force : Abaikan pengaturan on/off dan tanggal, langsung generate}';

    protected $description = 'Generate tagihan bulan berjalan untuk semua pelanggan aktif (Hotspot tetap & PPPoE) yang belum punya invoice periode ini. Jalan untuk instalasi utama DAN setiap tenant aktif — pengaturan on/off & tanggal generate dicek PER TENANT (Setting tersimpan di database masing-masing).';

    public function handle(InvoiceService $service): int
    {
        $force = $this->option('force');

        $count = $this->runForEachTenant(function () use ($service, $force) {
            if (! $force) {
                $enabled = Setting::get('billing_auto_generate', '1') === '1';

                if (! $enabled) {
                    return 0;
                }

                $targetDay = (int) Setting::get('billing_generate_day', 1);

                if ((int) now()->format('j') !== $targetDay) {
                    return 0;
                }
            }

            return $service->generateMonthlyInvoices();
        });

        $this->info("{$count} invoice baru berhasil dibuat — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
