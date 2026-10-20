@php
    // Ping database DIUKUR SUNGGUHAN (query ringan), bukan angka contoh.
    try {
        $start = microtime(true);
        \Illuminate\Support\Facades\DB::select('select 1');
        $dbMs = round((microtime(true) - $start) * 1000);
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbMs = null;
        $dbOk = false;
    }

    // Versi aplikasi dari config('app.version'); atur lewat .env APP_VERSION=
    $version = config('app.version', '1.0.0');
    $ispName = \App\Models\Setting::get('isp_name', 'ToyaRadius');

    // ---- BARU (permintaan user 2026-09-19): ganti "Sistem Aman /
    // Database / Versi Sistem" jadi "Status Server" (CPU/RAM/Storage)
    // -- SEMUA angka DIUKUR SUNGGUHAN dari server Linux-nya sendiri
    // (baca /proc & disk_*_space() bawaan PHP), bukan angka contoh,
    // sama seperti ping database di atas. Dijalankan tiap footer
    // dirender (tiap buka halaman) -- SENGAJA TIDAK di-cache, soalnya
    // baca file /proc & disk_*_space() itu ringan (beda jauh sama
    // query database berat semacam Grafik Pendapatan yang memang
    // perlu di-cache).

    // CPU: rata-rata beban 1 menit terakhir (sys_getloadavg) dibagi
    // jumlah core, dijadikan persen. Jumlah core dihitung dari
    // /proc/cpuinfo langsung (BUKAN shell_exec('nproc')) -- supaya
    // tidak gagal kalau shell_exec dimatikan admin server demi
    // keamanan (pengaturan umum di banyak hosting).
    $cpuPercent = null;
    try {
        $load = sys_getloadavg();
        $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
        $cpuCores = max(1, substr_count($cpuInfo, 'processor'));
        if ($load !== false) {
            $cpuPercent = min(100, (int) round(($load[0] / $cpuCores) * 100));
        }
    } catch (\Throwable $e) {
        // dibiarkan null -- ditampilkan "-" di bawah kalau gagal baca
    }

    // RAM: dari /proc/meminfo (MemTotal - MemAvailable = terpakai).
    $ramPercent = null;
    $ramUsedGb = null;
    $ramTotalGb = null;
    try {
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mTotal);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $mAvail);
        $totalKb = (int) ($mTotal[1] ?? 0);
        $availKb = (int) ($mAvail[1] ?? 0);
        if ($totalKb > 0) {
            $usedKb = max(0, $totalKb - $availKb);
            $ramPercent = (int) round(($usedKb / $totalKb) * 100);
            $ramUsedGb = round($usedKb / 1024 / 1024, 1);
            $ramTotalGb = round($totalKb / 1024 / 1024, 1);
        }
    } catch (\Throwable $e) {
        // dibiarkan null
    }

    // Storage: disk tempat aplikasi ini sendiri berjalan (base_path()),
    // bukan cuma root "/" -- lebih relevan kalau /var/www ternyata ada
    // di partisi/mount terpisah dari root.
    $diskPercent = null;
    $diskUsedGb = null;
    $diskTotalGb = null;
    try {
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());
        if ($diskTotal) {
            $diskUsed = max(0, $diskTotal - $diskFree);
            $diskPercent = (int) round(($diskUsed / $diskTotal) * 100);
            $diskUsedGb = round($diskUsed / 1024 / 1024 / 1024, 1);
            $diskTotalGb = round($diskTotal / 1024 / 1024 / 1024, 1);
        }
    } catch (\Throwable $e) {
        // dibiarkan null
    }

    // Warna indikator: hijau tenang, oranye mulai penuh, merah kritis.
    // Dipakai sama buat CPU/RAM/Storage, jadi satu fungsi kecil saja.
    $warnaPemakaian = function (?int $persen): string {
        if ($persen === null) {
            return '#a1a1aa';
        }

        return match (true) {
            $persen >= 90 => '#dc2626',
            $persen >= 75 => '#ea580c',
            default => '#16a34a',
        };
    };
@endphp

<div class="tya-footer">
    <span class="tya-footer-item">
        <x-filament::icon icon="lucide-cpu" class="tya-footer-ico" style="color: {{ $warnaPemakaian($cpuPercent) }}" />
        <span class="tya-footer-title">CPU</span>
        <span class="tya-footer-sub">
            @if ($cpuPercent !== null)
                {{ $cpuPercent }}%
            @else
                <span style="color:#dc2626">-</span>
            @endif
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <x-filament::icon icon="lucide-memory-stick" class="tya-footer-ico" style="color: {{ $warnaPemakaian($ramPercent) }}" />
        <span class="tya-footer-title">RAM</span>
        <span class="tya-footer-sub">
            @if ($ramPercent !== null)
                {{ $ramPercent }}% &bull; {{ $ramUsedGb }}/{{ $ramTotalGb }}GB
            @else
                <span style="color:#dc2626">-</span>
            @endif
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <x-filament::icon icon="lucide-hard-drive" class="tya-footer-ico" style="color: {{ $warnaPemakaian($diskPercent) }}" />
        <span class="tya-footer-title">Storage</span>
        <span class="tya-footer-sub">
            @if ($diskPercent !== null)
                {{ $diskPercent }}% &bull; {{ $diskUsedGb }}/{{ $diskTotalGb }}GB
            @else
                <span style="color:#dc2626">-</span>
            @endif
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <span class="tya-footer-title">&copy; {{ now()->year }} {{ $ispName }}</span>
        <span class="tya-footer-sub">All rights reserved</span>
        <x-filament::icon icon="lucide-heart" class="tya-footer-ico tya-footer-ico-heart" />
    </span>
</div>

