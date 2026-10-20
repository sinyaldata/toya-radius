<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\RadiusSessionLogService;
use Illuminate\Console\Command;

class SyncRadiusSessionLogs extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:sync-session-logs {--minutes=180 : Rentang waktu ke belakang yang dipindai}';

    protected $description = 'Salin peristiwa login/logout dari radacct ke tabel log permanen radius_session_logs. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(RadiusSessionLogService $service): int
    {
        $minutes = (int) $this->option('minutes');

        $total = $this->runForEachTenant(function () use ($service, $minutes) {
            $result = $service->sync($minutes);

            return $result['login'] + $result['logout'];
        });

        $this->info("{$total} log login/logout baru dicatat — gabungan semua tenant.");

        return self::SUCCESS;
    }
}
