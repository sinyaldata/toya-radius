
<?php
    // Lapis pengaman kedua. Penjagaan UTAMA ada di AdminPanelProvider
    // (render hook-nya tidak memanggil view ini kalau belum login), tapi
    // kalau suatu saat view ini dipakai dari tempat lain, jangan sampai
    // bottom bar nongol lagi di halaman login -- semua tautannya menuju
    // halaman yang dijaga auth.
    //
    // "return" di blok PHP ini memang menghentikan render view-nya:
    // Blade mengompilasi view menjadi fungsi, jadi ini sah dan bukan
    // akal-akalan.
    if (! \Filament\Facades\Filament::auth()->check()) {
        return;
    }

    $dashboardUrl = \App\Filament\Pages\Dashboard::getUrl();
    $pppoeUrl = \App\Filament\Resources\PppoeUserResource::getUrl();
    $hotspotMemberUrl = \App\Filament\Resources\HotspotMemberResource::getUrl();
    $voucherUrl = \App\Filament\Resources\HotspotUserResource::getUrl();
    $laporanUrl = \App\Filament\Pages\SalesReportPage::getUrl();
    $settingUrl = \App\Filament\Pages\SettingsPage::getUrl();

    // Deteksi tab AKTIF dari path URL saat ini -- Dashboard dicek PERSIS
    // (exact match, bukan prefix), soalnya path-nya "/admin" adalah AWALAN
    // dari semua halaman admin lain juga (kalau prefix, Dashboard akan
    // selalu kebaca "aktif" di halaman manapun).
    $pathOf = fn (string $url) => ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    $isExact = fn (string $url) => request()->is($pathOf($url));
    $isPrefix = fn (string $url) => request()->is($pathOf($url).'*');

    $dashboardActive = $isExact($dashboardUrl);
    $pppoeActive = $isPrefix($pppoeUrl);
    $hotspotMemberActive = $isPrefix($hotspotMemberUrl);
    $pelangganActive = $pppoeActive || $hotspotMemberActive;
    $voucherActive = $isPrefix($voucherUrl);
    $laporanActive = $isPrefix($laporanUrl);
    $settingActive = $isPrefix($settingUrl);
?>

