
<?php if (isset($component)) { $__componentOriginalb525200bfa976483b4eaa0b7685c6e24 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb525200bfa976483b4eaa0b7685c6e24 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament-widgets::components.widget','data' => ['class' => 'tya-stats-widget']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament-widgets::widget'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'tya-stats-widget']); ?>
    <style>
        .tya-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        @media (max-width: 1024px) {
            .tya-stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        /* Permintaan user: TETAP 2 kolom di HP juga (bukan 1 kolom
           penuh lebar seperti sebelumnya), jadi kartunya nggak
           kebesaran. */
        @media (max-width: 560px) {
            /* PENTING: semua properti di blok ini dikasih !important --
               sebelumnya TIDAK dikasih, dan karena blok media query ini
               ditulis di ATAS aturan dasarnya (yang tidak berubah, ada
               di bawah, sekitar baris 110-an), aturan DASAR itu yang
               justru MENANG di layar manapun (termasuk HP) -- CSS
               resmi-nya: dua aturan sama kuat, yang letaknya belakangan
               di file yang menang, TIDAK PEDULI media query-nya cocok
               atau tidak. !important memastikan blok HP ini SELALU
               menang di layar sempit, tidak bergantung urutan lagi. */
            .tya-stats-grid { grid-template-columns: repeat(2, 1fr) !important; gap: .5rem !important; }
            .tya-stat-card { padding: .6rem .65rem !important; }
            .tya-stat-icon-box { width: 1.8rem !important; height: 1.8rem !important; }
            .tya-stat-icon-box svg { width: .9rem !important; height: .9rem !important; }
            .tya-stat-value {
                font-size: .8rem !important;
                overflow-wrap: break-word !important;
                word-break: break-word !important;
            }
            .tya-stat-label { font-size: .62rem !important; }
            .tya-stat-desc { font-size: .56rem !important; }
            .tya-stat-tren { font-size: .56rem !important; padding: .12rem .35rem !important; }
            .tya-stat-minibar { height: 1.1rem !important; right: .6rem !important; bottom: .6rem !important; }
        }

        .tya-stat-card {
            position: relative;
            background: #ffffff;
            /* Permintaan user: kotak (tanpa lengkungan), tanpa garis
               tepi -- bayangan saja di belakangnya biar tidak
               menyatu dengan latar. */
            border: none;
            border-radius: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 8px 20px -8px rgba(0, 0, 0, 0.1);
            padding: 1.1rem 1.15rem;
            overflow: hidden;
            text-decoration: none !important;
            display: block;
            transition: box-shadow .15s ease, transform .15s ease;
        }
        .dark .tya-stat-card {
            background: #202023;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3), 0 8px 20px -8px rgba(0, 0, 0, 0.4);
        }
        a.tya-stat-card:hover {
            box-shadow: 0 10px 24px -8px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }

        .tya-stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: .6rem;
        }
        .tya-stat-icon-box {
            width: 2.6rem;
            height: 2.6rem;
            /* Permintaan user: ikut kotak juga (bukan cuma kartu
               luarnya), biar konsisten satu bahasa desain. */
            border-radius: .3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .tya-stat-icon-box svg { width: 1.25rem; height: 1.25rem; }
        .tya-stat-icon-box.oranye { background: #ea580c; }
        .tya-stat-icon-box.oranye svg { color: #ffffff; }
        .tya-stat-icon-box.netral { background: #f4f4f5; }
        .dark .tya-stat-icon-box.netral { background: rgba(255, 255, 255, 0.08); }
        .tya-stat-icon-box.netral svg { color: #52525b; }
        .dark .tya-stat-icon-box.netral svg { color: #d4d4d8; }

        .tya-stat-tren {
            display: flex;
            align-items: center;
            gap: .2rem;
            font-size: .68rem;
            font-weight: 700;
            padding: .2rem .5rem;
            border-radius: 999px;
        }
        .tya-stat-tren svg { width: .68rem; height: .68rem; }
        .tya-stat-tren.naik { background: rgba(34, 197, 94, .12); color: #16a34a; }
        .tya-stat-tren.turun { background: rgba(220, 38, 38, .1); color: #dc2626; }
        .tya-stat-online-dot {
            width: .55rem; height: .55rem; border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, .18);
        }

        .tya-stat-label {
            font-size: .75rem;
            font-weight: 500;
            color: #71717a;
            margin-bottom: .2rem;
        }
        .dark .tya-stat-label { color: #a1a1aa; }
        .tya-stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: #18181b;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .dark .tya-stat-value { color: #fafafa; }
        .tya-stat-desc {            font-size: .68rem;
            color: #a1a1aa;
            margin-top: .3rem;
        }

        /* Mini grafik batang dekoratif -- posisinya nempel kanan
           bawah kartu, warnanya cuma pelengkap visual (bukan data
           sungguhan), dibuat pakai div CSS biasa -- ringan, tanpa
           perlu memuat Chart.js buat elemen sekecil ini. */
        .tya-stat-minibar {
            position: absolute;
            right: 1rem;
            bottom: 1rem;
            display: flex;
            align-items: flex-end;
            gap: .15rem;
            height: 1.8rem;
            opacity: .35;
        }
        .tya-stat-minibar span {
            width: .2rem;
            border-radius: 999px .999px 0 0;
            background: currentColor;
        }
        .tya-stat-card .tya-stat-minibar { color: #ea580c; }
    </style>

    <div class="tya-stats-grid">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $this->getKartu(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php ($tag = $k['url'] ? 'a' : 'div'); ?>
            <<?php echo e($tag); ?> <?php if($k['url']): ?> href="<?php echo e($k['url']); ?>" <?php endif; ?> class="tya-stat-card">
                <div class="tya-stat-top">
                    <div class="tya-stat-icon-box <?php echo e($k['tema']); ?>">
                        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => $k['icon']]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($k['icon'])]); ?>
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
                    </div>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($k['online_dot'])): ?>
                        <span class="tya-stat-online-dot"></span>
                    <?php elseif($k['tren'] !== null): ?>
                        <span class="tya-stat-tren <?php echo e($k['tren'] >= 0 ? 'naik' : 'turun'); ?>">
                            <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => $k['tren'] >= 0 ? 'lucide-arrow-up' : 'lucide-arrow-down']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($k['tren'] >= 0 ? 'lucide-arrow-up' : 'lucide-arrow-down')]); ?>
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
                            <?php echo e($k['tren'] >= 0 ? '+' : ''); ?><?php echo e(number_format($k['tren'], 1)); ?>%
                        </span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <div class="tya-stat-label"><?php echo e($k['label']); ?></div>
                <div class="tya-stat-value"><?php echo e($k['value']); ?></div>
                <div class="tya-stat-desc">
                    <?php echo $k['desc_html'] ?? e($k['desc']); ?>

                </div>

                <div class="tya-stat-minibar">
                    <span style="height: 35%"></span>
                    <span style="height: 55%"></span>
                    <span style="height: 40%"></span>
                    <span style="height: 75%"></span>
                    <span style="height: 100%"></span>
                </div>
            </<?php echo e($tag); ?>>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/widgets/dashboard-stats.blade.php ENDPATH**/ ?>