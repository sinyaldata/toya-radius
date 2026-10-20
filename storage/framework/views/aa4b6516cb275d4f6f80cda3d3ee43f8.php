
<?php
    $kontak = \App\Models\Setting::get('isp_contact');
    $waNumber = $kontak ? preg_replace('/[^0-9]/', '', $kontak) : null;

    // 08xxx -> 628xxx supaya link wa.me valid
    if ($waNumber && str_starts_with($waNumber, '0')) {
        $waNumber = '62'.substr($waNumber, 1);
    }

    $url = $waNumber
        ? 'https://wa.me/'.$waNumber
        : \App\Filament\Pages\SettingsPage::getUrl();

    // Nama perusahaan dipecah di SPASI TERAKHIR: bagian depan gelap,
    // kata terakhir oranye — meniru gaya "ToyaRadius" pada rancangan.
    // Dibuat dari nama yang tersimpan, bukan teks tetap, supaya tenant
    // lain ikut memakai namanya sendiri. Nama satu kata tampil polos
    // (tidak ada yang bisa dipecah), dan itu memang disengaja.
    $namaIsp = trim((string) \App\Models\Setting::get('isp_name', 'Toya Radius')) ?: 'Toya Radius';
    $posisiSpasi = mb_strrpos($namaIsp, ' ');
    $merekDepan = $posisiSpasi !== false ? mb_substr($namaIsp, 0, $posisiSpasi) : $namaIsp;
    $merekBelakang = $posisiSpasi !== false ? mb_substr($namaIsp, $posisiSpasi + 1) : '';
?>

<div class="tya-help-dock" x-show="$store.sidebar.isOpen" x-cloak>
    <div class="tya-help-card">
        
        <span class="tya-help-blob" aria-hidden="true"></span>

        <div class="tya-help-head">
            <span class="tya-help-badge">
                <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-life-buoy','class' => 'tya-help-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-life-buoy','class' => 'tya-help-icon']); ?>
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
            <div class="tya-help-teks">
                <div class="tya-help-title">Butuh Bantuan?</div>
                <div class="tya-help-sub">Hubungi tim support kami, siap membantu Anda.</div>
            </div>
        </div>

        <a href="<?php echo e($url); ?>" target="_blank" rel="noopener" class="tya-help-btn">
            <span>Buka Bantuan</span>
            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-arrow-right','class' => 'tya-help-btn-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-arrow-right','class' => 'tya-help-btn-icon']); ?>
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
        </a>
    </div>

    <div class="tya-help-brand">
        <div class="tya-help-brand-name">
            <?php echo e($merekDepan); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($merekBelakang): ?><span><?php echo e($merekBelakang); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="tya-help-brand-tag">Stabil &bull; Aman &bull; Bersama</div>
    </div>
</div>
<?php /**PATH /var/www/toya-radius/resources/views/filament/sidebar-help.blade.php ENDPATH**/ ?>