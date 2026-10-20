<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Radcheck;
use App\Models\Scopes\OutletScope;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PEMERIKSA (hanya membaca, kecuali dipakai --perbaiki): cari pelanggan
 * PPPoE/Hotspot yang statusnya Aktif di aplikasi tapi kehilangan
 * Cleartext-Password di RADIUS -- pelanggan begini TIDAK BISA LOGIN sama
 * sekali (RADIUS tidak punya apa pun untuk dicocokkan), tapi baru
 * ketahuan kalau dia sedang disconnect-reconnect (mati listrik, ganti
 * router, dst.) -- selama sesi lamanya masih berjalan, semuanya
 * terlihat baik-baik saja.
 *
 * DITEMUKAN LEWAT: kasus nyata (2026-09-26) -- satu pelanggan PPPoE
 * kehilangan Cleartext-Password padahal datanya di aplikasi tidak
 * pernah diedit selama 20 hari sebelumnya (sesinya sendiri baru
 * berjalan 3 hari lalu tertutup normal, membuktikan password-nya
 * SEMPAT ada saat sesi itu dimulai). Penyebab pastinya belum
 * dipastikan -- perintah ini untuk memastikan SKALA-nya dulu (cuma
 * satu pelanggan, atau ada yang lain juga).
 *
 * Contoh:
 *   php artisan radius:cek-kredensial-hilang
 *   php artisan radius:cek-kredensial-hilang --perbaiki
 */
class CekKredensialHilang extends Command
{
    use RunsPerTenant;

    protected $signature = 'radius:cek-kredensial-hilang
        {--perbaiki : Tulis ulang RADIUS pelanggan yang kehilangan kredensial (bukan cuma menampilkan)}';

    protected $description = 'Periksa pelanggan PPPoE/Hotspot berstatus Aktif yang kehilangan Cleartext-Password di RADIUS (tidak bisa login sama sekali). Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(\App\Services\CustomerRadiusService $radius): int
    {
        $perbaiki = (bool) $this->option('perbaiki');

        $total = $this->runForEachTenant(function ($tenant) use ($radius, $perbaiki) {
            return $this->periksaTenantIni($tenant?->subdomain ?? 'utama', $radius, $perbaiki);
        });

        $this->newLine();
        $this->info($perbaiki
            ? "{$total} pelanggan diperbaiki -- gabungan semua tenant."
            : "{$total} pelanggan kehilangan kredensial -- gabungan semua tenant. Tambahkan --perbaiki untuk menulis ulang.");

        return self::SUCCESS;
    }

    public function periksaTenantIni(string $label, \App\Services\CustomerRadiusService $radius, bool $perbaiki): int
    {
        $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
            ->with('servicePackage')
            ->whereIn('type', [CustomerType::Pppoe->value, CustomerType::Hotspot->value])
            ->where('status', CustomerStatus::Active->value)
            ->get(['id', 'name', 'username', 'password', 'type', 'service_package_id']);

        if ($pelanggan->isEmpty()) {
            return 0;
        }

        // Satu query whereIn untuk SEMUA pelanggan tenant ini, bukan satu
        // query per pelanggan -- jauh lebih ringan untuk tenant dengan
        // banyak pelanggan.
        $usernameRadius = $pelanggan->mapWithKeys(fn (Customer $c) => [
            RadiusUsername::forRadius($c->username) => $c->id,
        ]);

        $adaPassword = Radcheck::where('attribute', 'Cleartext-Password')
            ->whereIn('username', $usernameRadius->keys())
            ->pluck('username')
            ->all();

        $hilang = $pelanggan->filter(fn (Customer $c) => ! in_array(
            RadiusUsername::forRadius($c->username), $adaPassword, true
        ));

        if ($hilang->isEmpty()) {
            return 0;
        }

        $this->line("<options=bold>[{$label}]</> {$hilang->count()} pelanggan Aktif kehilangan Cleartext-Password:");

        $diperbaiki = 0;

        foreach ($hilang as $c) {
            $this->line("  - {$c->name} ({$c->username}, {$c->type->value})".($c->servicePackage ? '' : ' -- TANPA PAKET, tidak bisa diperbaiki otomatis'));

            if ($perbaiki && $c->servicePackage) {
                $radius->provisionCustomer($c, $c->servicePackage);
                $diperbaiki++;

                // Jejak waktu -- akar penyebab kredensial ini bisa hilang
                // BELUM diketahui pasti (baru 2 kejadian, 2026-09-26), jadi
                // dicatat supaya kalau terulang, waktunya bisa dicocokkan
                // dengan kejadian lain di server (restart, deploy, dst.).
                Log::warning('Kredensial RADIUS hilang, diperbaiki otomatis', [
                    'tenant' => $label,
                    'customer_id' => $c->id,
                    'username' => $c->username,
                    'name' => $c->name,
                ]);
            }
        }

        return $perbaiki ? $diperbaiki : $hilang->count();
    }
}
