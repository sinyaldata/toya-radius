<?php

namespace App\Filament\Resources\PppoeUserResource\Widgets;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\PppoeOnlineResource;
use App\Filament\Resources\PppoeUserResource;
use App\Models\Customer;
use App\Models\Radacct;
use App\Models\Scopes\OutletScope;
use App\Support\RadiusUsername;
use Filament\Widgets\Widget;

/**
 * Dua kartu di atas tabel User PPPoE: SESI ONLINE dan SUSPENDED.
 *
 * PENTING — KENAPA KELAS INI ADA DI SINI, BUKAN DI app/Filament/Widgets:
 * Panel ini SENGAJA tidak memakai ->discoverWidgets() (lihat catatannya
 * di AdminPanelProvider), jadi widget yang ditaruh di folder
 * app/Filament/Widgets HANYA dikenali kalau didaftarkan manual di
 * ->widgets([...]) — dan itu akan ikut memunculkannya di Dashboard.
 * Widget yang dipasang lewat getHeaderWidgets() sebuah resource HARUS
 * ikut pola yang sudah terbukti jalan di aplikasi ini, yaitu ditaruh di
 * folder Widgets milik resource-nya sendiri (contoh yang sudah lama
 * jalan: HotspotUserResource\Widgets\HotspotUserOverview).
 *
 * pollingInterval = null: mematikan penyegaran otomatis. Tanpa ini
 * kartu bisa terlihat berkedip seperti terus di-refresh — masalah yang
 * sama pernah terjadi pada kartu di halaman Voucher.
 */
class PppoeUserOverview extends Widget
{
    // RINGKASAN-LANGSUNG: dimuat bersama halaman (tanpa kotak loading berkedip)
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.tya-stat-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    // RINGKASAN-CEPAT: angka kartu disimpan 2 menit per tenant & per user, supaya
    // halaman tidak menghitung ulang ke database setiap kali dibuka.
    public function getCards(): array
    {
        $kunci = 'tya-ringkasan:'.static::class.':'
            .\Illuminate\Support\Facades\DB::connection()->getDatabaseName().':'
            .(auth()->id() ?? 0);

        return \Illuminate\Support\Facades\Cache::remember($kunci, 120, fn () => $this->hitungKartu());
    }

    protected function hitungKartu(): array
    {
        // Kalau ada satu query yang bermasalah, JANGAN sampai seluruh
        // kartu ikut hilang/gagal render — angkanya cukup jadi 0.
        try {
            // withoutGlobalScope(OutletScope): kartu ini menghitung
            // SELURUH pelanggan PPPoE, tidak ikut tersaring scope outlet.
            $pppoe = fn () => Customer::query()
                ->withoutGlobalScope(OutletScope::class)
                ->where('type', CustomerType::Pppoe);

            // SESI ONLINE dari radacct (sesi tanpa acctstoptime), disaring
            // ke username pelanggan PPPoE saja — caranya SAMA PERSIS
            // dengan menu "PPPoE > Online", termasuk pemetaan
            // RadiusUsername::forRadius(), karena username di radacct
            // memakai awalan tenant sehingga tidak sama persis dengan
            // yang tersimpan di tabel customers.
            $usernameRadius = $pppoe()->pluck('username')
                ->map(fn ($u) => RadiusUsername::forRadius($u))
                ->all();

            $online = empty($usernameRadius) ? 0 : Radacct::query()
                ->whereNull('acctstoptime')
                ->whereIn('username', $usernameRadius)
                ->distinct()
                ->count('username');

            // Offline = total pelanggan PPPoE dikurangi yang sedang
            // online -- angkanya dari total & online yang SUDAH dihitung
            // di atas, jadi tidak perlu query tambahan.
            $totalPppoe = $pppoe()->count();
            $offline = max(0, $totalPppoe - $online);

            $suspended = $pppoe()->where('status', CustomerStatus::Suspended)->count();
        } catch (\Throwable $e) {
            $online = 0;
            $offline = 0;
            $suspended = 0;
        }

        return [
            [
                'label' => 'Sesi Online',
                'value' => number_format($online),
                'icon' => 'lucide-wifi',
                'color' => '#f97316',
                'note' => 'Sedang terhubung sekarang',
                // Ke halaman "PPPoE > Online" (sesi radacct aktif).
                'url' => PppoeOnlineResource::getUrl('index'),
            ],
            [
                'label' => 'Offline',
                'value' => number_format($offline),
                'icon' => 'lucide-wifi-off',
                'color' => '#78716c',
                'note' => 'Tidak sedang terhubung',
                // Ke halaman User PPPoE, tersaring lewat filter Sesi=Offline.
                'url' => PppoeUserResource::getUrl('index').'?tableFilters[sesi_online][value]=offline',
            ],
            [
                'label' => 'Suspended',
                'value' => number_format($suspended),
                'icon' => 'lucide-ban',
                'color' => '#9a3412',
                'note' => 'Akses ditutup sementara',
            ],
        ];
    }
}