<style>
    /* ============================================================
     * BOTTOM NAVIGATION BAR -- mobile/tablet kecil saja (<=1023px,
     * persis titik sidebar berubah jadi drawer, lihat theme.css).
     * ============================================================ */
    .tya-bottom-nav {
        display: none;
    }

    @media (max-width: 1023px) {
        .tya-bottom-nav {
            display: flex;
            position: fixed;
            inset-inline: 0;
            bottom: 0;
            z-index: 30;
            align-items: flex-end;
            justify-content: space-around;
            padding: 0.4rem 0.35rem calc(0.4rem + env(safe-area-inset-bottom, 0px));
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(16px) saturate(160%);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            border-top: 1px solid rgba(24, 24, 27, 0.08);
            box-shadow: 0 -6px 20px -8px rgba(24, 24, 27, 0.12);
        }

        .dark .tya-bottom-nav {
            background: rgba(24, 24, 27, 0.88);
            border-top-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 -6px 20px -8px rgba(0, 0, 0, 0.45);
        }

        /* Ruang bawah supaya konten/footer tidak ketutup bar ini. */
        .fi-main {
            padding-bottom: 4.75rem !important;
        }

        /* Halaman login/auth (layout "simple") TIDAK pakai bottom nav --
         * belum ada sesi, tujuan-tujuannya juga butuh login dulu. */
        .fi-simple-page .tya-bottom-nav {
            display: none !important;
        }
    }

    .tya-bn-item {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        gap: 0.2rem;
        flex: 1 1 0;
        padding: 0.3rem 0.15rem;
        border-radius: 0.75rem;
        color: #a1a1aa;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
        cursor: pointer;
        transition: color 0.15s ease;
    }

    .tya-bn-item:active {
        background: rgba(249, 115, 22, 0.08);
    }

    .tya-bn-icon {
        width: 1.35rem;
        height: 1.35rem;
        flex-shrink: 0;
    }

    .tya-bn-label {
        font-size: 0.625rem;
        font-weight: 600;
        line-height: 1;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .tya-bn-item.is-active {
        color: #f97316;
    }

    .dark .tya-bn-item {
        color: #71717a;
    }

    .dark .tya-bn-item.is-active {
        color: #fb923c;
    }

    /* Tombol tengah (Voucher) diangkat jadi lingkaran menonjol -- pola
     * "FAB tengah" yang lumrah di app mobile, sekaligus menegaskan ini
     * aksi PALING SERING dipakai (generate voucher). */
    .tya-bn-item-cta {
        color: #52525b;
    }

    .dark .tya-bn-item-cta {
        color: #d4d4d8;
    }

    .tya-bn-cta-circle {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.9rem;
        height: 2.9rem;
        border-radius: 9999px;
        margin-top: -1.35rem;
        background: linear-gradient(155deg, #fb923c, #ea580c);
        box-shadow: 0 8px 16px -4px rgba(234, 88, 12, 0.55);
        border: 3px solid rgba(255, 255, 255, 0.9);
    }

    .dark .tya-bn-cta-circle {
        border-color: rgba(24, 24, 27, 0.9);
    }

    .tya-bn-cta-circle .tya-bn-icon-cta {
        width: 1.4rem;
        height: 1.4rem;
        color: #ffffff;
    }

    .tya-bn-item-cta .tya-bn-label {
        margin-top: 0.05rem;
        color: #ea580c;
    }

    .dark .tya-bn-item-cta .tya-bn-label {
        color: #fb923c;
    }

    /* ===== Submenu "Pelanggan" (popover kecil di atas tombolnya) ===== */
    .tya-bn-popover {
        position: absolute;
        bottom: calc(100% + 0.6rem);
        left: 50%;
        transform: translateX(-50%);
        min-width: 10.5rem;
        background: #ffffff;
        border: 1px solid rgba(24, 24, 27, 0.08);
        border-radius: 0.9rem;
        box-shadow: 0 12px 28px -8px rgba(24, 24, 27, 0.28);
        padding: 0.35rem;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .dark .tya-bn-popover {
        background: #27272a;
        border-color: rgba(255, 255, 255, 0.08);
    }

    .tya-bn-popover-item {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.5rem 0.6rem;
        border-radius: 0.6rem;
        color: #3f3f46;
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }

    .tya-bn-popover-item:active {
        background: rgba(249, 115, 22, 0.1);
    }

    .dark .tya-bn-popover-item {
        color: #e4e4e7;
    }

    .tya-bn-popover-icon {
        width: 1.05rem;
        height: 1.05rem;
        color: #f97316;
        flex-shrink: 0;
    }

    .tya-bn-popover-arrow {
        position: absolute;
        bottom: -0.32rem;
        left: 50%;
        transform: translateX(-50%) rotate(45deg);
        width: 0.65rem;
        height: 0.65rem;
        background: #ffffff;
        border-right: 1px solid rgba(24, 24, 27, 0.08);
        border-bottom: 1px solid rgba(24, 24, 27, 0.08);
    }

    .dark .tya-bn-popover-arrow {
        background: #27272a;
        border-color: rgba(255, 255, 255, 0.08);
    }

    .tya-bn-overlay {
        position: fixed;
        inset: 0;
        z-index: 29;
        background: transparent;
    }
</style>

<div x-data="{ pelangganOpen: false }">
    
    <div class="tya-bn-overlay" x-show="pelangganOpen" x-cloak @click="pelangganOpen = false"></div>

    <nav class="tya-bottom-nav">
        <a href="<?php echo e($dashboardUrl); ?>" class="tya-bn-item <?php if($dashboardActive): ?> is-active <?php endif; ?>">
            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-layout-dashboard','class' => 'tya-bn-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-layout-dashboard','class' => 'tya-bn-icon']); ?>
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
            <span class="tya-bn-label">Dashboard</span>
        </a>

        <div class="tya-bn-item <?php if($pelangganActive): ?> is-active <?php endif; ?>" @click="pelangganOpen = !pelangganOpen">
            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-users-round','class' => 'tya-bn-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-users-round','class' => 'tya-bn-icon']); ?>
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
            <span class="tya-bn-label">Pelanggan</span>

            <div class="tya-bn-popover" x-show="pelangganOpen" x-transition x-cloak @click.stop>
                <a href="<?php echo e($pppoeUrl); ?>" class="tya-bn-popover-item">
                    <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-radio','class' => 'tya-bn-popover-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-radio','class' => 'tya-bn-popover-icon']); ?>
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
                    <span>PPPoE</span>
                </a>
                <a href="<?php echo e($hotspotMemberUrl); ?>" class="tya-bn-popover-item">
                    <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-wifi','class' => 'tya-bn-popover-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-wifi','class' => 'tya-bn-popover-icon']); ?>
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
                    <span>Member</span>
                </a>
                <div class="tya-bn-popover-arrow"></div>
            </div>
        </div>

        <a href="<?php echo e($voucherUrl); ?>" class="tya-bn-item tya-bn-item-cta <?php if($voucherActive): ?> is-active <?php endif; ?>">
            <span class="tya-bn-cta-circle">
                <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-ticket','class' => 'tya-bn-icon tya-bn-icon-cta']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-ticket','class' => 'tya-bn-icon tya-bn-icon-cta']); ?>
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
            <span class="tya-bn-label">Voucher</span>
        </a>

        <a href="<?php echo e($laporanUrl); ?>" class="tya-bn-item <?php if($laporanActive): ?> is-active <?php endif; ?>">
            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-bar-chart-3','class' => 'tya-bn-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-bar-chart-3','class' => 'tya-bn-icon']); ?>
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
            <span class="tya-bn-label">Laporan</span>
        </a>

        <a href="<?php echo e($settingUrl); ?>" class="tya-bn-item <?php if($settingActive): ?> is-active <?php endif; ?>">
            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-settings','class' => 'tya-bn-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-settings','class' => 'tya-bn-icon']); ?>
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
            <span class="tya-bn-label">Setting</span>
        </a>
    </nav>
</div>
<?php /**PATH /var/www/toya-radius/resources/views/filament/mobile-bottom-nav.blade.php ENDPATH**/ ?>