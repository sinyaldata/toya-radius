<?php
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
?>

<div class="tya-footer">
    <span class="tya-footer-item">
        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-cpu','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($cpuPercent)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-cpu','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($cpuPercent)).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $attributes = $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $component = $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
        <span class="tya-footer-title">CPU</span>
        <span class="tya-footer-sub">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cpuPercent !== null): ?>
                <?php echo e($cpuPercent); ?>%
            <?php else: ?>
                <span style="color:#dc2626">-</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-memory-stick','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($ramPercent)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-memory-stick','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($ramPercent)).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $attributes = $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $component = $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
        <span class="tya-footer-title">RAM</span>
        <span class="tya-footer-sub">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ramPercent !== null): ?>
                <?php echo e($ramPercent); ?>% &bull; <?php echo e($ramUsedGb); ?>/<?php echo e($ramTotalGb); ?>GB
            <?php else: ?>
                <span style="color:#dc2626">-</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-hard-drive','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($diskPercent)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-hard-drive','class' => 'tya-footer-ico','style' => 'color: '.e($warnaPemakaian($diskPercent)).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $attributes = $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $component = $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
        <span class="tya-footer-title">Storage</span>
        <span class="tya-footer-sub">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($diskPercent !== null): ?>
                <?php echo e($diskPercent); ?>% &bull; <?php echo e($diskUsedGb); ?>/<?php echo e($diskTotalGb); ?>GB
            <?php else: ?>
                <span style="color:#dc2626">-</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </span>
    </span>

    <span class="tya-footer-sep"></span>

    <span class="tya-footer-item">
        <span class="tya-footer-title">&copy; <?php echo e(now()->year); ?> <?php echo e($ispName); ?></span>
        <span class="tya-footer-sub">All rights reserved</span>
        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-heart','class' => 'tya-footer-ico tya-footer-ico-heart']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-heart','class' => 'tya-footer-ico tya-footer-ico-heart']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $attributes = $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950)): ?>
<?php $component = $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950; ?>
<?php unset($__componentOriginalbfc641e0710ce04e5fe02876ffc6f950); ?>
<?php endif; ?>
    </span>
</div>

<?php /**PATH /var/www/toya-radius/resources/views/filament/footer.blade.php ENDPATH**/ ?>