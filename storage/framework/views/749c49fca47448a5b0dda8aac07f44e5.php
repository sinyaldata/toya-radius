<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Akun VPN — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo $__env->make('vpn-owner.partials.styles', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="tya-app">
    <?php echo $__env->make('vpn-owner.partials.sidebar', ['active' => 'index'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <div class="tya-main">
    <?php echo $__env->make('vpn-owner.partials.topbar', ['active' => 'index'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span><?php echo e(session('success')); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('provision_message')): ?>
        <div class="tya-flash warning"><span class="tya-flash-icon">⚠️</span><span><?php echo e(session('provision_message')); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span><?php echo e($errors->first()); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="tya-card tint">
        <div class="tya-card-head">
            <h2>Sedang Mengelola VPN Untuk</h2>
        </div>
        <form method="GET" action="<?php echo e(route('vpn-owner.index')); ?>" class="tya-grid">
            <div class="tya-field">
                <label>Pilih Tenant</label>
                <select name="tenant" onchange="this.form.submit()">
                    <option value="main" <?php echo e($currentTenant === 'main' ? 'selected' : ''); ?>><?php echo e(parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url')); ?> (Instalasi Utama)</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tenants; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($t->subdomain); ?>" <?php echo e($currentTenant === $t->subdomain ? 'selected' : ''); ?>><?php echo e($t->name); ?> (<?php echo e($t->subdomain); ?>)</option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
            </div>
        </form>
        <p class="tya-hint" style="margin-bottom:0;">Akun VPN yang dibuat/dilihat di halaman ini KHUSUS buat tenant yang dipilih — setiap tenant punya daftar akunnya sendiri.</p>
    </div>

    <div class="tya-flash info">
        <span class="tya-flash-icon">ℹ️</span>
        <span>
            <strong>L2TP (disarankan)</strong> — IP tunnel BENERAN tetap sesuai yang kamu tentukan, otomatis tersimpan ke <code>chap-secrets</code>, tidak perlu langkah manual lagi.<br>
            <strong>PPTP (SoftEther)</strong> — masih perlu 1 langkah manual di <code>vpncmd</code> server VPN setelah bikin akun (<code>Hub ToyaHub</code> → <code>UserCreate</code> → <code>UserRadiusSet</code>), dan IP tunnel-nya pakai trik <code>pref-src</code> di skrip MikroTik (bukan tetap beneran — batasan SoftEther SecureNAT).
        </span>
    </div>

    <div class="tya-card" id="generate-massal">
        <div class="tya-card-head">
            <h2>⚡ Generate Banyak Sekaligus</h2>
        </div>
        <form method="POST" action="<?php echo e(route('vpn-owner.store-bulk')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="tenant" value="<?php echo e($currentTenant); ?>">
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Prefix Username</label>
                    <input type="text" name="prefix" value="toyavpn" required>
                </div>
                <div class="tya-field">
                    <label>Jumlah Akun</label>
                    <input type="number" name="jumlah" value="10" min="1" max="50" required>
                </div>
                <div class="tya-field">
                    <label>IP Tunnel Awal</label>
                    <input type="text" name="ip_awal" placeholder="mis. 10.90.90.10" required>
                </div>
                <div class="tya-field">
                    <label>Jenis VPN</label>
                    <select name="vpn_type" required>
                        <option value="l2tp">L2TP (disarankan)</option>
                        <option value="pptp">PPTP (SoftEther)</option>
                    </select>
                </div>
            </div>
            <p class="tya-hint">IP berikutnya otomatis naik 1 per akun (mis. .10, .11, .12, dst).</p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Generate</button>
            </div>
        </form>
    </div>

    <div class="tya-card" id="buat-akun">
        <div class="tya-card-head">
            <h2>➕ Tambah Satu Akun VPN</h2>
        </div>
        <form method="POST" action="<?php echo e(route('vpn-owner.store')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="tenant" value="<?php echo e($currentTenant); ?>">
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="tya-field">
                    <label>IP Tunnel Tetap</label>
                    <input type="text" name="tunnel_ip" placeholder="mis. 10.90.90.51" required>
                </div>
                <div class="tya-field">
                    <label>Jenis VPN</label>
                    <select name="vpn_type" required>
                        <option value="l2tp">L2TP (disarankan)</option>
                        <option value="pptp">PPTP (SoftEther)</option>
                    </select>
                </div>
            </div>
            <p class="tya-hint">Password digenerate otomatis.</p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Buat Akun</button>
            </div>
        </form>
    </div>

    <div class="tya-card" id="daftar-akun">
        <div class="tya-card-head">
            <h2>Daftar Akun VPN (<?php echo e($accounts->count()); ?>)</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>IP Tunnel</th>
                        <th>Jenis</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $accounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $account): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td data-label="Username"><strong><?php echo e($account->username); ?></strong></td>
                            <td data-label="IP Tunnel"><?php echo e($account->tunnel_ip); ?></td>
                            <td data-label="Jenis"><span class="tya-badge blue"><?php echo e(strtoupper($account->vpn_type)); ?></span></td>
                            <td data-label="Status">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($account->isUsed()): ?>
                                    <span class="tya-badge amber">Dipakai: <?php echo e($account->nas?->shortname ?? "NAS sudah dihapus"); ?></span>
                                <?php else: ?>
                                    <span class="tya-badge green">Belum dipakai</span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                            <td data-label="">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($account->isUsed())): ?>
                                    <form method="POST" action="<?php echo e(route('vpn-owner.destroy', $account)); ?>?tenant=<?php echo e($currentTenant); ?>" onsubmit="return confirm('Hapus akun <?php echo e($account->username); ?>?');">
                                        <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                        <button type="submit" class="tya-btn danger sm">Hapus</button>
                                    </form>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="5" class="tya-table-empty">Belum ada akun VPN buat tenant ini.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div> 
    </div> 
</div> 
<?php echo $__env->make('vpn-owner.partials.scripts', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</body>
</html>
<?php /**PATH /var/www/toya-radius/resources/views/vpn-owner/index.blade.php ENDPATH**/ ?>