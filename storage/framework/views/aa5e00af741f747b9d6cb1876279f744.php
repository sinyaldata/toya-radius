<?php
    $timezone = config('app.timezone', 'Asia/Jakarta');
?>

<div
    x-data="{
        base: <?php echo e(now($timezone)->valueOf()); ?>,
        startedAt: Date.now(),
        timezone: '<?php echo e($timezone); ?>',
        now: null,
        tick() {
            this.now = new Date(this.base + (Date.now() - this.startedAt));
        },
        formatted() {
            if (!this.now) return '';
            const parts = new Intl.DateTimeFormat('en-GB', {
                timeZone: this.timezone, day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
            }).formatToParts(this.now).reduce((acc, p) => ({ ...acc, [p.type]: p.value }), {});
            return `${parts.day}-${parts.month}-${parts.year} ${parts.hour}:${parts.minute}:${parts.second}`;
        },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)"
    class="tya-waktu-server"
>
    <?php if (isset($component)) { $__componentOriginalbfc641e0710ce04e5fe02876ffc6f950 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfc641e0710ce04e5fe02876ffc6f950 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.icon','data' => ['icon' => 'lucide-clock','class' => 'tya-waktu-server-icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'lucide-clock','class' => 'tya-waktu-server-icon']); ?>
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
    <div class="tya-waktu-server-lines">
        <span class="tya-waktu-server-label">Waktu Server</span>
        <span x-text="formatted()" class="tya-waktu-server-value"></span>
    </div>
</div>

<?php /**PATH /var/www/toya-radius/resources/views/filament/topbar-clock.blade.php ENDPATH**/ ?>