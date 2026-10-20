<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

/**
 * Grafik "Pendapatan" -- DIBANGUN ULANG TOTAL 2026-09-18 menggantikan
 * lagi Grafik Pengguna Online di posisi ini (widget itu lemot, harus
 * mengecek tumpang-tindih sesi login satu-satu; grafik pendapatan
 * jauh lebih ringan, "cuma" menjumlahkan transaksi per tanggal/bulan
 * -- lihat DashboardService::revenueChart()).
 *
 * Permintaan user secara spesifik: grafik BATANG (bukan garis/kurva
 * seperti Grafik Online sebelumnya), filter Harian/Bulanan, dan
 * rincian Voucher+Invoice TERPISAH (bukan digabung jadi satu angka
 * "Pendapatan" polos) -- 2 batang berdampingan tiap periode.
 *
 * Menempati posisi & ukuran PERSIS yang sama dengan OnlineChartWidget
 * yang digantikannya: sort=2 (paling kiri di barisnya), columnSpan
 * responsif ('full' di HP, 2 kolom di layar besar, sebaris dengan
 * OutletRevenueWidget/donat "10 Outlet" di sebelahnya).
 */
class SalesChartWidget extends ChartWidget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '300px';

    // View custom (permintaan user: filter "Harian/Bulanan" jadi tombol
    // pil, bukan dropdown select) -- lihat sales-chart.blade.php.
    protected string $view = 'filament.widgets.sales-chart';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    public ?string $filter = 'harian';

    protected ?string $pollingInterval = null;

    public function getHeading(): ?string
    {
        return 'Grafik Pendapatan';
    }

    protected function getFilters(): ?array
    {
        return [
            'harian' => 'Harian',
            'bulanan' => 'Bulanan',
        ];
    }

    protected function getData(): array
    {
        $chart = app(DashboardService::class)->revenueChart($this->filter ?? 'harian');

        return [
            'datasets' => [
                [
                    'label' => 'Voucher',
                    'data' => $chart['voucher'],
                    'backgroundColor' => '#ea580c',
                    'borderRadius' => 4,
                    'maxBarThickness' => 22,
                ],
                [
                    'label' => 'Invoice',
                    'data' => $chart['invoice'],
                    'backgroundColor' => '#fdba74',
                    'borderRadius' => 4,
                    'maxBarThickness' => 22,
                ],
            ],
            'labels' => $chart['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'layout' => [
                'padding' => ['left' => 0, 'right' => 0, 'top' => 0, 'bottom' => 0],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => ['boxWidth' => 10, 'font' => ['size' => 10], 'padding' => 8],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                    'backgroundColor' => '#18181b',
                    'titleColor' => '#fafafa',
                    'bodyColor' => '#e4e4e7',
                    'padding' => 10,
                    'cornerRadius' => 8,
                    'titleFont' => ['size' => 12],
                    'bodyFont' => ['size' => 11],
                    // FIX (user: "sumbu Y gak kelihatan" + "kasih
                    // nilai pas mouse didekatkan") -- SEBELUMNYA di
                    // sini ada 'callbacks' => ['label' => "function(...)
                    // {...}"] -- itu TIDAK BENERAN JADI FUNGSI
                    // SUNGGUHAN. Blade cuma mengubah PHP jadi teks JSON
                    // biasa (lewat @js()), BUKAN kode JavaScript hidup
                    // -- jadi "function(ctx){...}" itu sampai ke
                    // browser sebagai STRING TEKS POLOS, bukan fungsi
                    // yang bisa dipanggil. Chart.js coba MEMANGGIL
                    // teks itu SEOLAH-OLAH fungsi ("string"(...)) dan
                    // ini bikin error JavaScript -- yang di sumbu-Y
                    // errornya sampai bikin SELURUH sumbu itu gagal
                    // digambar (persis yang dilaporkan).
                    //
                    // DIHAPUS TOTAL -- dibiarkan Chart.js pakai
                    // tampilan BAWAAN-nya sendiri buat tooltip (yang
                    // otomatis mengikuti format 'ticks.format' di
                    // sumbu-Y di bawah, jadi tetap tampil "Rp1,5jt"
                    // rapi pas mouse didekatkan ke batangnya, PERSIS
                    // yang diminta, tanpa perlu fungsi custom yang
                    // rawan gagal seperti sebelumnya).
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => false,
                    'grid' => ['display' => false],
                    'ticks' => ['font' => ['size' => 10], 'maxTicksLimit' => 8, 'maxRotation' => 0],
                ],
                'y' => [
                    'stacked' => false,
                    'grid' => ['color' => 'rgba(128,128,128,0.12)'],
                    'ticks' => [
                        'font' => ['size' => 10],
                        // FIX -- diganti dari "callback: function(...)
                        // {...}" (string biasa, TIDAK BENERAN jadi
                        // fungsi -- lihat catatan panjang di 'tooltip'
                        // di atas) ke 'format', yang JAUH LEBIH AMAN:
                        // ini bukan kode JavaScript sama sekali, cuma
                        // OBJEK PENGATURAN BIASA yang Chart.js sendiri
                        // yang membaca & terapkan lewat Intl.NumberFormat
                        // bawaan JavaScript (dukungan RESMI Chart.js,
                        // bukan trik). Hasilnya: "Rp1,5jt" otomatis,
                        // TANPA risiko error sama sekali.
                        'format' => [
                            'style' => 'currency',
                            'currency' => 'IDR',
                            'notation' => 'compact',
                            'compactDisplay' => 'short',
                            'maximumFractionDigits' => 1,
                        ],
                    ],
                    'beginAtZero' => true,
                ],
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false,
            ],
        ];
    }
}
