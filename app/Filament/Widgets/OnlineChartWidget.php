<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

/**
 * Grafik "Pengguna Online" — jumlah yang terhubung per jam selama
 * rentang waktu terakhir. AKTIF LAGI 2026-09-17 (sempat dicabut,
 * digantikan Status Server lalu Traffik Jaringan NAS -- keduanya
 * sekarang dihapus dari Dashboard atas permintaan user) -- gaya tampil
 * DIDESAIN ULANG total mengikuti gambar referensi user: satu garis
 * oranye (jumlah TOTAL, bukan dipecah Hotspot/PPPoE lagi), kartu tema
 * gelap, tanpa kotak ringkas di bawah grafik.
 *
 * Menempati posisi PALING KIRI pada baris dua kolom di Dashboard:
 * Pengguna Online | 10 Outlet (donat, di kanan). Urutan itu ditentukan
 * nilai $sort tiap widget, bukan urutan ->widgets().
 *
 * Perhitungan datanya ada di DashboardService::onlineChart() — di sana
 * dijelaskan kenapa "online per jam" harus dihitung dari tumpang-tindih
 * rentang sesi, bukan sekadar menghitung baris radacct.
 */
class OnlineChartWidget extends ChartWidget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    // Sort 2 = mengambil alih posisi lama "Pendapatan" (SalesChartWidget,
    // sekarang dicabut dari Dashboard) -- paling kiri di baris ini.
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '300px';

    // 2 dari 3 kolom (permintaan user: "besarin aja grafiknya") di
    // layar besar -- TAPI permintaan user selanjutnya: PENUH SATU
    // KARTU (bukan setengah/kepotong) khusus di mode HP, jadi dibuat
    // responsif: 'full' di breakpoint kecil (default/HP), baru jadi 2
    // kolom begitu masuk layar besar (lg ke atas).
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    // View sendiri: salinan chart-widget bawaan, restyle tema gelap
    // mengikuti gambar referensi user (tanpa kotak ringkas lagi di
    // bawah grafik -- versi sebelumnya punya itu, sekarang dilepas
    // supaya persis seperti contoh: kartu gelap bersih isinya cuma
    // grafik).
    protected string $view = 'filament.widgets.online-chart';

    public ?string $filter = '24';

    // Tidak menyegarkan sendiri — biar tidak berkedip. Data terbaru
    // muncul saat halaman dimuat ulang.
    //
    // CATATAN: di ChartWidget properti ini NON-STATIC (beda dengan
    // kelas Widget biasa yang static). Kalau ditulis "static" di sini,
    // PHP langsung fatal error saat file dimuat:
    // "Cannot redeclare non static ...::$pollingInterval as static".
    // Jadi JANGAN tambahkan kata "static" pada baris di bawah ini.
    protected ?string $pollingInterval = null;

    public function getHeading(): ?string
    {
        return 'Grafik Pengguna Online';
    }

    protected function getFilters(): ?array
    {
        // 3 opsi sesuai permintaan user -- "1 Bulan" pakai kode "720"
        // (30 hari x 24 jam), ditangani otomatis di
        // DashboardService::onlineChart() dengan pengelompokan per HARI
        // (bukan per jam) supaya tetap ringan & mudah dibaca untuk
        // rentang sepanjang itu.
        return [
            '24' => 'Hari Ini',
            '12' => '12 Jam Terakhir',
            '720' => '1 Bulan',
        ];
    }

    protected function getData(): array
    {
        $jam = (int) ($this->filter ?? 24);
        $data = app(DashboardService::class)->onlineChart($jam);

        // Digabung jadi SATU garis total (permintaan user, ikut gambar
        // referensi -- sebelumnya dipecah 2 garis Hotspot/PPPoE
        // terpisah). Kalau nanti butuh rincian per-jenis lagi, data
        // mentahnya ($data['hotspot'] / $data['pppoe']) masih sama
        // persis seperti sebelumnya di DashboardService, tinggal
        // dipecah lagi di sini.
        $total = array_map(
            fn ($h, $p) => (int) $h + (int) $p,
            $data['hotspot'],
            $data['pppoe']
        );

        return [
            'datasets' => [
                [
                    'label' => 'Pengguna Online',
                    'data' => $total,
                    'borderColor' => '#f97316',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.35)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2.5,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 5,
                    'pointHoverBackgroundColor' => '#f97316',
                    'pointHoverBorderColor' => '#fff',
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            // Tinggi grafik mengikuti wadahnya (maxHeight di atas),
            // bukan dihitung dari rasio lebar.
            'maintainAspectRatio' => false,
            // FIX (user: "kiri kanan masih banyak kosong") -- Chart.js
            // bawaannya kasih sedikit jarak kosong di sekeliling area
            // plot (bukan cuma dari label sumbu-Y, tapi memang ada
            // "bantalan" tambahan bawaan). Dinolkan semua di sini --
            // yang tersisa cuma jarak buat label sumbu (angka & jam),
            // itu memang perlu ruang supaya kebaca, tidak dihilangkan.
            'layout' => [
                'padding' => [
                    'left' => 0,
                    'right' => 0,
                    'top' => 0,
                    'bottom' => 0,
                ],
            ],
            'plugins' => [
                // Legend dimatikan (permintaan user, ikut gambar
                // referensi) -- cuma satu garis sekarang, tidak perlu
                // keterangan warna lagi.
                'legend' => ['display' => false],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                    'backgroundColor' => '#27272a',
                    'titleColor' => '#fafafa',
                    'bodyColor' => '#d4d4d8',
                    'padding' => 10,
                    'cornerRadius' => 8,
                    'titleFont' => ['size' => 12, 'weight' => 'bold'],
                    'bodyFont' => ['size' => 11],
                    'callbacks' => [
                        // "386 pengguna" (bukan cuma angka mentah) --
                        // persis format di gambar referensi.
                        'label' => "function(ctx) { return ctx.parsed.y + ' pengguna'; }",
                    ],
                ],
            ],
            // Warna grid/teks sumbu dipakai ABU-ABU NETRAL (permintaan
            // user: kartu ini sekarang ikut terang/gelap tema aplikasi,
            // bukan SELALU gelap lagi) -- putih transparan cuma kebaca
            // di background gelap, di mode terang jadi nggak kelihatan
            // sama sekali. Abu-abu ini dipilih supaya cukup kebaca di
            // KEDUA mode tanpa perlu logic JS terpisah buat gonta-ganti
            // warnanya.
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => [
                        'font' => ['size' => 10],
                        'color' => 'rgba(128,128,128,0.65)',
                        'maxTicksLimit' => 6,
                        'maxRotation' => 0,
                    ],
                ],
                'y' => [
                    'grid' => ['color' => 'rgba(128,128,128,0.15)'],
                    'ticks' => [
                        'font' => ['size' => 10],
                        'color' => 'rgba(128,128,128,0.65)',
                        'precision' => 0,
                    ],
                    // FIX (permintaan user: "garis grafik kelihatan
                    // mepet, kurang penuh") -- beginAtZero:true maksa
                    // sumbu-Y selalu mulai dari 0, walau data aslinya
                    // ada di angka ratusan/ribuan tinggi terus. Itu
                    // bikin garisnya numpuk mepet ke atas kartu, sisa
                    // ruang di bawahnya kosong percuma. Dilepas supaya
                    // Chart.js otomatis menyesuaikan rentang sumbu-Y ke
                    // sekitar data yang sebenarnya -- garis jadi mengisi
                    // tinggi kartu lebih penuh & proporsional.
                    'beginAtZero' => false,
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
