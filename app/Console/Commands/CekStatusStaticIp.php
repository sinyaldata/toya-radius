<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\StaticIpOnlineService;
use Illuminate\Console\Command;

class CekStatusStaticIp extends Command
{
    use RunsPerTenant;

    protected $signature = 'static-ip:cek-online';

    protected $description = 'ARP-ping semua pelanggan Static IP dari router masing-masing dan simpan status Online/Offline-nya (dibaca kolom Status di tabel). Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(StaticIpOnlineService $service): int
    {
        $total = $this->runForEachTenant(function ($tenant) use ($service) {
            $r = $service->checkAll();

            $this->line(sprintf(
                '[%s] dicek %d (online %d, offline %d), dilewati %d, router gagal %d',
                $tenant?->subdomain ?? 'utama',
                $r['checked'], $r['online'], $r['offline'], $r['skipped'], $r['routers_failed']
            ));

            return $r['checked'];
        });

        $this->info("{$total} pelanggan Static IP dicek — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
