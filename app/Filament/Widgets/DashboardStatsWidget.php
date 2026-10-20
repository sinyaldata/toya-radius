<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\HotspotOnlineResource;
use App\Filament\Resources\InvoiceUnpaidResource;
use App\Filament\Resources\PppoeOnlineResource;
use App\Services\DashboardService;
use Filament\Widgets\Widget;

/**
 * 4 kartu statistik Dashboard -- didesain ULANG TOTAL jadi widget
 * custom (permintaan user 2026-09-17, mengikuti gambar referensi
 * baru: kartu putih, kotak ikon berwarna, badge tren naik/turun di
 * pojok kanan atas, mini grafik batang dekoratif di kanan).
 *
 * SEBELUMNYA pakai StatsOverviewWidget bawaan Filament (->icon()
 * ->color() dst) -- diganti total karena tata letak baru ini (badge
 * tren + mini-chart di posisi tertentu) tidak bisa dicapai lewat API
 * Stat bawaan, perlu kontrol penuh atas markup-nya.
 *
 * Data tren (persen naik/turun) dihitung di
 * DashboardService::summary() -- lihat catatan di sana soal kenapa
 * kartu Invoice pakai perbandingan berbeda dari 2 kartu Pendapatan.
 */
class DashboardStatsWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-stats';

    public function getKartu(): array
    {
        $outletId = auth()->user()?->isOutlet() ? auth()->user()->outlet_id : null;

        $s = app(DashboardService::class)->summary($outletId);

        return [
            [
                'label' => 'Pendapatan Hari Ini',
                'value' => 'Rp'.number_format($s['pendapatan_hari_ini'], 0, ',', '.'),
                'desc' => 'Voucher Rp'.number_format($s['pendapatan_hari_ini_voucher'], 0, ',', '.').' • Invoice Rp'.number_format($s['pendapatan_hari_ini_invoice'], 0, ',', '.'),
                'icon' => 'lucide-wallet',
                'tema' => 'oranye',
                'tren' => $s['pendapatan_hari_ini_tren'],
                'url' => null,
            ],
            [
                'label' => 'Pendapatan Bulan Ini',
                'value' => 'Rp'.number_format($s['pendapatan_bulanan'], 0, ',', '.'),
                'desc' => 'Voucher Rp'.number_format($s['pendapatan_bulanan_voucher'], 0, ',', '.').' • Invoice Rp'.number_format($s['pendapatan_bulanan_invoice'], 0, ',', '.'),
                'icon' => 'lucide-trending-up',
                'tema' => 'oranye',
                'tren' => $s['pendapatan_bulanan_tren'],
                'url' => null,
            ],
            [
                'label' => 'Invoice Belum Dibayar',
                'value' => 'Rp'.number_format($s['invoice_belum_dibayar'], 0, ',', '.'),
                'desc' => $s['invoice_belum_dibayar_jumlah'].' tagihan',
                'icon' => 'lucide-file-text',
                'tema' => 'oranye',
                'tren' => $s['invoice_belum_dibayar_tren'],
                'url' => InvoiceUnpaidResource::getUrl('index'),
            ],
            [
                'label' => 'Online Sekarang',
                'value' => number_format($s['online_total']),
                'desc_html' => '<a href="'.e(HotspotOnlineResource::getUrl('index')).'" style="text-decoration:underline; text-decoration-style:dotted; color:inherit;">'
                        .$s['online_hotspot'].' Hotspot</a>'
                    .' • '
                    .'<a href="'.e(PppoeOnlineResource::getUrl('index')).'" style="text-decoration:underline; text-decoration-style:dotted; color:inherit;">'
                        .$s['online_pppoe'].' PPPoE</a>',
                'icon' => 'lucide-users',
                'tema' => 'oranye',
                'tren' => null,
                'online_dot' => true,
                'url' => null,
            ],
        ];
    }
}
