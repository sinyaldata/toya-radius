<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo e($batchLabel); ?></title>
    <style>
        
        @page { margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 0; }

        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-after: always;
        }
        table.sheet:last-child { page-break-after: auto; }
        table.sheet td { width: 20%; padding: 0.6mm; vertical-align: top; }

        .card {
            border: 1px dashed #71717a;
            padding: 1.2mm 1.3mm;
            text-align: center;
            position: relative;
            line-height: 1.1;
        }
        .v-no {
            position: absolute;
            top: 0.4mm;
            right: 0.8mm;
            font-size: 5.5pt;
            font-weight: bold;
            color: #a1a1aa;
            line-height: 1;
        }
        .v-isp {
            font-size: 5.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #ea580c;
            padding-right: 4mm;
            line-height: 1.1;
        }
        .v-outlet {
            font-size: 7pt;
            font-weight: bold;
            margin-top: 0.2mm;
            padding-right: 4mm;
            line-height: 1.1;
        }
        .v-kode {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 12pt;
            letter-spacing: 0.4px;
            margin: 0.8mm 0;
            line-height: 1.05;
        }
        .v-meta { font-size: 6pt; color: #52525b; line-height: 1.1; }
        .v-harga { font-weight: bold; color: #16a34a; font-size: 8.5pt; margin-top: 0.4mm; line-height: 1.1; }
    </style>
</head>
<body>
    <?php
        $kolom = 5;
        $perLembar = 50;
        // Nomor urut dihitung pakai PENCACAH manual ($nomorCounter),
        // BUKAN dari kunci koleksi -- chunk() (baik chunk luar per
        // lembar maupun chunk dalam per baris) mempertahankan kunci
        // ASLI tiap item, jadi begitu masuk lembar ke-2 dst kuncinya
        // sudah bukan 0,1,2,... lagi. Kalau tetap dipakai buat hitung
        // nomor urut, lembar ke-2 dan seterusnya jadi salah semua.
        $nomorCounter = 0;
    ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vouchers->values()->chunk($perLembar); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $halaman): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <table class="sheet">
            <tbody>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $halaman->values()->chunk($kolom); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $baris): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $baris; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $voucher): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php $nomorCounter++; ?>
                            <td>
                                <div class="card">
                                    <span class="v-no"><?php echo e($nomorCounter); ?></span>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tampilkanIsp): ?>
                                        <div class="v-isp"><?php echo e($ispName); ?></div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <div class="v-outlet"><?php echo e($outletName); ?></div>
                                    <div class="v-kode">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showPassword): ?>
                                            U:<?php echo e($voucher->username); ?><br>P:<?php echo e($voucher->password); ?>

                                        <?php else: ?>
                                            <?php echo e($voucher->username); ?>

                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                    <div class="v-meta">Aktif <?php echo e($durasiAktif); ?> &middot; Berlaku <?php echo e($masaBerlaku); ?></div>
                                    <div class="v-harga">Rp<?php echo e(number_format($voucher->sale_price, 0, ',', '.')); ?></div>
                                </div>
                            </td>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = $baris->count(); $i < $kolom; $i++): ?>
                            <td></td>
                        <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </tbody>
        </table>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>
</html>
<?php /**PATH /var/www/toya-radius/resources/views/exports/voucher-cards-pdf.blade.php ENDPATH**/ ?>