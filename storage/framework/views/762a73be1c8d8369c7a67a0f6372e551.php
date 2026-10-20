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
        .tya-hs-card {
            background: #ffffff;
            border: 1px solid #e4e4e7;
        }
        .tya-hs-card .tya-hs-label { color: #71717a; }
        .tya-hs-card .tya-hs-value { color: #1c1917; }
        .tya-hs-card .tya-hs-note { color: #c2410c; }

        /* Kartu yang bisa diklik (ada 'url') -- dibungkus <a>, jadi
         * warna teks & underline bawaan link dinetralkan supaya
         * tampilannya tetap sama persis dengan kartu biasa. */
        a.tya-hs-card {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .dark .tya-hs-card {
            background: #27272a;
            border-color: rgba(255,255,255,.08);
        }
        .dark .tya-hs-card .tya-hs-label { color: #a1a1aa; }
        .dark .tya-hs-card .tya-hs-value { color: #fafafa; }
        .dark .tya-hs-card .tya-hs-note { color: #fb923c; }

        @media (max-width: 1279px) {
            .tya-hs-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }

        @media (max-width: 640px) {
            .tya-hs-grid { grid-template-columns: repeat(2, 1fr) !important; }

            /* Ikon kartu (Total Voucher, Voucher Expired, dst) kelihatan
             * terlalu besar di HP karena ukurannya inline/tetap (2.75rem)
             * padahal kartunya sendiri menyempit jadi 2 kolom -- dikecilkan
             * khusus di mode HP supaya proporsional lagi. */
            .tya-hs-icon-ctn {
                width: 2rem !important;
                height: 2rem !important;
                border-radius: 0.5rem !important;
            }

            .tya-hs-icon-ctn svg {
                width: 1rem !important;
                height: 1rem !important;
            }

            .tya-hs-value {
                font-size: 1.3rem !important;
            }
        }
    </style>

    
    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1rem;" class="tya-hs-grid">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $this->getCards(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                // Kartu jadi <a> (bisa diklik) kalau 'url' diisi, kalau
                // tidak tetap <div> biasa seperti sebelumnya.
                $tagKartu = ! empty($card['url']) ? 'a' : 'div';
                $hrefKartu = ! empty($card['url']) ? ' href="'.e($card['url']).'"' : '';
            ?>
            <<?php echo $tagKartu; ?>

                class="tya-hs-card"<?php echo $hrefKartu; ?>

                style="
                    display: block;
                    border-radius: 0.85rem;
                    padding: 1.1rem 1.25rem;
                    position: relative;
                    overflow: hidden;
                    transition: transform .15s ease, box-shadow .15s ease;
                "
                onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px -6px rgba(249,115,22,.25)';"
                onmouseout="this.style.transform=''; this.style.boxShadow='';"
            >
                
                <div style="position:absolute; left:0; top:0; bottom:0; width:4px; background:#f97316;"></div>

                <div style="display:flex; align-items:center; justify-content:space-between; gap:.85rem; width:100%;">
                    <div>
                        <div class="tya-hs-label" style="font-size:.72rem; font-weight:600; letter-spacing:.02em; text-transform:uppercase;">
                            <?php echo e($card['label']); ?>

                        </div>
                        <div class="tya-hs-value" style="font-size:1.45rem; font-weight:800; line-height:1.15; margin-top:.2rem;">
                            <?php echo e(number_format($card['value'])); ?>

                        </div>

                        
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($card['note'])): ?>
                            <div class="tya-hs-note" style="font-size:.68rem; font-weight:600; margin-top:.3rem;">
                                <?php echo e($card['note']); ?>

                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    <div class="tya-hs-icon-ctn" style="
                        width:2.75rem; height:2.75rem; border-radius:0.65rem;
                        background:#f97316; color:#fff;
                        display:flex; align-items:center; justify-content:center;
                        box-shadow:0 4px 10px -2px rgba(249,115,22,.5);
                        flex-shrink:0;
                    ">
                        <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => $card['icon'],'style' => 'width:1.35rem; height:1.35rem;']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($card['icon']),'style' => 'width:1.35rem; height:1.35rem;']); ?>
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/resources/hotspot-user-resource/widgets/hotspot-user-overview.blade.php ENDPATH**/ ?>