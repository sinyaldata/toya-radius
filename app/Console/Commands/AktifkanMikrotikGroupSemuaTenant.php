<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerType;
use App\Models\ServicePackage;
use App\Models\Setting;
use App\Services\CustomerRadiusService;
use Illuminate\Console\Command;

/**
 * SEKALI JALAN: nyalakan saklar "PPPoE ikut Profile & IP Pool paketnya"
 * (pppoe_mikrotik_group) untuk SEMUA tenant (dan instalasi utama)
 * sekaligus, lalu langsung terapkan ke pelanggan yang sudah ada -- tanpa
 * perlu membuka halaman Pengaturan satu per satu (halaman itu belum
 * terpasang di server ini per 2026-09-26).
 *
 * KENAPA DIBUATKAN (ditemukan 2026-09-26): pelanggan "zafran2" di
 * instalasi utama dapat IP 0.0.0.0 terus-menerus karena saklar ini
 * ternyata MATI, walau paketnya sendiri sudah benar (Router + IP Pool +
 * nama profile bukan bawaan) -- 16 pelanggan lain di paket yang sama
 * sudah dapat Mikrotik-Group SEBELUM saklar itu dimatikan, jadi
 * pelanggan LAMA tetap baik-baik saja sementara yang BARU/disinkron
 * ulang gagal diam-diam. User minta diberlakukan ke SEMUA tenant
 * sekaligus (bukan cuma instalasi utama), untuk jaga-jaga tenant lain
 * juga kena kondisi yang sama tanpa disadari.
 *
 * AMAN DIJALANKAN: tenant yang tidak punya paket PPPoE sama sekali
 * dilewati (tidak menyentuh apa pun). Tenant yang paketnya memang tidak
 * memenuhi syarat (belum ada Router/IP Pool, atau nama masih bawaan)
 * TETAP TIDAK dikirimi Mikrotik-Group -- saklar ini cuma mengizinkan,
 * bukan memaksa paket yang belum siap.
 *
 * Contoh:  php artisan pppoe:aktifkan-mikrotik-group
 */
class AktifkanMikrotikGroupSemuaTenant extends Command
{
    use RunsPerTenant;

    protected $signature = 'pppoe:aktifkan-mikrotik-group';

    protected $description = 'SEKALI JALAN: nyalakan saklar Mikrotik-Group PPPoE untuk instalasi utama DAN semua tenant, lalu terapkan ke pelanggan yang sudah ada.';

    public function handle(CustomerRadiusService $radius): int
    {
        $totalDiperbarui = $this->runForEachTenant(function ($tenant) use ($radius) {
            $label = $tenant?->subdomain ?? 'utama';

            $adaPaket = ServicePackage::whereIn('type', ['pppoe', 'both'])->exists();

            if (! $adaPaket) {
                return 0;
            }

            $sebelum = CustomerRadiusService::mikrotikGroupAktif();
            Setting::set('pppoe_mikrotik_group', '1', 'pppoe');

            $jumlah = $radius->terapkanMikrotikGroupTenantIni(true);

            $this->line(sprintf(
                '[%s] saklar %s -> AKTIF, %d baris RADIUS diperbarui',
                $label,
                $sebelum ? 'sudah aktif' : 'MATI',
                $jumlah
            ));

            return $jumlah;
        });

        $this->newLine();
        $this->info("Selesai. Total {$totalDiperbarui} baris RADIUS diperbarui/ditulis di seluruh tenant.");

        return self::SUCCESS;
    }
}
