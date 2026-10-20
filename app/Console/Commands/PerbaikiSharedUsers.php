<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Radcheck;
use App\Models\Scopes\OutletScope;
use App\Services\CustomerRadiusService;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * Perbaikan SEKALI JALAN: samakan batas login sekaligus (Simultaneous-Use)
 * semua Hotspot Member dengan "Shared Users" di Profile Hotspot paketnya.
 *
 * Kenapa perlu: sebelumnya SEMUA member diberi Simultaneous-Use := 1 apa pun
 * paketnya. Kode yang baru (CustomerRadiusService::batasLoginMember) hanya
 * berlaku untuk member yang di-provisioning SETELAH terpasang; yang sudah ada
 * tetap membawa angka 1 sampai ditulis ulang.
 *
 * Idempoten: nilainya dihitung ulang dari paket saat ini (bukan "ditambah"
 * dari nilai lama). Hanya MENGUBAH baris yang sudah ada dan hanya kalau
 * nilainya berbeda; tidak menyentuh kredensial, rate-limit, atau status
 * isolir. Yang sedang online tidak terputus.
 */
class PerbaikiSharedUsers extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:perbaiki-shared-users {--dry-run : Cuma tampilkan berapa yang akan diubah, tidak menulis apa pun}';

    protected $description = 'Perbaikan SEKALI JALAN: samakan Simultaneous-Use Hotspot Member dengan Shared Users paketnya. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(): int
    {
        $simulasi = (bool) $this->option('dry-run');

        $total = $this->runForEachTenant(function ($tenant) use ($simulasi) {
            $diubah = $this->perbaikiTenantIni($simulasi);

            $this->line(sprintf('[%s] member diubah %d', $tenant?->subdomain ?? 'utama', $diubah));

            return $diubah;
        });

        $this->info($simulasi
            ? "{$total} member AKAN diubah (dry-run, belum ada yang ditulis) -- gabungan semua tenant."
            : "{$total} member diperbaiki -- gabungan semua tenant.");

        return self::SUCCESS;
    }

    public function perbaikiTenantIni(bool $simulasi): int
    {
        $diubah = 0;

        Customer::withoutGlobalScope(OutletScope::class)
            ->with('servicePackage')
            ->where('type', CustomerType::Hotspot->value)
            ->whereNotNull('service_package_id')
            ->chunkById(500, function ($kelompok) use (&$diubah, $simulasi) {
                foreach ($kelompok as $c) {
                    if (! $c->servicePackage) {
                        continue;
                    }

                    $benar = (string) CustomerRadiusService::batasLoginMember($c->servicePackage);

                    $baris = Radcheck::where('username', RadiusUsername::forRadius($c->username))
                        ->where('attribute', 'Simultaneous-Use')
                        ->first();

                    if (! $baris || (string) $baris->value === $benar) {
                        continue;
                    }

                    if (! $simulasi) {
                        $baris->update(['value' => $benar]);
                    }

                    $diubah++;
                }
            });

        return $diubah;
    }
}
