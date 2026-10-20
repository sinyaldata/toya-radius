
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
    <style>
        .tya-sc-grid {
            display: grid;
            gap: 1rem;
            /* Jumlah kolom menyesuaikan sendiri: minimal 210px per
             * kartu, sisanya dibagi rata. Jadi 2 kartu tampil lebar,
             * 4 kartu tetap muat sejajar di layar besar, dan otomatis
             * turun ke bawah di layar sempit tanpa media query. */
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        }

        .tya-sc-card {
            position: relative;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 0;
            padding: 1.1rem 1.25rem;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .tya-sc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -6px rgba(249, 115, 22, .25);
        }

        /* Kartu yang bisa diklik (ada 'url') -- dibungkus <a>, jadi
         * warna teks & underline bawaan link perlu dinetralkan supaya
         * tampilannya tetap sama persis dengan kartu biasa. */
        a.tya-sc-card {
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .tya-sc-accent { position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .tya-sc-row { display: flex; align-items: center; justify-content: space-between; gap: .85rem; }
        .tya-sc-label { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #71717a; }
        .tya-sc-value { font-size: 1.4rem; font-weight: 800; line-height: 1.15; margin-top: .25rem; color: #1c1917; }
        .tya-sc-note { font-size: .68rem; font-weight: 600; margin-top: .3rem; color: #c2410c; }
        .tya-sc-icon {
            width: 2.6rem; height: 2.6rem; border-radius: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff; flex-shrink: 0;
        }

        .dark .tya-sc-card { background: #27272a; border-color: rgba(255,255,255,.08); }
        .dark .tya-sc-label { color: #a1a1aa; }
        .dark .tya-sc-value { color: #fafafa; }
        .dark .tya-sc-note { color: #fb923c; }

        @media (max-width: 640px) {
            .tya-sc-grid { grid-template-columns: repeat(2, 1fr); gap: .6rem; }
            .tya-sc-card { padding: .8rem .85rem; }
            .tya-sc-value { font-size: 1.1rem; }
            .tya-sc-label { font-size: .62rem; letter-spacing: .03em; }
            .tya-sc-icon { width: 1.9rem; height: 1.9rem; }
            .tya-sc-icon svg { width: 1rem !important; height: 1rem !important; }
        }
    </style>

    <div class="tya-sc-grid">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $this->getCards(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $warna = $card['color'] ?? '#f97316';
                // Kartu jadi <a> (bisa diklik) kalau 'url' diisi, kalau
                // tidak tetap <div> biasa seperti sebelumnya. Tag & atribut
                // href disiapkan di sini (bukan langsung ditulis di tag)
                // supaya href tetap lolos escaping HTML normal lewat e().
                $tagKartu = ! empty($card['url']) ? 'a' : 'div';
                $hrefKartu = ! empty($card['url']) ? ' href="'.e($card['url']).'"' : '';
            ?>
            <<?php echo $tagKartu; ?> class="tya-sc-card"<?php echo $hrefKartu; ?>>
                <div class="tya-sc-accent" style="background: <?php echo e($warna); ?>;"></div>

                <div class="tya-sc-row">
                    <div style="min-width:0;">
                        <div class="tya-sc-label"><?php echo e($card['label']); ?></div>
                        <div class="tya-sc-value"><?php echo e($card['value']); ?></div>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($card['note'])): ?>
                            <div class="tya-sc-note"><?php echo e($card['note']); ?></div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    <div class="tya-sc-icon" style="background: <?php echo e($warna); ?>;">
                        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => $card['icon'],'style' => 'width:1.3rem; height:1.3rem;']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($card['icon']),'style' => 'width:1.3rem; height:1.3rem;']); ?>
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
                </div>
            </<?php echo $tagKartu; ?>>
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/widgets/tya-stat-cards.blade.php ENDPATH**/ ?>