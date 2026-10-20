<?php if (isset($component)) { $__componentOriginalb525200bfa976483b4eaa0b7685c6e24 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb525200bfa976483b4eaa0b7685c6e24 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament-widgets::components.widget','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament-widgets::widget'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    
    <div class="tya-banner" style="position:relative; display:flex; align-items:center; flex-wrap:wrap; gap:1.25rem; overflow:hidden; border-radius:1rem; padding:0.9rem 1.85rem; margin:0.25rem 0 0.75rem 0; isolation:isolate;">
        
        <svg
            class="tya-banner-mountain"
            viewBox="0 0 400 220"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            style="position:absolute; right:-1.5rem; bottom:-1rem; width:22rem; max-width:45%; height:100%; z-index:0; pointer-events:none;"
        >
            <circle cx="325" cy="55" r="26" fill="#fde68a" opacity="0.55" />
            <path d="M30 220 L150 78 L205 148 L258 58 L400 220 Z" fill="#f2a765" opacity="0.5" />
            <path d="M0 220 L105 112 L175 190 L265 92 L400 220 Z" fill="#ea580c" opacity="0.65" />
        </svg>

        <div class="tya-banner-left" style="position:relative; z-index:1; flex-shrink:0;">
            <div class="tya-greeting-title">
                <?php echo e($this->getGreeting()); ?>, <?php echo e($this->getUserFirstName()); ?>

                <span class="wave">👋</span>
            </div>
            <div class="tya-greeting-sub"><?php echo e($this->getDateLabel()); ?> — semoga hari ini lancar.</div>
        </div>

        
        <div
            class="tya-sholat-card"
            style="position:relative; z-index:1; flex:1 1 auto; display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:0.85rem; background:transparent;"
            x-data="{
                status: 'memuat',
                items: [],
                init_() {
                    fetch('https://api.aladhan.com/v1/timingsByCity?city=Selong&country=Indonesia&method=20')
                        .then(r => r.json())
                        .then(data => {
                            const t = data.data.timings;
                            this.items = [
                                { nama: 'Subuh', jam: t.Fajr },
                                { nama: 'Dzuhur', jam: t.Dhuhr },
                                { nama: 'Ashar', jam: t.Asr },
                                { nama: 'Maghrib', jam: t.Maghrib },
                                { nama: 'Isya', jam: t.Isha },
                            ];
                            this.status = 'siap';
                        })
                        .catch(() => { this.status = 'gagal'; });
                }
            }"
            x-init="init_()"
        >
            <div class="tya-sholat-card-head" style="display:flex; align-items:center; gap:0.35rem; font-size:0.72rem; font-weight:800; color:#9a3412; white-space:nowrap;">
                <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-moon-star','class' => 'tya-sholat-card-icon','style' => 'width:0.85rem; height:0.85rem; color:#ea580c; flex-shrink:0;']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-moon-star','class' => 'tya-sholat-card-icon','style' => 'width:0.85rem; height:0.85rem; color:#ea580c; flex-shrink:0;']); ?>
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
                Jadwal Waktu Sholat
            </div>

            <template x-if="status === 'memuat'">
                <div class="tya-sholat-info" style="font-size:0.75rem; color:#a1a1aa;">Memuat...</div>
            </template>
            <template x-if="status === 'gagal'">
                <div class="tya-sholat-info" style="font-size:0.75rem; color:#a1a1aa;">Jadwal tidak tersedia.</div>
            </template>
            <template x-if="status === 'siap'">
                <div class="tya-sholat-rows" style="display:flex; flex-wrap:wrap; row-gap:0.3rem; column-gap:0.9rem; justify-content:flex-end;">
                    <template x-for="item in items" :key="item.nama">
                        <span class="tya-sholat-item" style="font-size:0.78rem; font-weight:700; color:#431407; white-space:nowrap;">
                            <span class="titik" style="color:#ea580c; margin-right:0.25rem;">•</span>
                            <span x-text="item.nama"></span> <span x-text="item.jam"></span>
                        </span>
                    </template>
                </div>
            </template>
        </div>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb525200bfa976483b4eaa0b7685c6e24)): ?>
<?php $attributes = $__attributesOriginalb525200bfa976483b4eaa0b7685c6e24; ?>
<?php unset($__attributesOriginalb525200bfa976483b4eaa0b7685c6e24); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb525200bfa976483b4eaa0b7685c6e24)): ?>
<?php $component = $__componentOriginalb525200bfa976483b4eaa0b7685c6e24; ?>
<?php unset($__componentOriginalb525200bfa976483b4eaa0b7685c6e24); ?>
<?php endif; ?>
<?php /**PATH /var/www/toya-radius/resources/views/filament/widgets/greeting-widget.blade.php ENDPATH**/ ?>