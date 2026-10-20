<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>VPN Owner Panel — Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --tya-orange: #f97316;
            --tya-orange-dark: #ea580c;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: radial-gradient(circle at 20% 15%, #27272a 0%, #18181b 45%, #0f0f11 100%);
            color: #e4e4e7;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; margin: 0; padding: 1.5rem;
            -webkit-font-smoothing: antialiased;
        }
        .tya-login-card {
            background: #202023; padding: 2.25rem 2rem; border-radius: 1.1rem;
            width: 100%; max-width: 23rem; border: 1px solid rgba(255,255,255,.08);
            box-shadow: 0 20px 50px rgba(0,0,0,.4);
        }
        .tya-login-badge {
            width: 3rem; height: 3rem; border-radius: .9rem; margin: 0 auto 1.1rem;
            background: linear-gradient(135deg, var(--tya-orange), var(--tya-orange-dark));
            display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
            box-shadow: 0 8px 20px rgba(249,115,22,.35);
        }
        h1 { font-size: 1.05rem; margin: 0 0 .3rem; text-align: center; font-weight: 700; }
        h1 span { color: var(--tya-orange); }
        .tya-login-desc { text-align: center; font-size: .78rem; color: #a1a1aa; margin: 0 0 1.6rem; }
        label { display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.45rem; color: #d4d4d8; }
        input[type=password] {
            width: 100%; box-sizing: border-box; padding: 0.7rem 0.85rem; border-radius: 0.6rem;
            border: 1px solid #3f3f46; background: #18181b; color: #fff; font-size: 0.9rem;
            margin-bottom: 1.2rem; font-family: inherit; transition: border-color .15s ease, box-shadow .15s ease;
        }
        input[type=password]:focus { outline: none; border-color: var(--tya-orange); box-shadow: 0 0 0 3px rgba(249,115,22,.2); }
        button {
            width: 100%; padding: 0.75rem; border-radius: 0.6rem; border: none;
            background: linear-gradient(135deg, var(--tya-orange), var(--tya-orange-dark));
            color: #fff; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit;
            transition: filter .15s ease, transform .08s ease;
        }
        button:hover { filter: brightness(1.06); }
        button:active { transform: scale(.99); }
        .error { display: flex; gap: .5rem; align-items: flex-start; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; font-size: 0.8rem; padding: .7rem .85rem; border-radius: .55rem; margin-bottom: 1.1rem; line-height: 1.45; }
        .note { font-size: 0.72rem; color: #71717a; margin-top: 1.4rem; text-align: center; line-height: 1.5; }

        @media (max-width: 400px) {
            .tya-login-card { padding: 1.75rem 1.4rem; }
        }
    </style>
</head>
<body>
    <div class="tya-login-card">
        <div class="tya-login-badge">🔒</div>
        <h1>VPN <span>Owner Panel</span></h1>
        <p class="tya-login-desc">Kontrol pusat Toya Radius — akses terbatas</p>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
            <div class="error"><span>⚠️</span><span><?php echo e($errors->first()); ?></span></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <form method="POST" action="<?php echo e(route('vpn-owner.login.submit')); ?>">
            <?php echo csrf_field(); ?>
            <label>Password Owner</label>
            <input type="password" name="password" autofocus required>
            <button type="submit">Masuk</button>
        </form>

        <div class="note">Halaman ini terpisah total dari login Toya Radius — akun admin/staff biasa tidak bisa akses ini.</div>
    </div>
</body>
</html>
<?php /**PATH /var/www/toya-radius/resources/views/vpn-owner/login.blade.php ENDPATH**/ ?>