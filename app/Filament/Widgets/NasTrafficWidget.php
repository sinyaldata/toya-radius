<?php

namespace App\Filament\Widgets;

use App\Models\Nas;
use App\Services\MikrotikService;
use Filament\Widgets\Widget;

/**
 * Widget "Traffik Jaringan NAS" — menggantikan "Status Server"
 * (permintaan user 2026-09-17). Menampilkan kecepatan Download/Upload
 * LIVE (bukan kumulatif) dari tiap NAS/router yang terhubung ke
 * MikroTik-nya, dibaca lewat MikrotikService::getTrafficSummary().
 *
 * CATATAN PENTING soal performa: tiap baris NAS = satu panggilan API
 * LANGSUNG ke router itu (timeout 2 detik per router, lihat
 * MikrotikConnectionData). Kalau ada router yang mati/tidak terjangkau,
 * baris itu MEMAKAN WAKTU (nunggu sampai timeout) sebelum lanjut ke NAS
 * berikutnya -- sengaja DIBATASI maksimal 6 NAS supaya Dashboard tidak
 * jadi lambat kalau NAS-nya banyak/beberapa sedang offline. Kalau NAS
 * lebih dari 6, tampilkan catatan di bawah daftar (lihat blade view).
 */
class NasTrafficWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected const BATAS_NAS = 6;

    // Berapa titik data terakhir yang disimpan buat grafik realtime
    // sebelum yang paling lama "digeser keluar" -- di update tiap 3
    // detik (lihat $pollingInterval di bawah), jadi 20 titik ~= 1 menit
    // terakhir.
    protected const BATAS_RIWAYAT = 20;

    // Sort 4 = kolom KANAN (Pendapatan 2 | Area/Wilayah 3 | widget ini 4)
    // -- posisi PERSIS sama seperti ServerStatusWidget yang digantikan.
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.nas-traffic';

    /**
     * NAS yang sedang dibuka detailnya (modal "Lihat Detail" --
     * permintaan user: grafik realtime + rincian per-interface). Null
     * = modal tertutup, TIDAK ADA polling yang jalan sama sekali --
     * lihat catatan performa di blade view kenapa ini penting.
     */
    public ?int $trafficDetailNasId = null;

    public ?string $trafficDetailNama = null;

    /** @var array<int, array{waktu: string, rx: int, tx: int}> */
    public array $riwayatTrafik = [];

    /** @var array<int, array{name: string, rx_bps: int, tx_bps: int}> */
    public array $interfaceSekarang = [];

    public ?string $trafficDetailError = null;

    /**
     * Buka modal detail buat satu NAS -- riwayat grafik di-reset total
     * (mulai dari nol lagi), lalu langsung ambil satu sampel pertama
     * supaya modalnya tidak kosong sambil menunggu polling berikutnya.
     */
    public function bukaDetailTrafik(int $nasId): void
    {
        $nas = Nas::with('router')->find($nasId);

        $this->trafficDetailNasId = $nasId;
        $this->trafficDetailNama = $nas ? ($nas->shortname ?: $nas->nasname) : null;
        $this->riwayatTrafik = [];
        $this->interfaceSekarang = [];
        $this->trafficDetailError = null;

        $this->perbaruiDetailTrafik();
    }

    public function tutupDetailTrafik(): void
    {
        $this->trafficDetailNasId = null;
        $this->trafficDetailNama = null;
        $this->riwayatTrafik = [];
        $this->interfaceSekarang = [];
        $this->trafficDetailError = null;
    }

    /**
     * Dipanggil berulang lewat wire:poll SELAMA modal detail terbuka
     * (lihat blade view -- wire:poll-nya cuma ada di dalam blok
     * @if($trafficDetailNasId), jadi otomatis berhenti sendiri begitu
     * modal ditutup, tidak terus jalan di belakang layar).
     */
    public function perbaruiDetailTrafik(): void
    {
        if (! $this->trafficDetailNasId) {
            return;
        }

        $nas = Nas::with('router')->find($this->trafficDetailNasId);

        if (! $nas || ! $nas->router) {
            $this->trafficDetailError = 'NAS ini belum terhubung ke router manapun.';

            return;
        }

        try {
            $perInterface = (new MikrotikService)->getInterfaceTraffics($nas->router);
        } catch (\Throwable $e) {
            $perInterface = null;
        }

        if ($perInterface === null) {
            $this->trafficDetailError = 'Router tidak bisa dihubungi (timeout/offline).';

            return;
        }

        $this->trafficDetailError = null;
        $this->interfaceSekarang = $perInterface;

        $this->riwayatTrafik[] = [
            'waktu' => now()->format('H:i:s'),
            'rx' => (int) array_sum(array_column($perInterface, 'rx_bps')),
            'tx' => (int) array_sum(array_column($perInterface, 'tx_bps')),
        ];

        // Geser keluar titik paling lama kalau sudah melebihi batas --
        // array_values supaya key-nya tetap urut 0,1,2,... (bukan
        // bolong) setelah array_shift.
        if (count($this->riwayatTrafik) > self::BATAS_RIWAYAT) {
            array_shift($this->riwayatTrafik);
        }

        // Kirim data terbaru ke Alpine/Chart.js di sisi browser lewat
        // event -- lebih tahan banting daripada mengandalkan Livewire
        // "menebak" elemen mana yang berubah buat DOM milik Chart.js
        // (canvas tidak dikelola lewat HTML biasa).
        $this->dispatch('tya-nastrf-update', riwayat: $this->riwayatTrafik);
    }

    /**
     * @return array<int, array{nama: string, online: bool, rx_bps: ?int, tx_bps: ?int}>
     */
    public function getTraffics(): array
    {
        $semuaNas = Nas::whereNotNull('router_id')
            ->with('router')
            ->orderBy('id')
            ->get();

        $totalNas = $semuaNas->count();

        return [
            'total' => $totalNas,
            'ditampilkan' => min($totalNas, self::BATAS_NAS),
            'baris' => $semuaNas->take(self::BATAS_NAS)->map(function (Nas $nas) {
                $nama = $nas->shortname ?: $nas->nasname;

                if (! $nas->router) {
                    return ['id' => $nas->id, 'nama' => $nama, 'online' => false, 'rx_bps' => null, 'tx_bps' => null];
                }

                try {
                    // Instance BARU tiap panggilan (bukan app(MikrotikService::class))
                    // -- MikrotikService menyimpan koneksi aktif di
                    // $this->client dan MENGEMBALIKAN yang lama kalau
                    // sudah pernah connect(), jadi kalau dipakai ulang
                    // dari container yang sama, NAS kedua dst bisa salah
                    // kebaca dari koneksi router NAS pertama.
                    $hasil = (new MikrotikService)->getTrafficSummary($nas->router);
                } catch (\Throwable $e) {
                    $hasil = null;
                }

                return [
                    'id' => $nas->id,
                    'nama' => $nama,
                    'online' => $hasil !== null,
                    'rx_bps' => $hasil['rx_bps'] ?? null,
                    'tx_bps' => $hasil['tx_bps'] ?? null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Format bit/detik (satuan asli RouterOS) jadi Kbps/Mbps/Gbps yang
     * mudah dibaca -- satuan "per detik" dipakai (bukan Byte seperti
     * kartu Bandwidth server yang lama) karena ini konteks TRAFIK
     * JARINGAN, dan Mbps adalah satuan yang lazim dipakai ISP
     * (termasuk penamaan paket internet di aplikasi ini sendiri).
     */
    public static function formatBps(?int $bitsPerSecond): string
    {
        if ($bitsPerSecond === null) {
            return '-';
        }

        if ($bitsPerSecond >= 1_000_000_000) {
            return number_format($bitsPerSecond / 1_000_000_000, 2, ',', '.').' Gbps';
        }

        if ($bitsPerSecond >= 1_000_000) {
            return number_format($bitsPerSecond / 1_000_000, 2, ',', '.').' Mbps';
        }

        if ($bitsPerSecond >= 1_000) {
            return number_format($bitsPerSecond / 1_000, 1, ',', '.').' Kbps';
        }

        return $bitsPerSecond.' bps';
    }
}
