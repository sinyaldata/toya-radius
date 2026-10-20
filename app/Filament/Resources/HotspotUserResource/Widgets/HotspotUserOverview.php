<?php

namespace App\Filament\Resources\HotspotUserResource\Widgets;

use App\Enums\VoucherSource;
use App\Enums\VoucherStatus;
use App\Models\Voucher;
use Filament\Widgets\Widget;

/**
 * Kartu statistik KHUSUS halaman User Hotspot — desain custom (bukan
 * StatsOverviewWidget bawaan Filament) supaya bisa dikasih aksen oranye
 * sesuai identitas visual aplikasi ini, dan tidak ikut polling 5 detik
 * bawaan Filament yang sempat bikin kartu kelihatan berkedip.
 *
 * Kartu "Voucher Aktif", "Voucher Online" & "Voucher Offline" DIHAPUS
 * dari sini (permintaan user) -- sekalian query online/offline-nya
 * (baca sesi radacct + daftar username pelanggan) ikut dibuang karena
 * sudah tidak dipakai kartu mana pun lagi.
 */
class HotspotUserOverview extends Widget
{
    // RINGKASAN-LANGSUNG: dimuat bersama halaman (tanpa kotak loading berkedip)
    protected static bool $isLazy = false;

    protected string $view = 'filament.resources.hotspot-user-resource.widgets.hotspot-user-overview';

    protected int|string|array $columnSpan = 'full';

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
        $total = Voucher::count();
        $expired = Voucher::where('status', VoucherStatus::Expired)->count();

        // --- Aktivitas HARI INI ---
        // "Laku" dihitung dari tanggal TERJUAL (sold_at), bukan dari
        // pemakaian — supaya angkanya mencerminkan penjualan.
        $lakuHariIni = Voucher::whereBetween('sold_at', [today(), today()->endOfDay()])->count();

        // "Digenerate hari ini" dari created_at, lalu dipecah per asal
        // pembuatan. Voucher lama memang tidak punya catatan asal
        // (kolomnya NULL), tapi tidak berpengaruh di sini karena kartu
        // ini hanya menghitung yang dibuat HARI INI.
        $generateHariIni = Voucher::whereBetween('created_at', [today(), today()->endOfDay()])->count();
        $generateAplikasi = Voucher::whereBetween('created_at', [today(), today()->endOfDay()])
            ->where('generated_via', VoucherSource::Aplikasi->value)
            ->count();
        $generateTelegram = Voucher::whereBetween('created_at', [today(), today()->endOfDay()])
            ->where('generated_via', VoucherSource::Telegram->value)
            ->count();

        return [
            ['label' => 'Total Voucher', 'value' => $total, 'icon' => 'lucide-ticket'],
            ['label' => 'Voucher Expired', 'value' => $expired, 'icon' => 'lucide-clock'],
            [
                'label' => 'Laku Hari Ini',
                'value' => $lakuHariIni,
                'icon' => 'lucide-shopping-cart',
            ],
            [
                'label' => 'Digenerate Hari Ini',
                'value' => $generateHariIni,
                'icon' => 'lucide-sparkles',
                // Keterangan kecil di bawah angkanya — rincian per asal.
                'note' => 'Aplikasi '.number_format($generateAplikasi).' • Telegram '.number_format($generateTelegram),
            ],
        ];
    }
}
