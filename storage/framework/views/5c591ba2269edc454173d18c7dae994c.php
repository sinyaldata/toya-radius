<?php
    $ada = ! empty($versi['kode']);
    $mb = $ada && ! empty($versi['ukuran']) ? number_format($versi['ukuran'] / 1048576, 1, ',', '.') . ' MB' : null;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f97316">
<title>Unduh Aplikasi Toya Radius</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f4f6; color: #18181b; }
  .atas { padding: 48px 22px 84px; text-align: center; color: #fff; border-radius: 0 0 32px 32px;
    background: radial-gradient(circle at 92% 0%, rgba(255,255,255,.14) 0 110px, transparent 111px), linear-gradient(135deg, #fb923c, #f97316 55%, #ea580c); }
  .ikon { width: 84px; height: 84px; margin: 0 auto 14px; border-radius: 24px; background: rgba(255,255,255,.18); box-shadow: inset 0 0 0 1px rgba(255,255,255,.3); display: flex; align-items: center; justify-content: center; }
  .ikon svg { width: 46px; height: 46px; }
  h1 { margin: 0; font-size: 25px; font-weight: 800; }
  .atas p { margin: 6px 0 0; color: rgba(255,255,255,.9); font-size: 14.5px; }
  .kartu { max-width: 460px; margin: -56px auto 0; background: #fff; border-radius: 22px; padding: 22px 20px; box-shadow: 0 16px 34px -18px rgba(234,88,12,.45), 0 1px 3px rgba(0,0,0,.05); position: relative; }
  .info { display: flex; justify-content: space-around; text-align: center; margin-bottom: 18px; }
  .info b { display: block; font-size: 17px; }
  .info small { color: #71717a; font-size: 12px; }
  .unduh { display: flex; align-items: center; justify-content: center; gap: 10px; height: 56px; border-radius: 15px; color: #fff; text-decoration: none; font-weight: 800; font-size: 16.5px; background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 10px 22px -10px rgba(234,88,12,.8); }
  .unduh svg { width: 22px; height: 22px; }
  .kosong { padding: 16px; border-radius: 14px; background: #fff7ed; color: #9a3412; font-size: 14px; text-align: center; }
  .catatan { margin-top: 14px; padding: 12px 14px; border-radius: 14px; background: #fafafa; font-size: 13.5px; color: #52525b; white-space: pre-line; }
  .langkah { max-width: 460px; margin: 22px auto 34px; padding: 0 20px; }
  .langkah h2 { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #71717a; margin: 0 4px 12px; }
  .l { display: flex; gap: 12px; align-items: flex-start; background: #fff; border-radius: 16px; padding: 13px 14px; margin-bottom: 8px; font-size: 14px; line-height: 1.45; }
  .l i { flex: 0 0 auto; width: 28px; height: 28px; border-radius: 9px; background: #fff7ed; color: #ea580c; font-style: normal; font-weight: 800; display: flex; align-items: center; justify-content: center; }
</style>
</head>
<body>
<div class="atas">
  <div class="ikon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a15 15 0 0 1 20 0"/><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1.2" fill="#fff"/></svg></div>
  <h1>Toya Radius</h1>
  <p>Aplikasi Android untuk pemilik ISP, admin &amp; outlet</p>
</div>

<div class="kartu">
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ada): ?>
    <div class="info">
      <div><b>v<?php echo e($versi['versi'] ?? '-'); ?></b><small>Versi</small></div>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mb): ?><div><b><?php echo e($mb); ?></b><small>Ukuran</small></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($versi['tanggal'])): ?><div><b><?php echo e(\Illuminate\Support\Carbon::parse($versi['tanggal'])->format('d/m/Y')); ?></b><small>Rilis</small></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <a class="unduh" href="/unduh/toya-radius.apk?v=<?php echo e($versi['kode']); ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M6.5 10.5L12 16l5.5-5.5M4 20h16"/></svg>
      Unduh APK
    </a>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($versi['catatan'])): ?><div class="catatan"><?php echo e($versi['catatan']); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php else: ?>
    <div class="kosong">Aplikasi sedang disiapkan. Silakan cek lagi nanti.</div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<div class="langkah">
  <h2>Cara memasang</h2>
  <div class="l"><i>1</i><div>Ketuk <b>Unduh APK</b>, tunggu sampai selesai.</div></div>
  <div class="l"><i>2</i><div>Buka file <b>toya-radius.apk</b> dari notifikasi atau folder Download.</div></div>
  <div class="l"><i>3</i><div>Kalau muncul peringatan, pilih <b>Setelan</b> → izinkan <b>Instal aplikasi tidak dikenal</b> untuk browser Anda, lalu kembali dan ketuk <b>Instal</b>.</div></div>
  <div class="l"><i>4</i><div>Buka aplikasi, isi <b>Kode ISP</b> (bagian depan alamat, mis. <b>madani</b>), lalu login seperti biasa.</div></div>
</div>
</body>
</html>
<?php /**PATH /var/www/toya-radius/resources/views/aplikasi-hp/unduh.blade.php ENDPATH**/ ?>