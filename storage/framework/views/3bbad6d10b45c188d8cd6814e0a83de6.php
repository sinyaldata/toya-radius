<?php
    $customLogoPath = \App\Models\Setting::get('logo_path');
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($customLogoPath): ?>
    
    <img
        src="<?php echo e(\Illuminate\Support\Facades\Storage::disk('public')->url($customLogoPath)); ?>"
        alt="Logo"
        style="height: 2.6rem; width: auto; object-fit: contain;"
    />
<?php else: ?>

<svg viewBox="0 0 200 50" xmlns="http://www.w3.org/2000/svg" style="height: 2.6rem; width: auto;" role="img" aria-label="Toya Radius">
    <path d="M22 2 C22 2 7.7 18.7 7.7 29.7 C7.7 37.6 14.1 44 22 44 C29.9 44 36.3 37.6 36.3 29.7 C36.3 18.7 22 2 22 2 Z" fill="#ea580c" />
    <path d="M29.7 9.9 A13.2 13.2 0 0 1 38.5 22" stroke="#18181b" stroke-width="2.2" fill="none" stroke-linecap="round" opacity="0.85" />
    <path d="M33 4.4 A19.8 19.8 0 0 1 44 22" stroke="#18181b" stroke-width="2.2" fill="none" stroke-linecap="round" opacity="0.45" />
    <text x="56" y="26" font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="22" fill="currentColor">Toya<tspan fill="#ea580c">Radius</tspan></text>
    <text x="56" y="42" font-family="Arial, Helvetica, sans-serif" font-weight="600" font-size="6.3" letter-spacing="0.3" fill="currentColor" opacity="0.55">BILLING RADIUS KITA BERSAMA</text>
</svg>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /var/www/toya-radius/resources/views/filament/branding/logo.blade.php ENDPATH**/ ?>