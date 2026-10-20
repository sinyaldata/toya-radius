
<?php
    $lebarMm = (float) ($lebarMm ?? 58);
    // Ruang aman kiri/kanan: 58mm -> isi 48mm, 80mm -> isi 72mm (lebar
    // cetak nyata kebanyakan printer thermal).
    $sisiMm = $lebarMm <= 60 ? 5 : 4;
    $kontenMm = $lebarMm - (2 * $sisiMm);
    // Berapa huruf Courier 9px muat dalam satu baris (1 huruf = 0.6em).
    $kapasitas = (int) floor($kontenMm / 1.43);
    // Nilai/label panjang tanpa spasi (mis. nama paket panjang) tidak bisa
    // dipatahkan dompdf -- dipotong manual supaya TIDAK menembus tepi.
    $potong = fn ($teks, $maks) => nl2br(e(wordwrap((string) $teks, max(8, $maks), "\n", true)));
    $maksNilai = $kapasitas - 12;   // kolom kanan (label kiri paling panjang "Pelanggan" + celah)
    $maksLabel = $kapasitas - 12;   // kolom kiri baris tagihan (nominal "Rp150.000" + celah)
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk Pembayaran</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 9px;
            line-height: 1.35;
            color: #000;
        }
        #struk {
            width: <?php echo e($kontenMm); ?>mm;
            margin-left: <?php echo e($sisiMm); ?>mm;
            padding-top: 3mm;
            padding-bottom: 3mm;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .line { border-top: 1px dashed #000; margin: 1.5mm 0; height: 0; }
        .company-name { font-size: 11px; font-weight: 700; }
        .small { font-size: 7.5px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0.3mm 0; vertical-align: top; }
        .kiri { text-align: left; }
        .kanan { text-align: right; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
<div id="struk">
    <div class="center">
        <div class="company-name"><?php echo e(\App\Models\Setting::get('isp_name', config('app.name'))); ?></div>
        <div class="small"><?php echo e(\App\Models\Setting::get('isp_address')); ?></div>
        <div class="small"><?php echo e(\App\Models\Setting::get('isp_contact')); ?></div>
    </div>

    <div class="line"></div>

    <div class="center bold">BUKTI PEMBAYARAN</div>
    <div class="center small"><?php echo e($noStruk); ?></div>
    <div class="center small"><?php echo e($tanggal); ?></div>

    <div class="line"></div>

    <table>
        <tr><td class="kiri nowrap">Pelanggan</td><td class="bold kanan"><?php echo $potong($pelanggan->name, $maksNilai); ?></td></tr>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($pelanggan->servicePackage): ?>
            <tr><td class="kiri nowrap">Paket</td><td class="kanan"><?php echo $potong($pelanggan->servicePackage->name, $maksNilai); ?></td></tr>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($outletName): ?>
            <tr><td class="kiri nowrap">Outlet</td><td class="kanan"><?php echo $potong($outletName, $maksNilai); ?></td></tr>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </table>

    <div class="line"></div>

    <table>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td class="kiri"><?php echo $potong($item['label'], $maksLabel); ?></td>
                <td class="kanan nowrap">Rp<?php echo e(number_format($item['jumlah'], 0, ',', '.')); ?></td>
            </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </table>

    <div class="line"></div>

    <table>
        <tr><td class="bold kiri nowrap">TOTAL BAYAR</td><td class="bold kanan nowrap">Rp<?php echo e(number_format($total, 0, ',', '.')); ?></td></tr>
        <tr><td class="kiri nowrap">Metode</td><td class="kanan"><?php echo e($metode); ?></td></tr>
    </table>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sisaTagihan > 0 || $saldoLebih > 0): ?>
        <div class="line"></div>
        <table>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sisaTagihan > 0): ?>
                <tr><td class="kiri nowrap">Sisa Tunggakan</td><td class="kanan nowrap">Rp<?php echo e(number_format($sisaTagihan, 0, ',', '.')); ?></td></tr>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($saldoLebih > 0): ?>
                <tr><td class="kiri nowrap">Saldo Di Muka</td><td class="kanan nowrap">Rp<?php echo e(number_format($saldoLebih, 0, ',', '.')); ?></td></tr>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </table>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="line"></div>

    <div class="center small">Terima kasih atas pembayarannya</div>
    <div class="center small">Dicetak otomatis lewat bot Telegram</div>
</div>
</body>
</html>
<?php /**PATH /var/www/toya-radius/resources/views/exports/bot-payment-receipt-pdf.blade.php ENDPATH**/ ?>