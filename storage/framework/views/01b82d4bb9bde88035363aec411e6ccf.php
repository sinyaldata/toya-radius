<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tenant — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo $__env->make('vpn-owner.partials.styles', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="tya-app">
    <?php echo $__env->make('vpn-owner.partials.sidebar', ['active' => 'tenants'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <div class="tya-main">
    <?php echo $__env->make('vpn-owner.partials.topbar', ['active' => 'tenants'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span><?php echo e(session('success')); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('warning')): ?>
        <div class="tya-flash warning"><span class="tya-flash-icon">⚠️</span><span><?php echo e(session('warning')); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span><?php echo e($errors->first()); ?></span></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php ($pending = $tenants->where('status', 'pending')); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($pending->isNotEmpty()): ?>
        <div class="tya-card amber">
            <div class="tya-card-head">
                <h2>⏳ Pendaftaran Menunggu Persetujuan (<?php echo e($pending->count()); ?>)</h2>
            </div>
            <div class="tya-table-wrap">
                <table class="tya-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Subdomain</th>
                            <th>Pemilik</th>
                            <th>Paket</th>
                            <th>Kontak</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $pending; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tenant): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td data-label="Nama"><strong><?php echo e($tenant->name); ?></strong></td>
                                <td data-label="Subdomain"><?php echo e($tenant->subdomain); ?>.<?php echo e(parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url')); ?></td>
                                <td data-label="Pemilik"><?php echo e($tenant->owner_name); ?></td>
                                <td data-label="Paket"><?php echo e($tenant->plan?->name ?? '-'); ?></td>
                                <td data-label="Kontak"><?php echo e($tenant->owner_email); ?><br><?php echo e($tenant->owner_phone); ?></td>
                                <td data-label="">
                                    <div style="display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end;">
                                        <form method="POST" action="<?php echo e(route('vpn-owner.tenants.approve', $tenant)); ?>" class="tya-inline-form">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" class="tya-btn sm">Setujui</button>
                                        </form>
                                        <form method="POST" action="<?php echo e(route('vpn-owner.tenants.reject', $tenant)); ?>" class="tya-inline-form" onsubmit="return confirm('Tolak & hapus pendaftaran ini?');">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit" class="tya-btn danger ghost sm">Tolak</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="tya-card" id="tambah-tenant">
        <div class="tya-card-head">
            <h2>➕ Tambah Tenant Baru</h2>
        </div>
        <form method="POST" action="<?php echo e(route('vpn-owner.tenants.store')); ?>">
            <?php echo csrf_field(); ?>
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Nama ISP / Perusahaan</label>
                    <input type="text" name="name" value="<?php echo e(old('name')); ?>" placeholder="mis. Ijoe Net" required>
                </div>
                <div class="tya-field">
                    <label>Subdomain</label>
                    <input type="text" name="subdomain" value="<?php echo e(old('subdomain')); ?>" placeholder="mis. ijoe" required>
                </div>
                <div class="tya-field">
                    <label>Nama Pemilik</label>
                    <input type="text" name="owner_name" value="<?php echo e(old('owner_name')); ?>">
                </div>
                <div class="tya-field">
                    <label>Email Pemilik (buat login SuperAdmin)</label>
                    <input type="email" name="owner_email" value="<?php echo e(old('owner_email')); ?>" required>
                </div>
                <div class="tya-field">
                    <label>No. HP</label>
                    <input type="text" name="owner_phone" value="<?php echo e(old('owner_phone')); ?>">
                </div>
                <div class="tya-field">
                    <label>Alamat</label>
                    <input type="text" name="address" value="<?php echo e(old('address')); ?>">
                </div>
                <div class="tya-field">
                    <label>Paket Tenant</label>
                    <select name="plan_catalog_id">
                        <option value="">— Belum dipilih —</option>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $planCatalogs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $paket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($paket->id); ?>" <?php if(old('plan_catalog_id') == $paket->id): echo 'selected'; endif; ?>>
                                <?php echo e($paket->name); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($paket->price_per_month): ?>
                                    (Rp<?php echo e(number_format($paket->price_per_month, 0, ',', '.')); ?>/bulan)
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </select>
                </div>
                <div class="tya-field">
                    <label>Jadwal Jatuh Tempo</label>
                    <input type="date" name="billing_due_date" value="<?php echo e(old('billing_due_date')); ?>">
                </div>
            </div>
            <p class="tya-hint">
                Otomatis: bikin database baru, jalankan semua migration aplikasi, buat akun SuperAdmin pertama.
                Proses ini bisa makan waktu beberapa detik.
                Paket & jadwal jatuh tempo boleh dikosongkan (mis. tenant internal/gratis) — kalau diisi,
                dashboard tenant ini otomatis menampilkan pengingat pembayaran mulai H-10 sebelum tanggal jatuh tempo.
            </p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Buat Tenant</button>
            </div>
        </form>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Tenant Aktif & Lainnya (<?php echo e($tenants->where('status', '!=', 'pending')->count()); ?>)</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Subdomain</th>
                        <th>Paket & Jatuh Tempo</th>
                        <th>Database</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $tenants->where('status', '!=', 'pending'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tenant): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <?php ($sisaHari = $tenant->daysUntilBillingDue()); ?>
                        <tr>
                            <td data-label="Nama"><strong><?php echo e($tenant->name); ?></strong></td>
                            <td data-label="Subdomain"><?php echo e($tenant->subdomain); ?>.<?php echo e(parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url')); ?></td>
                            <td data-label="Paket & Jatuh Tempo">
                                <form method="POST" action="<?php echo e(route('vpn-owner.tenants.billing', $tenant)); ?>" style="display:flex; flex-direction:column; gap:.3rem; min-width:11rem;">
                                    <?php echo csrf_field(); ?>
                                    <select name="plan_catalog_id" style="padding:0.3rem 0.5rem; font-size:0.75rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                        <option value="">— Belum dipilih —</option>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $planCatalogs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $paket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <option value="<?php echo e($paket->id); ?>" <?php if($tenant->plan_catalog_id === $paket->id): echo 'selected'; endif; ?>><?php echo e($paket->name); ?></option>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </select>
                                    <input type="date" name="billing_due_date" value="<?php echo e($tenant->billing_due_date?->format('Y-m-d')); ?>" style="padding:0.3rem 0.5rem; font-size:0.75rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sisaHari !== null): ?>
                                        <span class="tya-badge <?php echo e($sisaHari < 0 ? 'red' : ($sisaHari <= 10 ? 'amber' : 'green')); ?>" style="align-self:flex-start;">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sisaHari < 0): ?> Lewat <?php echo e(abs($sisaHari)); ?> hari
                                            <?php elseif($sisaHari === 0): ?> Jatuh tempo hari ini
                                            <?php else: ?> H-<?php echo e($sisaHari); ?>

                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <button type="submit" class="tya-btn ghost sm" style="align-self:flex-start;">Simpan</button>
                                </form>
                            </td>
                            <td data-label="Database"><?php echo e($tenant->database_name); ?></td>
                            <td data-label="Status">
                                <span class="tya-badge <?php echo e($tenant->status === 'active' ? 'green' : ($tenant->status === 'suspended' ? 'red' : 'amber')); ?>"><?php echo e(ucfirst($tenant->status)); ?></span>
                            </td>
                            <td data-label="Dibuat"><?php echo e($tenant->created_at->format('d/m/Y H:i')); ?></td>
                            <td data-label="">
                                <form method="POST" action="<?php echo e(route('vpn-owner.tenants.destroy', $tenant)); ?>" style="display:flex; gap:.4rem; align-items:center; justify-content:flex-end; flex-wrap:wrap;">
                                    <?php echo csrf_field(); ?>
                                    <?php echo method_field('DELETE'); ?>
                                    <input type="text" name="confirm_subdomain" placeholder="ketik: <?php echo e($tenant->subdomain); ?>" style="width:9rem; padding:0.4rem 0.6rem; font-size:0.72rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                    <button type="submit" class="tya-btn danger sm" onclick="return confirm('Yakin hapus tenant ini PERMANEN?');">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="7" class="tya-table-empty">Belum ada tenant aktif.</td></tr>
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
<?php /**PATH /var/www/toya-radius/resources/views/vpn-owner/tenants.blade.php ENDPATH**/ ?>