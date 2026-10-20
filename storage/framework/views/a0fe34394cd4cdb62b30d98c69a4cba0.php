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
        .tya-card { background: #fff; border-radius: 0.85rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03), 0 4px 14px rgba(0,0,0,0.02); overflow: hidden; }
        .dark .tya-card { background: #27272a; }
        .tya-card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.85rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; }
        .dark .tya-card-header { border-bottom-color: rgba(255,255,255,0.08); }
        .tya-card-title { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; font-weight: 700; color: #18181b; }
        .dark .tya-card-title { color: #fff; }
        .tya-card-title svg { width: 16px; height: 16px; color: #f97316; }

        /* Samakan tinggi kartu dengan widget Aktivitas Login yang
           sebelahnya (permintaan user) -- sebelumnya cuma widget itu
           yang punya min-height, jadi tinggi ketiga kartu di baris ini
           beda-beda mengikuti isinya masing-masing. flex-column +
           margin-top:auto pada paginasi bikin tombol halaman selalu
           menempel di dasar kartu, jadi ketiganya rata rapi.
           Cuma di layar lebar (>=1024px) di mana ketiganya memang
           sebaris -- di HP mereka bertumpuk vertikal, tidak perlu
           dipaksa tinggi. */
        @media (min-width: 1024px) {
            /* Ketiga kartu di baris ini otomatis menyamakan diri ke
               YANG PALING TINGGI. Struktur Filament: .fi-wi (grid) >
               .fi-wi-widget (kolom) > .tya-card. Kolomnya sudah
               otomatis meregang setinggi baris (grid align-items:
               stretch bawaan), jadi cukup kartunya disuruh mengisi
               penuh kolom itu. min-height saja tidak cukup: begitu satu
               kartu isinya melebihi 420px (mis. teks melipat 2 baris),
               yang lain tetap berhenti di 420px dan jadi lebih pendek. */
            .tya-card { display: flex; flex-direction: column; height: 100%; min-height: 420px; }
            .tya-pagination { margin-top: auto; }
        }
        .tya-pill-group { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .tya-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.6875rem; font-weight: 700; }
        .tya-pill.paid { background: #f97316; color: #fff; }

        .tya-table-wrap { overflow-x: auto; }
        table.tya-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        table.tya-table th { text-align: left; padding: 0.5rem 1rem; font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #a1a1aa; border-bottom: 1px solid rgba(0,0,0,0.06); white-space: nowrap; }
        .dark table.tya-table th { border-bottom-color: rgba(255,255,255,0.08); }
        table.tya-table td { padding: 0.6rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle; }
        .dark table.tya-table td { border-bottom-color: rgba(255,255,255,0.05); }
        table.tya-table tr:last-child td { border-bottom: none; }

        .tya-main-text { font-weight: 600; color: #18181b; }
        .dark .tya-main-text { color: #fff; }
        .tya-sub-text { color: #a1a1aa; font-size: 0.6875rem; margin-top: 0.1rem; }
        .tya-code { font-family: 'Courier New', monospace; font-weight: 700; color: #ea580c; }

        .tya-pagination { display: flex; align-items: center; justify-content: center; gap: 0.3rem; padding: 0.75rem; border-top: 1px solid rgba(0,0,0,0.06); }
        .dark .tya-pagination { border-top-color: rgba(255,255,255,0.08); }
        .tya-pagination button { min-width: 26px; height: 26px; border-radius: 0.4rem; border: 1px solid rgba(0,0,0,0.1); background: #fff; font-size: 0.75rem; cursor: pointer; color: #52525b; }
        .dark .tya-pagination button { background: #18181b; border-color: rgba(255,255,255,0.1); color: #d4d4d8; }
        .tya-pagination button.active { background: #f97316; color: #fff; border-color: #f97316; }
        .tya-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .tya-empty { padding: 2rem 1rem; text-align: center; color: #a1a1aa; font-size: 0.8125rem; }

        /* ===== Kartu daftar RINGKAS (Aktivitas Login / Top Invoice / Top Voucher Sales) =====
           PERBAIKAN (2026-10-02, laporan user: "di laptop 13 inci kartu ini
           melonjong"). Di laptop 13" dashboard memakai 3 kolom dengan sidebar
           terbuka, jadi tiap kartu cuma +-300px. Tabel 3-4 kolom dipaksa muat:
           nomor invoice/transaksi patah jadi 3 baris, tanggal melipat, muncul
           batang geser mendatar, dan karena tinggi ketiga kartu disamakan ke
           yang tertinggi, semuanya jadi memanjang 600-750px.
           Sekarang: ukuran diukur dari LEBAR KARTU itu sendiri (container
           query, bukan lebar layar), dan kalau kartu <= 480px tiap baris tabel
           disusun ulang jadi 2 baris yang rapat. Kartu yang lebih lebar (>480px)
           tampil PERSIS seperti semula -- SEMUA aturan di bawah (termasuk
           nowrap/ellipsis) sengaja hanya berlaku di dalam @container, supaya
           layar lebar tidak ikut berubah. (Versi pertama memakai batas 560px
           dan salah menyalakan mode ringkas di layar +-507px/kartu.) */
        .tya-kartu-daftar { container-type: inline-size; container-name: tya-kartu; }
        .tya-tgl-pendek { display: none; }

        @container tya-kartu (max-width: 480px) {
            .tya-nowrap { white-space: nowrap; }
            .tya-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
            .tya-min0 { min-width: 0; }
            .tya-table-wrap { overflow-x: visible; }
            table.tya-table thead { display: none; }
            table.tya-table, table.tya-table tbody { display: block; width: 100%; }
            table.tya-table tr {
                display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 0.6rem; row-gap: 0.2rem;
                align-items: center; padding: 0.55rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.05);
            }
            .dark table.tya-table tr { border-bottom-color: rgba(255,255,255,0.06); }
            table.tya-table tr:last-child { border-bottom: none; }
            table.tya-table td { display: block; padding: 0; border: 0; min-width: 0; }
            .tya-tgl-penuh { display: none; }
            .tya-tgl-pendek { display: inline; }
            /* Nomor + nominal sebaris (bukan ditumpuk) */
            table.tya-table td .tya-main-text + .tya-sub-text { margin-top: 0; }

            /* --- Top Invoice: baris 1 = nomor + nominal + status ; baris 2 = pelanggan | tanggal --- */
            table.tya-tbl-invoice tr { grid-template-areas: "inv inv" "cust tgl"; }
            table.tya-tbl-invoice td:nth-child(1) { grid-area: inv; display: flex; flex-wrap: wrap; align-items: baseline; column-gap: 0.5rem; row-gap: 0; }
            /* Nominal + status turun BERSAMA kalau tidak muat sebaris (bukan lencananya patah sendiri) */
            table.tya-tbl-invoice td:nth-child(1) .tya-sub-text { white-space: nowrap; }
            table.tya-tbl-invoice td:nth-child(2) { grid-area: cust; }
            table.tya-tbl-invoice td:nth-child(3) { grid-area: tgl; text-align: right; white-space: nowrap; }

            /* --- Top Voucher Sales: baris 1 = no. trx + nominal | tanggal ; baris 2 = kode + paket | penjual --- */
            table.tya-tbl-voucher tr { grid-template-areas: "trx tgl" "kode jual"; }
            table.tya-tbl-voucher td:nth-child(1) { grid-area: trx; display: flex; align-items: baseline; gap: 0.5rem; }
            table.tya-tbl-voucher td:nth-child(2) { grid-area: kode; display: flex; align-items: baseline; gap: 0.5rem; }
            table.tya-tbl-voucher td:nth-child(3) { grid-area: jual; text-align: right; max-width: 7.5rem; }
            table.tya-tbl-voucher td:nth-child(4) { grid-area: tgl; text-align: right; white-space: nowrap; }
            table.tya-tbl-voucher td:nth-child(3), table.tya-tbl-voucher td:nth-child(2) .tya-sub-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            table.tya-tbl-voucher td:nth-child(2) .tya-sub-text { margin-top: 0; min-width: 0; }

            /* --- Aktivitas Login: judul & email 1 baris, nama tidak diulang di baris meta --- */
            .tya-login-title, .tya-login-email { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tya-meta .name, .tya-meta .name + span { display: none; }
            .tya-timeline-item { margin-bottom: 0.8rem; }
        }
    </style>

    <div class="tya-card tya-kartu-daftar">
        <div class="tya-card-header">
            <div class="tya-card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6m-5 4h4m-7 4h10a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v3a2 2 0 100 4v3a2 2 0 002 2z" />
                </svg>
                Top 20 Voucher Sales
            </div>
            <?php ($vc = $this->getVoucherCounts()); ?>
            <div class="tya-pill-group">
                <span class="tya-pill paid">Terjual <?php echo e($vc['total']); ?></span>
            </div>
        </div>

        <?php ($voucherRows = $this->getVoucherRows()); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($voucherRows->isEmpty()): ?>
            <div class="tya-empty">Belum ada penjualan voucher.</div>
        <?php else: ?>
            <div class="tya-table-wrap">
                <table class="tya-table tya-tbl-voucher">
                    <thead>
                        <tr>
                            <th>Kode Trx</th>
                            <th>Kode Voucher</th>
                            <th>Penjual</th>
                            <th>Dibayar Pada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $voucherRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td>
                                    <div class="tya-main-text tya-nowrap"><?php echo e($trx->transaction_number); ?></div>
                                    <div class="tya-sub-text">Rp<?php echo e(number_format($trx->total_amount, 0, ',', '.')); ?></div>
                                </td>
                                <td>
                                    <div class="tya-code"><?php echo e($trx->voucher?->username ?? '-'); ?></div>
                                    <div class="tya-sub-text"><?php echo e($trx->voucher?->profile?->name ?? '-'); ?></div>
                                </td>
                                <td class="tya-sub-text"><?php echo e($trx->outlet?->name ?? $trx->cashier?->name ?? 'Stok Pusat'); ?></td>
                                <td class="tya-sub-text"><span class="tya-tgl-penuh"><?php echo e($trx->transaction_date?->format('Y-m-d H:i:s') ?? '-'); ?></span><span class="tya-tgl-pendek"><?php echo e($trx->transaction_date?->format('d/m H:i') ?? '-'); ?></span></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php ($voucherTotalPages = $this->getVoucherTotalPages()); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($voucherTotalPages > 1): ?>
                <div class="tya-pagination">
                    <button wire:click="goToVoucherPage(<?php echo e($voucherPage - 1); ?>)" <?php if($voucherPage <= 1): echo 'disabled'; endif; ?>>&lsaquo;</button>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($p = 1; $p <= $voucherTotalPages; $p++): ?>
                        <button wire:click="goToVoucherPage(<?php echo e($p); ?>)" class="<?php echo e($p === $voucherPage ? 'active' : ''); ?>"><?php echo e($p); ?></button>
                    <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <button wire:click="goToVoucherPage(<?php echo e($voucherPage + 1); ?>)" <?php if($voucherPage >= $voucherTotalPages): echo 'disabled'; endif; ?>>&rsaquo;</button>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/widgets/voucher-sales-list.blade.php ENDPATH**/ ?>