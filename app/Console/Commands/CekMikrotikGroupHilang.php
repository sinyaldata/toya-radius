<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Radreply;
use App\Models\Scopes\OutletScope;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * PEMERIKSA (baca saja, kecuali --perbaiki): cari pelanggan PPPoE Aktif
 * yang PAKETNYA memenuhi syarat Mikrotik-Group (Router + IP Pool +
 * profile bukan bawaan) DAN saklar tenant-nya AKTIF, tapi baris
 * Mikrotik-Group di RADIUS-nya HILANG atau SALAH (tidak sama dengan
 * nama profile paketnya) -- pelanggan begini TIDAK DAPAT IP dari
 * MikroTik (jatuh ke default-profile router, biasanya keluar 0.0.0.0).
 *
 * DITEMUKAN LEWAT: kasus nyata "zafran2" (2026-09-26/27) -- paketnya
 * benar, tapi saklar tenant sempat mati, jadi tidak pernah dapat
 * Mikrotik-Group. Setelah saklar dinyalakan ulang (lihat
 * pppoe:aktifkan-mikrotik-group), SEHARUSNYA tidak ada lagi yang
 * kelewat -- tapi pelanggan yang dibuat/pindah paket SETELAH itu, atau
 * lewat jalur lain (import, dsb.), bisa saja tetap kelewat. Perintah
 * ini untuk menemukan kejadian seperti itu SEBELUM pelanggannya sendiri
 * yang melapor.
 *
 * TIDAK MENYENTUH pelanggan yang paketnya memang TIDAK memenuhi syarat
 * (mis. belum ada Router/IP Pool, atau masih pakai profile bawaan) --
 * itu bukan kesalahan, memang sengaja tidak dikirim.
 *
 * Contoh:
 *   php artisan radius:cek-mikrotik-group-hilang
 *   php artisan radius:cek-mikrotik-group-hilang --perbaiki
 */
class CekMikrotikGroupHilang extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:cek-mikrotik-group-hilang
        {--perbaiki : Tulis ulang Mikrotik-Group pelanggan yang hilang/salah (bukan cuma menampilkan)}';

    protected $description = 'Periksa pelanggan PPPoE Aktif yang paketnya berhak Mikrotik-Group tapi RADIUS-nya hilang/salah (tidak dapat IP). Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(CustomerRadiusService $radius): int
    {
        $perbaiki = (bool) $this->option('perbaiki');

        $total = $this->runForEachTenant(function ($tenant) use ($radius, $perbaiki) {
            return $this->periksaTenantIni($tenant?->subdomain ?? 'utama', $radius, $perbaiki);
        });

        $this->newLine();
        $this->info($perbaiki
            ? "{$total} pelanggan diperbaiki -- gabungan semua tenant."
            : "{$total} pelanggan bermasalah -- gabungan semua tenant. Tambahkan --perbaiki untuk menulis ulang.");

        return self::SUCCESS;
    }

    public function periksaTenantIni(string $label, CustomerRadiusService $radius, bool $perbaiki): int
    {
        if (! CustomerRadiusService::mikrotikGroupAktif()) {
            return 0;
        }

        $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
            ->with('servicePackage')
            ->where('type', CustomerType::Pppoe->value)
            ->where('status', CustomerStatus::Active->value)
            ->whereNotNull('service_package_id')
            ->get(['id', 'name', 'username', 'password', 'type', 'service_package_id']);

        $pelanggan = $pelanggan->filter(fn (Customer $c) => $c->servicePackage
            && CustomerRadiusService::paketMengirimMikrotikGroup($c->servicePackage));

        if ($pelanggan->isEmpty()) {
            return 0;
        }

        $usernameRadius = $pelanggan->mapWithKeys(fn (Customer $c) => [
            RadiusUsername::forRadius($c->username) => $c->id,
        ]);

        $nilaiSekarang = Radreply::where('attribute', 'Mikrotik-Group')
            ->whereIn('username', $usernameRadius->keys())
            ->pluck('value', 'username');

        $bermasalah = $pelanggan->filter(function (Customer $c) use ($nilaiSekarang) {
            $username = RadiusUsername::forRadius($c->username);
            $seharusnya = $c->servicePackage->mikrotik_profile;

            return ($nilaiSekarang[$username] ?? null) !== $seharusnya;
        });

        if ($bermasalah->isEmpty()) {
            return 0;
        }

        $this->line("<options=bold>[{$label}]</> {$bermasalah->count()} pelanggan PPPoE Aktif Mikrotik-Group hilang/salah:");

        $diperbaiki = 0;

        foreach ($bermasalah as $c) {
            $username = RadiusUsername::forRadius($c->username);
            $sekarang = $nilaiSekarang[$username] ?? '(kosong)';
            $seharusnya = $c->servicePackage->mikrotik_profile;

            $this->line("  - {$c->name} ({$c->username}) -- sekarang: {$sekarang}, seharusnya: {$seharusnya}");

            if ($perbaiki) {
                $radius->provisionCustomer($c, $c->servicePackage);
                $diperbaiki++;
            }
        }

        return $perbaiki ? $diperbaiki : $bermasalah->count();
    }
}
