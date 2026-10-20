
<?php
    $perkiraanKarakterPerBaris = 85;
    $barisTampilan = 0;

    foreach (explode("\n", trim($script)) as $baris) {
        $barisTampilan += max(1, (int) ceil(mb_strlen($baris) / $perkiraanKarakterPerBaris));
    }

    $baris = max(5, min(16, $barisTampilan + 1));
?>
<div>
    <textarea readonly rows="<?php echo e($baris); ?>" onclick="this.select()"
        style="width:100%; font-family: ui-monospace, monospace; font-size: 11.5px; padding: 8px 10px; border-radius: 8px; border: 1px solid #d1d5db; background: #0f172a; color: #e2e8f0; box-sizing: border-box; line-height: 1.45;"
    ><?php echo e($script); ?></textarea>

    <button type="button"
        x-data
        @click="navigator.clipboard.writeText($el.previousElementSibling.value); $el.innerText='Tersalin ✓'; setTimeout(() => $el.innerText='Salin Script', 1500);"
        style="margin-top: 6px; padding: 5px 12px; border-radius: 8px; background: #f97316; color: white; font-weight: 600; font-size: 12px; border: none; cursor: pointer;"
    >Salin Script</button>
</div>
<?php /**PATH /var/www/toya-radius/resources/views/filament/resources/nas-resource/script-preview.blade.php ENDPATH**/ ?>