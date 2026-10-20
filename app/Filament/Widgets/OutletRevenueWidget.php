<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use Filament\Widgets\ChartWidget;

class OutletRevenueWidget extends ChartWidget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    // Sort 3 = kolom TENGAH (Pendapatan 2 | Donat 3 | Online 4).
    protected static ?int $sort = 3;

    // Donat dibuat lebih besar mengikuti contoh desain yang dikirim
    // user (sebelumnya 170px -- terlalu kecil dibanding kartunya).
    // Dikecilkan lagi (200px -> 130px): kartu ini yang PALING TINGGI di
    // barisnya (donat + 10 baris legenda), dan dalam satu baris grid
    // semua kartu ikut setinggi yang tertinggi -- jadi mengecilkan yang
    // ini memendekkan seluruh barisnya sekaligus.
    protected ?string $maxHeight = '160px';

    // Responsif (permintaan user: Grafik Pengguna Online full width di
    // HP) -- biar sebarisan/konsisten, ini juga full lebar di HP,
    // baru jadi 1 kolom begitu masuk layar besar (lg ke atas).
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    // View sendiri: donat + ringkasan per outlet di bawahnya.
    // (Teks total di tengah lingkaran SUDAH DIHAPUS -- permintaan user.)
    protected string $view = 'filament.widgets.outlet-revenue';

    /**
     * Warna berurutan untuk daftar Area/Wilayah. 10 warna, cukup untuk
     * 10 baris teratas (limit query di getAreaRows()).
     */
    protected const COLORS = [
        // HANYA gradasi ORANYE VIVID (permintaan user, tidak boleh ada
        // warna lain DAN tidak boleh kecoklatan) -- diurutkan dari
        // GELAP ke TERANG sesuai urutan peringkat (Area #1/terbesar =
        // paling gelap, makin ke bawah makin terang). Titik paling
        // gelapnya sengaja TIDAK turun sampai coklat tua (rumus lama
        // #7c2d12 ke bawah kelihatan coklat) -- interpolasi dijaga di
        // antara oranye-700 (#c2410c) sampai oranye-300 (#fdba74),
        // rentang yang tetap terbaca "oranye" murni di semua tingkatnya.
        '#c2410c',
        '#c94e18',
        '#cf5c23',
        '#d6692f',
        '#dc773a',
        '#e38446',
        '#e99251',
        '#f09f5d',
        '#f6ad68',
        '#fdba74', // oranye paling terang (peringkat #10)
    ];

    public function getHeading(): ?string
    {
        return 'Area/Wilayah Penjualan Terbanyak';
    }

    /**
     * 10 Area/Wilayah dengan pendapatan tertinggi bulan ini, lengkap
     * dengan persentase kontribusinya -- diganti dari per-Outlet ke
     * per-Area (permintaan user). Area didapat lewat outlet pemilik tiap
     * transaksi (outlets.area_id) -- transaksi dari outlet yang belum
     * diset area-nya otomatis tidak ikut dihitung di sini (konsisten
     * dengan versi per-outlet sebelumnya, yang juga melewati transaksi
     * tanpa outlet_id). Dihitung sekali lalu di-cache per request supaya
     * query tidak jalan berulang (dipakai chart, total, dan legenda).
     */
    public function getAreaRows(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->join('outlets', 'outlets.id', '=', 'transactions.outlet_id')
            ->selectRaw('outlets.area_id as area_id, SUM(transactions.total_amount) as total')
            ->where('transactions.payment_status', PaymentStatus::Lunas)
            ->whereMonth('transactions.transaction_date', now()->month)
            ->whereYear('transactions.transaction_date', now()->year)
            ->whereNotNull('outlets.area_id')
            ->groupBy('outlets.area_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $namaArea = \App\Models\Area::whereIn('id', $rows->pluck('area_id'))->pluck('name', 'id');

        $grandTotal = (float) $rows->sum('total');

        $cache = $rows->values()->map(function ($r, $i) use ($grandTotal, $namaArea) {
            $color = self::COLORS[$i % count(self::COLORS)];

            return [
                'name' => $namaArea[$r->area_id] ?? '-',
                'total' => (float) $r->total,
                // Kalau total 0, jangan bagi nol.
                'pct' => $grandTotal > 0 ? ((float) $r->total / $grandTotal) * 100 : 0.0,
                'color' => $color,
                // Warna label persen mengikuti warna potongannya (lihat
                // badgeStyle()) supaya legenda & donat terbaca sebagai
                // satu kesatuan, persis contoh desain yang dikirim user.
                'badge' => self::badgeStyle($color),
            ];
        })->toArray();

        return $cache;
    }

    /**
     * Menentukan warna latar & teks label persen dari warna potongan
     * donat.
     *
     * Caranya menghitung TERANG-GELAPnya warna (luminance). Warna
     * gelap (hitam/abu tua) dipakai apa adanya sebagai latar dengan
     * teks putih; warna terang (oranye/abu muda) dipakai versi
     * transparannya sebagai latar dengan teks warna aslinya yang
     * digelapkan — kalau dipukul rata, teks putih di atas oranye muda
     * jadi tidak terbaca.
     */
    protected static function badgeStyle(string $hex): array
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        // Rumus luminance standar (mata manusia paling peka ke hijau).
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        if ($luminance < 0.5) {
            return ['bg' => $hex, 'text' => '#ffffff'];
        }

        return [
            'bg' => sprintf('rgba(%d, %d, %d, 0.20)', $r, $g, $b),
            'text' => sprintf('#%02x%02x%02x', (int) ($r * 0.62), (int) ($g * 0.62), (int) ($b * 0.62)),
        ];
    }

    public function getAreaTotal(): float
    {
        return collect($this->getAreaRows())->sum('total');
    }

    protected function getData(): array
    {
        $rows = collect($this->getAreaRows());

        return [
            'datasets' => [
                [
                    'data' => $rows->pluck('total')->toArray(),
                    'backgroundColor' => $rows->pluck('color')->toArray(),
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $rows->pluck('name')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                // Legenda bawaan dimatikan — diganti daftar ringkasan
                // (nama + persentase + nominal) di bawah donat.
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#18181b',
                    'padding' => 10,
                    'cornerRadius' => 8,
                ],
            ],
            'cutout' => '68%',
        ];
    }
}
