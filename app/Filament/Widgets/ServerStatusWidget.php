<?php

namespace App\Filament\Widgets;

use App\Services\ServerStatusService;
use Filament\Widgets\Widget;

/**
 * Widget "Status Server" — CPU, RAM, Penyimpanan, dan Bandwidth.
 * Menggantikan grafik Pelanggan Online di kolom kanan Dashboard.
 *
 * Semua angkanya dibaca ServerStatusService (tanpa shell_exec, lihat
 * catatan panjang di sana — termasuk kenapa CPU & bandwidth tampil 0
 * pada pemuatan halaman yang pertama).
 *
 * pollingInterval TIDAK di-set static: di kelas Widget biasa properti
 * ini memang static, TAPI supaya konsisten dan aman kalau suatu saat
 * kelas induknya diganti ke ChartWidget, di sini sengaja tidak
 * dideklarasikan sama sekali.
 */
class ServerStatusWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    // Sort 4 = kolom KANAN (Pendapatan 2 | 10 Outlet 3 | Status Server 4).
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.server-status';

    public function getStatus(): array
    {
        $svc = app(ServerStatusService::class);

        try {
            $data = $svc->all();
        } catch (\Throwable $e) {
            // Jangan sampai satu pembacaan gagal bikin seluruh Dashboard
            // ikut gagal render.
            $data = ['cpu' => null, 'ram' => null, 'disk' => null, 'net' => null, 'uptime' => null];
        }

        return [
            'cpu' => $data['cpu'],
            'ram' => $data['ram'],
            'disk' => $data['disk'],
            'net' => $data['net'],
            'uptime' => $data['uptime'],
        ];
    }

    /**
     * Warna bar mengikuti tingkat pemakaian. Keadaan normal memakai
     * ORANYE (permintaan user — sebelumnya hijau), lalu naik ke oranye
     * tua saat mulai padat, dan merah saat kritis.
     *
     * Eskalasinya sengaja dijaga tetap MENGGELAP (oranye -> oranye tua
     * -> merah). Kalau keadaan "mulai padat" diberi warna yang justru
     * lebih terang dari keadaan normal, matanya salah membaca: yang
     * lebih buruk malah terlihat lebih ringan.
     */
    public static function warnaBar(?float $persen): string
    {
        if ($persen === null) {
            return '#d4d4d8';
        }

        if ($persen >= 90) {
            return '#dc2626';
        }

        if ($persen >= 70) {
            return '#c2410c';
        }

        return '#f97316';
    }

    public static function formatBytes(?int $bytes): string
    {
        return ServerStatusService::formatBytes($bytes);
    }
}
