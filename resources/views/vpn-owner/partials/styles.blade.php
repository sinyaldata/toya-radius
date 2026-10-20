{{-- Design system bersama untuk semua halaman VPN Owner Panel.
     Di-@include di <head> tiap halaman supaya CSS tidak duplikat 6x dan
     supaya tampilan (dan perbaikan mobile) konsisten di semua halaman
     sekaligus cukup edit di SATU tempat. Tidak ada perubahan logic PHP
     apa pun di sini -- murni desain ulang tampilan.

     Skrip kecil ini dijalankan SEBELUM <style> supaya tema gelap/terang
     yang tersimpan langsung kepakai dari awal render halaman -- tanpa
     ini, halaman akan kelihatan "kedip" putih sesaat sebelum berubah ke
     gelap tiap kali dibuka. --}}
<script>
    (function () {
        try {
            var tema = localStorage.getItem('tya-theme');
            if (tema === 'dark' || tema === 'light') {
                document.documentElement.setAttribute('data-theme', tema);
            }
        } catch (e) {}
    })();
</script>
{{--
     REDESIGN (permintaan user, sesuai referensi gambar): sidebar gelap
     di kiri + topbar (cari/online/tema/akun) + kartu statistik di atas,
     menggantikan topbar polos + tab horizontal yang lama. Semua kelas
     LAMA (.tya-card, .tya-table, .tya-badge, .tya-field, dst) SENGAJA
     dipertahankan persis seperti sebelumnya -- supaya isi tiap halaman
     (form, tabel, flash message) tidak perlu ditulis ulang, cukup
     "bingkai" di sekelilingnya yang diganti. --}}
<style>
    :root {
        --tya-orange: #f97316;
        --tya-orange-dark: #ea580c;
        --tya-orange-soft: #fff7ed;
        --tya-bg: #f5f5f7;
        --tya-card: #ffffff;
        --tya-border: #e4e4e7;
        --tya-border-soft: #f0f0f1;
        --tya-text: #18181b;
        --tya-muted: #71717a;
        --tya-muted-soft: #a1a1aa;
        --tya-radius-lg: 0;
        --tya-radius-md: 0;
        --tya-radius-sm: 0;
        --tya-shadow: 0 1px 2px rgba(24,24,27,.04), 0 1px 14px rgba(24,24,27,.035);
        --tya-sidebar-w: 264px;
    }

    /* ===== Mode gelap -- toggle lewat tombol matahari/bulan di topbar,
       tersimpan di localStorage (lihat script di partials/topbar). Semua
       komponen di bawah pakai var(--tya-*), jadi cukup timpa token-nya
       di sini, otomatis ikut berubah semua tanpa perlu selector .dark
       satu-satu di tiap komponen. ===== */
    html[data-theme="dark"] {
        --tya-bg: #101014;
        --tya-card: #18181f;
        --tya-border: #2a2a33;
        --tya-border-soft: #232329;
        --tya-text: #f4f4f5;
        --tya-muted: #a1a1aa;
        --tya-muted-soft: #71717a;
        --tya-orange-soft: rgba(249,115,22,.12);
        --tya-shadow: 0 1px 2px rgba(0,0,0,.2), 0 1px 14px rgba(0,0,0,.25);
    }
    html[data-theme="dark"] table.tya-table th { background: #1c1c24; }
    html[data-theme="dark"] table.tya-table tbody tr:hover { background: #1c1c24; }
    html[data-theme="dark"] .tya-field input,
    html[data-theme="dark"] .tya-field select,
    html[data-theme="dark"] .tya-field textarea { background: #101014; border-color: #33333d; color: var(--tya-text); }
    html[data-theme="dark"] code { background: #232329; }
    html[data-theme="dark"] .tya-card.amber { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.3); }
    html[data-theme="dark"] .tya-flash.success { background: rgba(16,185,129,.1); border-color: rgba(16,185,129,.3); color: #6ee7b7; }
    html[data-theme="dark"] .tya-flash.warning { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.3); color: #fcd34d; }
    html[data-theme="dark"] .tya-flash.error { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.3); color: #fca5a5; }
    html[data-theme="dark"] .tya-flash.info { background: rgba(59,130,246,.1); border-color: rgba(59,130,246,.3); color: #93c5fd; }

    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
        font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        background: var(--tya-bg);
        color: var(--tya-text);
        margin: 0;
        -webkit-font-smoothing: antialiased;
        line-height: 1.4;
    }
    a { color: inherit; }

    /* ===== Shell: sidebar gelap tetap di kiri + area konten kanan ===== */
    .tya-app { display: flex; min-height: 100vh; }

    .tya-sidebar {
        width: var(--tya-sidebar-w); flex-shrink: 0;
        background: linear-gradient(180deg, #1a1a22, #0e0e13 70%);
        color: #e4e4e7;
        display: flex; flex-direction: column;
        position: sticky; top: 0; height: 100vh; overflow-y: auto;
        z-index: 40;
        transition: margin-left .2s ease;
    }
    .tya-sidebar-brand { display: flex; align-items: center; gap: .7rem; padding: 1.3rem 1.2rem 1rem; }
    .tya-sidebar-brand-badge {
        width: 2.5rem; height: 2.5rem; border-radius: 0; flex-shrink: 0;
        background: linear-gradient(135deg, var(--tya-orange), var(--tya-orange-dark));
        display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
        box-shadow: 0 4px 14px rgba(249,115,22,.4);
    }
    .tya-sidebar-brand-text { font-weight: 800; font-size: .95rem; line-height: 1.25; color: #fff; }
    .tya-sidebar-brand-text small { display: block; font-weight: 500; font-size: .67rem; color: #8b8b96; margin-top: .15rem; }

    .tya-nav { flex: 1; padding: .6rem .75rem; display: flex; flex-direction: column; gap: .15rem; }
    .tya-nav a, .tya-nav-toggle {
        display: flex; align-items: center; gap: .65rem; padding: .62rem .75rem; border-radius: 0;
        color: #c4c4cd; text-decoration: none; font-size: .82rem; font-weight: 600;
        background: none; border: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit;
        transition: background .12s ease, color .12s ease;
    }
    .tya-nav a:hover, .tya-nav-toggle:hover { background: rgba(255,255,255,.06); color: #fff; }
    .tya-nav a.active { background: var(--tya-orange); color: #fff; box-shadow: 0 4px 12px rgba(249,115,22,.35); }
    .tya-nav-icon { width: 1.15rem; text-align: center; flex-shrink: 0; font-size: .95rem; display: inline-flex; align-items: center; justify-content: center; }
    .tya-nav-icon svg { width: 1.05rem; height: 1.05rem; display: block; }
    .tya-sidebar-brand-badge svg { display: block; }
    .tya-nav-chevron { margin-left: auto; font-size: .65rem; opacity: .7; transition: transform .15s ease; }
    .tya-nav-group.open .tya-nav-chevron { transform: rotate(90deg); }
    .tya-nav-sub { display: none; flex-direction: column; padding-left: 2.15rem; gap: .05rem; margin: .1rem 0 .35rem; }
    .tya-nav-group.open .tya-nav-sub { display: flex; }
    .tya-nav-sub a { padding: .42rem .6rem; font-size: .77rem; font-weight: 500; color: #94949e; border-radius: 0; }
    .tya-nav-sub a:hover { color: #fff; background: rgba(255,255,255,.05); }
    .tya-nav-sub a.active { color: var(--tya-orange); font-weight: 700; }

    .tya-sidebar-foot { padding: 1rem 1.2rem 1.3rem; font-size: .72rem; color: #75757f; border-top: 1px solid rgba(255,255,255,.07); margin-top: auto; }
    .tya-sidebar-foot strong { display: block; color: #e4e4e7; font-size: .82rem; margin-bottom: .15rem; }

    /* ===== Area kanan: topbar + konten ===== */
    .tya-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

    .tya-topbar2 {
        position: sticky; top: 0; z-index: 30;
        display: flex; align-items: center; gap: .9rem;
        padding: .85rem 1.5rem;
        background: color-mix(in srgb, var(--tya-card) 92%, transparent);
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        border-bottom: 1px solid var(--tya-border);
    }
    .tya-hamburger {
        display: none; align-items: center; justify-content: center;
        width: 2.1rem; height: 2.1rem; border-radius: 0; border: 1px solid var(--tya-border);
        background: var(--tya-card); color: var(--tya-text); cursor: pointer; font-size: 1rem; flex-shrink: 0;
    }
    .tya-search { position: relative; flex: 1; max-width: 26rem; }
    .tya-search input {
        width: 100%; box-sizing: border-box; padding: .55rem .8rem .55rem 2.2rem; border-radius: 0;
        border: 1px solid var(--tya-border); background: var(--tya-bg); color: var(--tya-text);
        font-size: .82rem; font-family: inherit;
    }
    .tya-search input:focus { outline: none; border-color: var(--tya-orange); box-shadow: 0 0 0 3px rgba(249,115,22,.15); }
    .tya-search-icon { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--tya-muted-soft); font-size: .85rem; pointer-events: none; display: flex; }
    .tya-search-icon svg { width: 1rem; height: 1rem; }

    .tya-topbar-spacer { flex: 1; }

    .tya-online-pill {
        display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .8rem; border-radius: 0;
        background: #dcfce7; color: #15803d; font-size: .76rem; font-weight: 700; text-decoration: none; white-space: nowrap;
    }
    html[data-theme="dark"] .tya-online-pill { background: rgba(34,197,94,.15); color: #4ade80; }
    .tya-online-dot { width: .5rem; height: .5rem; border-radius: 0; background: #22c55e; flex-shrink: 0; }

    .tya-icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 2.15rem; height: 2.15rem; border-radius: 0; border: 1px solid var(--tya-border);
        background: var(--tya-card); color: var(--tya-text); cursor: pointer; font-size: 1rem; flex-shrink: 0;
    }
    .tya-icon-btn:hover { background: var(--tya-bg); }

    .tya-user-menu { position: relative; }
    .tya-user-btn {
        display: inline-flex; align-items: center; gap: .55rem; padding: .3rem .6rem .3rem .3rem; border-radius: 0;
        border: 1px solid var(--tya-border); background: var(--tya-card); cursor: pointer; font-family: inherit;
        font-size: .8rem; font-weight: 700; color: var(--tya-text);
    }
    .tya-user-avatar { width: 1.9rem; height: 1.9rem; border-radius: 0; background: #18181b; color: #fff; display: flex; align-items: center; justify-content: center; font-size: .8rem; flex-shrink: 0; }
    html[data-theme="dark"] .tya-user-avatar { background: #f4f4f5; color: #18181b; }
    .tya-user-dropdown {
        display: none; position: absolute; right: 0; top: calc(100% + .5rem); min-width: 11rem;
        background: var(--tya-card); border: 1px solid var(--tya-border); border-radius: 0;
        box-shadow: 0 12px 28px rgba(0,0,0,.14); padding: .4rem; z-index: 50;
    }
    .tya-user-menu.open .tya-user-dropdown { display: block; }
    .tya-user-dropdown button {
        display: flex; width: 100%; align-items: center; gap: .5rem; padding: .55rem .65rem; border-radius: 0;
        border: none; background: none; color: #dc2626; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer;
    }
    .tya-user-dropdown button:hover { background: #fef2f2; }

    /* ===== Konten ===== */
    .tya-content { padding: 1.5rem; max-width: 88rem; width: 100%; margin: 0 auto; box-sizing: border-box; }

    .tya-page-head { margin-bottom: 1.25rem; }
    .tya-page-head h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 .2rem; letter-spacing: -.01em; }
    .tya-page-head p { margin: 0; color: var(--tya-muted); font-size: .84rem; }

    /* ===== Kartu statistik ===== */
    .tya-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .tya-stat-card { background: var(--tya-card); border: 1px solid var(--tya-border); border-radius: 0; padding: 1.1rem 1.2rem; display: flex; align-items: flex-start; gap: .9rem; box-shadow: var(--tya-shadow); }
    .tya-stat-icon { width: 2.6rem; height: 2.6rem; border-radius: 0; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
    .tya-stat-icon.blue { background: #dbeafe; color: #1d4ed8; }
    .tya-stat-icon.orange { background: #ffedd5; color: #c2410c; }
    .tya-stat-icon.green { background: #dcfce7; color: #16a34a; }
    .tya-stat-icon.gray { background: #f4f4f5; color: #71717a; }
    html[data-theme="dark"] .tya-stat-icon.blue { background: rgba(59,130,246,.15); }
    html[data-theme="dark"] .tya-stat-icon.orange { background: rgba(249,115,22,.15); }
    html[data-theme="dark"] .tya-stat-icon.green { background: rgba(34,197,94,.15); }
    html[data-theme="dark"] .tya-stat-icon.gray { background: rgba(255,255,255,.08); }
    .tya-stat-label { font-size: .76rem; color: var(--tya-muted); font-weight: 600; margin: 0 0 .25rem; }
    .tya-stat-value { font-size: 1.55rem; font-weight: 800; line-height: 1.1; margin: 0; }
    .tya-stat-delta { font-size: .72rem; font-weight: 700; margin-top: .4rem; display: flex; align-items: center; gap: .25rem; }
    .tya-stat-delta.up { color: #16a34a; }
    .tya-stat-delta.muted { color: var(--tya-muted-soft); font-weight: 600; }

    /* ===== Bar tab (segmented) + tombol aksi utama ===== */
    .tya-tabbar {
        display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
        background: var(--tya-card); border: 1px solid var(--tya-border); border-radius: 0;
        padding: .6rem; margin-bottom: 1.25rem; box-shadow: var(--tya-shadow);
    }
    .tya-tabs {
        display: flex; gap: .35rem; flex: 1; min-width: 0;
        overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none;
    }
    .tya-tabs::-webkit-scrollbar { display: none; }
    .tya-tabs a {
        flex-shrink: 0; display: inline-flex; align-items: center; gap: .4rem;
        padding: .55rem 1.05rem; border-radius: 0; font-size: .8rem; font-weight: 700;
        text-decoration: none; color: var(--tya-muted); background: var(--tya-bg);
        border: 1px solid transparent; white-space: nowrap;
        transition: border-color .15s ease, color .15s ease, background .15s ease;
    }
    .tya-tabs a:hover { color: var(--tya-orange-dark); }
    .tya-tabs a.active { background: var(--tya-orange); color: #fff; box-shadow: 0 4px 10px rgba(249,115,22,.28); }

    /* ===== Buttons ===== */
    .tya-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
        padding: 0.62rem 1.1rem; border-radius: 0; border: 1px solid transparent;
        background: var(--tya-orange); color: #fff; font-weight: 600; font-size: 0.83rem;
        cursor: pointer; text-decoration: none; font-family: inherit;
        transition: background .15s ease, transform .08s ease, border-color .15s ease;
    }
    .tya-btn:hover { background: var(--tya-orange-dark); }
    .tya-btn:active { transform: scale(.98); }
    .tya-btn.gray { background: var(--tya-card); color: var(--tya-text); border-color: var(--tya-border); }
    .tya-btn.gray:hover { background: var(--tya-bg); }
    .tya-btn.danger { background: #dc2626; }
    .tya-btn.danger:hover { background: #b91c1c; }
    .tya-btn.danger.ghost { background: var(--tya-card); color: #dc2626; border-color: #fecaca; }
    .tya-btn.danger.ghost:hover { background: #fef2f2; }
    .tya-btn.ghost { background: transparent; color: var(--tya-orange-dark); border-color: var(--tya-border); }
    .tya-btn.ghost:hover { background: var(--tya-orange-soft); }
    .tya-btn.sm { padding: .4rem .75rem; font-size: .74rem; }
    .tya-btn.block { width: 100%; }

    /* ===== Cards ===== */
    .tya-card { background: var(--tya-card); border: 1px solid var(--tya-border); border-radius: 0; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--tya-shadow); }
    .tya-card.tint { background: var(--tya-orange-soft); border-color: #fed7aa; }
    html[data-theme="dark"] .tya-card.tint { border-color: rgba(249,115,22,.3); }
    .tya-card.amber { background: #fffbeb; border-color: #fde68a; }
    .tya-card-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
    .tya-card h2 { font-size: 0.95rem; font-weight: 700; margin: 0; letter-spacing: -.005em; }
    .tya-card-sub { font-size: .78rem; color: var(--tya-muted); margin: -0.55rem 0 1.1rem; line-height: 1.5; }

    /* ===== Forms ===== */
    .tya-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; }
    .tya-grid.span-2-last > *:last-child { grid-column: 1 / -1; }
    .tya-field label { display: block; font-size: 0.76rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--tya-muted); }
    .tya-field input, .tya-field select, .tya-field textarea {
        width: 100%; box-sizing: border-box; padding: 0.62rem 0.75rem; border-radius: 0;
        border: 1px solid #d4d4d8; font-size: 0.85rem; font-family: inherit; background: var(--tya-card); color: var(--tya-text);
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .tya-field input:focus, .tya-field select:focus, .tya-field textarea:focus {
        outline: none; border-color: var(--tya-orange); box-shadow: 0 0 0 3px rgba(249,115,22,.15);
    }
    .tya-hint { font-size: 0.75rem; color: var(--tya-muted); margin: 0.85rem 0 1.1rem; line-height: 1.55; }
    .tya-actions-end { display: flex; justify-content: flex-end; gap: .5rem; flex-wrap: wrap; margin-top: .25rem; }

    /* ===== Tables -> responsive card-rows on mobile ===== */
    .tya-table-wrap { overflow-x: auto; margin: 0 -0.25rem; }
    table.tya-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    table.tya-table th { text-align: left; padding: 0.65rem 0.85rem; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--tya-muted); background: #fafafa; border-bottom: 1px solid var(--tya-border); white-space: nowrap; }
    table.tya-table th:first-child { border-top-left-radius: 0; }
    table.tya-table th:last-child { border-top-right-radius: 0; }
    table.tya-table td { padding: 0.7rem 0.85rem; border-bottom: 1px solid var(--tya-border-soft); vertical-align: middle; }
    table.tya-table tr:last-child td { border-bottom: none; }
    table.tya-table tbody tr:hover { background: #fafafa; }
    .tya-table-empty { text-align: center; color: var(--tya-muted-soft); padding: 2.25rem 1rem; font-size: .85rem; }

    @media (max-width: 720px) {
        table.tya-table thead { display: none; }
        table.tya-table, table.tya-table tbody, table.tya-table tr, table.tya-table td { display: block; width: 100%; }
        table.tya-table tbody { display: flex; flex-direction: column; gap: .65rem; }
        table.tya-table tr { background: var(--tya-card); border: 1px solid var(--tya-border-soft); border-radius: 0; padding: .2rem 0; }
        table.tya-table td {
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            border-bottom: 1px solid var(--tya-border-soft); padding: .55rem .85rem; text-align: right;
        }
        table.tya-table td:last-child { border-bottom: none; }
        table.tya-table td::before {
            content: attr(data-label); font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .03em; color: var(--tya-muted); text-align: left; margin-right: auto; flex-shrink: 0;
        }
        table.tya-table td:not([data-label])::before,
        table.tya-table td[data-label=""]::before { content: none; }
        table.tya-table td:not([data-label]),
        table.tya-table td[data-label=""] { justify-content: center; }
        .tya-table-empty { padding: 1.75rem 1rem; }
    }

    /* ===== Badges ===== */
    .tya-badge { display: inline-flex; align-items: center; gap: .3rem; padding: 0.22rem 0.62rem; border-radius: 0; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
    .tya-badge.green { background: #dcfce7; color: #16a34a; }
    .tya-badge.amber { background: #fef3c7; color: #b45309; }
    .tya-badge.red { background: #fee2e2; color: #b91c1c; }
    .tya-badge.blue { background: #dbeafe; color: #1d4ed8; }
    .tya-badge.gray { background: #f4f4f5; color: #71717a; }
    html[data-theme="dark"] .tya-badge.green { background: rgba(34,197,94,.15); color: #4ade80; }
    html[data-theme="dark"] .tya-badge.amber { background: rgba(245,158,11,.15); color: #fbbf24; }
    html[data-theme="dark"] .tya-badge.red { background: rgba(239,68,68,.15); color: #f87171; }
    html[data-theme="dark"] .tya-badge.blue { background: rgba(59,130,246,.15); color: #93c5fd; }
    html[data-theme="dark"] .tya-badge.gray { background: rgba(255,255,255,.08); color: #d4d4d8; }

    /* ===== Flash messages ===== */
    .tya-flash { display: flex; gap: .6rem; align-items: flex-start; padding: 0.9rem 1.05rem; border-radius: 0; margin-bottom: 1.25rem; font-size: 0.84rem; line-height: 1.55; white-space: pre-line; }
    .tya-flash.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; }
    .tya-flash.warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
    .tya-flash.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .tya-flash.info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
    .tya-flash-icon { flex-shrink: 0; font-size: .95rem; line-height: 1; margin-top: .1rem; }

    /* ===== Misc ===== */
    .tya-usage-bar { background: #fed7aa; border-radius: 0; height: .55rem; overflow: hidden; margin-top: .5rem; }
    .tya-usage-bar-fill { background: linear-gradient(90deg, var(--tya-orange), var(--tya-orange-dark)); height: 100%; border-radius: 0; transition: width .4s ease; }
    .tya-inline-form { display: inline-flex; }
    code { background: #f4f4f5; padding: .1rem .35rem; border-radius: 0; font-size: .85em; }

    /* ===== Mobile: sidebar jadi drawer, dibuka lewat hamburger ===== */
    @media (max-width: 960px) {
        .tya-hamburger { display: inline-flex; }
        .tya-sidebar {
            position: fixed; left: 0; top: 0; margin-left: calc(var(--tya-sidebar-w) * -1);
            box-shadow: 0 0 0 100vmax rgba(0,0,0,0);
            transition: margin-left .2s ease, box-shadow .2s ease;
        }
        body.tya-sidebar-open .tya-sidebar { margin-left: 0; box-shadow: 0 0 0 100vmax rgba(0,0,0,.45); }
        .tya-search { max-width: none; }
    }

    @media (max-width: 640px) {
        .tya-content { padding: 1rem; }
        .tya-topbar2 { padding: .75rem 1rem; }
        .tya-card { padding: 1.1rem; border-radius: 0; margin-bottom: 1.1rem; }
        .tya-card h2 { font-size: .88rem; }
        .tya-actions-end { justify-content: stretch; }
        .tya-actions-end .tya-btn { flex: 1; }
        .tya-online-pill span.label { display: none; }
    }

    /* ===================================================================
       REDESAIN MOBILE (2026-10-16, sesuai mockup): header gelap berisi
       judul panel + chip online + hamburger, kolom cari di bawahnya,
       kartu statistik 2x2, dan kartu online per tenant.
       =================================================================== */
    .tya-mbrand { display: none; }
    .tya-hamburger svg { width: 1.1rem; height: 1.1rem; display: block; }
    .tya-side-m { display: none; }
    .tya-on-m { display: none; }

    @media (max-width: 720px) {
        .tya-on-desk { display: none; }
        .tya-on-m { display: block; }
    }

    @media (max-width: 640px) {
        /* --- Header gelap --- */
        .tya-topbar2 {
            position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: .6rem;
            padding: .85rem 1rem 0; background: #17171d; border-bottom: none;
            backdrop-filter: none; -webkit-backdrop-filter: none;
            border-radius: 0 0 18px 18px;
            box-shadow: 0 6px 18px rgba(0,0,0,.12);
        }
        .tya-mbrand {
            display: flex; align-items: center; gap: .7rem; flex: 1; min-width: 0;
            text-decoration: none; color: #fff; order: 1;
        }
        .tya-mbrand-badge {
            width: 2.55rem; height: 2.55rem; border-radius: 12px; flex-shrink: 0;
            background: linear-gradient(135deg, var(--tya-orange), var(--tya-orange-dark));
            display: flex; align-items: center; justify-content: center; color: #fff;
            box-shadow: 0 4px 14px rgba(249,115,22,.4);
        }
        .tya-mbrand-badge svg { width: 1.35rem; height: 1.35rem; }
        .tya-mbrand-text { font-weight: 800; font-size: .96rem; line-height: 1.2; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tya-mbrand-text small { display: block; font-weight: 500; font-size: .7rem; color: #a1a1aa; margin-top: .1rem; overflow: hidden; text-overflow: ellipsis; }

        .tya-topbar-spacer, #tya-theme-toggle, .tya-user-menu { display: none !important; }

        .tya-online-pill {
            order: 2; padding: .42rem .75rem; border-radius: 999px; font-size: .78rem;
            background: rgba(34,197,94,.14) !important; color: #4ade80 !important;
            border: 1px solid rgba(34,197,94,.35);
        }
        .tya-online-pill span.label { display: inline !important; }
        .tya-online-dot { border-radius: 50%; width: .55rem; height: .55rem; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }

        .tya-hamburger {
            order: 3; background: transparent; border: none; color: #fff;
            width: 2.2rem; height: 2.2rem; border-radius: 10px;
        }
        .tya-hamburger:active { background: rgba(255,255,255,.08); }
        .tya-hamburger svg { width: 1.45rem; height: 1.45rem; }

        /* --- Kolom cari (kartu putih di bawah header) --- */
        .tya-search {
            order: 4; flex: 1 1 100%; max-width: none;
            margin: .85rem -1rem 0; padding: .7rem 1rem;
            background: var(--tya-card); border-radius: 0 0 18px 18px;
        }
        .tya-search input {
            border: none; border-radius: 12px; background: var(--tya-bg);
            padding: .72rem .9rem .72rem 2.5rem; font-size: .86rem;
        }
        .tya-search-icon { left: 1.8rem; }

        /* --- Judul + kartu statistik 2x2 --- */
        .tya-page-head { margin: .3rem 0 .9rem; }
        .tya-page-head h1 { font-size: 1.6rem; }
        .tya-page-head p { font-size: .82rem; }
        .tya-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; margin-bottom: 1rem; }
        .tya-stat-card { padding: .85rem .8rem; gap: .6rem; border-radius: 14px; min-width: 0; }
        .tya-stat-icon { width: 2.4rem; height: 2.4rem; border-radius: 11px; font-size: 1.1rem; }
        .tya-stat-card > div:last-child { min-width: 0; }
        .tya-stat-label { font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tya-stat-value { font-size: 1.4rem; }
        .tya-stat-delta { font-size: .68rem; margin-top: .3rem; }

        .tya-card { border-radius: 16px; }
        .tya-flash { border-radius: 12px; }

        /* --- Laci menu: tombol tema & keluar --- */
        .tya-side-m { display: flex; flex-direction: column; gap: .35rem; padding: .6rem .75rem; border-top: 1px solid rgba(255,255,255,.07); }
        .tya-side-m form { margin: 0; }
        .tya-side-m button {
            display: flex; align-items: center; gap: .6rem; width: 100%; padding: .62rem .75rem;
            border: none; border-radius: 10px; background: rgba(255,255,255,.05); color: #e4e4e7;
            font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; text-align: left;
        }
        .tya-side-m button.keluar { color: #fca5a5; }
    }

    /* --- Kartu online per tenant (HP) --- */
    .tya-on-card { padding: 1rem; }
    .tya-on-head { display: flex; align-items: center; gap: .75rem; margin-bottom: .9rem; }
    .tya-on-led {
        width: 1.35rem; height: 1.35rem; border-radius: 50%; flex-shrink: 0;
        background: radial-gradient(circle at 35% 35%, #4ade80, #16a34a 70%);
        box-shadow: 0 0 0 4px rgba(34,197,94,.15);
    }
    .tya-on-title { flex: 1; min-width: 0; }
    .tya-on-title h2 { font-size: 1.05rem !important; font-weight: 800 !important; margin: 0; }
    .tya-on-title small { display: block; color: var(--tya-muted); font-size: .8rem; margin-top: .1rem; }
    .tya-on-refresh {
        width: 2.6rem; height: 2.6rem; border-radius: 12px; border: none; flex-shrink: 0;
        background: var(--tya-bg); color: var(--tya-muted); cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .tya-on-refresh svg { width: 1.2rem; height: 1.2rem; }
    .tya-on-refresh.putar svg { animation: tya-putar .8s linear infinite; }
    @keyframes tya-putar { to { transform: rotate(360deg); } }

    .tya-on-list { display: flex; flex-direction: column; gap: .65rem; }
    .tya-on-item {
        display: block; text-decoration: none; color: inherit;
        border: 1px solid var(--tya-border); border-radius: 14px; padding: .75rem;
        background: var(--tya-card); transition: border-color .15s ease;
    }
    .tya-on-item:active { border-color: var(--tya-orange); }
    .tya-on-top { display: flex; align-items: center; gap: .65rem; margin-bottom: .65rem; }
    .tya-on-bld {
        width: 2.5rem; height: 2.5rem; border-radius: 11px; flex-shrink: 0; color: #fff;
        background: linear-gradient(135deg, var(--tya-orange), var(--tya-orange-dark));
        display: flex; align-items: center; justify-content: center;
    }
    .tya-on-bld svg { width: 1.35rem; height: 1.35rem; }
    .tya-on-name { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .15rem; }
    .tya-on-name strong { font-size: .9rem; font-weight: 800; line-height: 1.25; overflow-wrap: anywhere; }
    .tya-on-name span { display: flex; align-items: center; gap: .3rem; font-size: .76rem; color: var(--tya-muted); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tya-on-name span svg { width: .9rem; height: .9rem; flex-shrink: 0; }
    .tya-on-chip {
        display: inline-flex; align-items: center; gap: .35rem; padding: .28rem .65rem; border-radius: 999px;
        font-size: .74rem; font-weight: 700; flex-shrink: 0;
    }
    .tya-on-chip i { width: .45rem; height: .45rem; border-radius: 50%; background: currentColor; }
    .tya-on-chip.green { background: #dcfce7; color: #16a34a; }
    .tya-on-chip.amber { background: #fef3c7; color: #b45309; }
    .tya-on-chev { color: var(--tya-muted); flex-shrink: 0; display: flex; }
    .tya-on-chev svg { width: 1.05rem; height: 1.05rem; }

    .tya-on-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .4rem; }
    .tya-on-tile {
        display: grid; grid-template-columns: minmax(0, 1fr); grid-template-areas: "ik" "val" "lbl";
        align-items: center; column-gap: .45rem; row-gap: .15rem; min-width: 0;
        background: #f6f7f9; border-radius: 10px; padding: .5rem .5rem .45rem;
    }
    @media (min-width: 500px) {
        .tya-on-tile { grid-template-columns: auto minmax(0, 1fr); grid-template-areas: "ik lbl" "ik val"; padding: .55rem .6rem; }
        .tya-on-tile .ik svg { width: 1.3rem !important; height: 1.3rem !important; }
    }
    .tya-on-tile .ik { grid-area: ik; flex-shrink: 0; display: flex; margin-bottom: .1rem; }
    .tya-on-tile .ik svg { width: 1.05rem; height: 1.05rem; }
    .tya-on-tile .ik.hotspot { color: #f97316; }
    .tya-on-tile .ik.pppoe { color: #7c3aed; }
    .tya-on-tile .ik.static { color: #2563eb; }
    .tya-on-tile .ik.total { color: #16a34a; }
    .tya-on-tile .tx { display: contents; }
    .tya-on-tile small { grid-area: lbl; font-size: .66rem; color: var(--tya-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tya-on-tile b { grid-area: val; font-size: 1.02rem; font-weight: 800; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .tya-on-sum { background: #fff7ed; border-color: #fdba74; }
    .tya-on-sum .tya-on-tile { background: rgba(255,255,255,.75); }
    .tya-on-sumic {
        width: 2.2rem; height: 2.2rem; border-radius: 10px; flex-shrink: 0;
        background: #ffedd5; color: var(--tya-orange); display: flex; align-items: center; justify-content: center;
    }
    .tya-on-sumic svg { width: 1.2rem; height: 1.2rem; }
    .tya-on-note { font-size: .72rem; color: var(--tya-muted); margin: .8rem 0 0; line-height: 1.5; }

    @media (max-width: 370px) {
        .tya-on-tiles { gap: .3rem; }
        .tya-on-tile { padding: .45rem .4rem .4rem; }
        .tya-on-tile b { font-size: .88rem; }
        .tya-mbrand-text small { display: none; }
    }

    html[data-theme="dark"] .tya-on-tile { background: #20202a; }
    html[data-theme="dark"] .tya-on-sum { background: rgba(249,115,22,.1); border-color: rgba(249,115,22,.45); }
    html[data-theme="dark"] .tya-on-sum .tya-on-tile { background: rgba(0,0,0,.25); }
    html[data-theme="dark"] .tya-on-sumic { background: rgba(249,115,22,.18); }
    html[data-theme="dark"] .tya-on-chip.green { background: rgba(34,197,94,.15); color: #4ade80; }
    html[data-theme="dark"] .tya-on-chip.amber { background: rgba(245,158,11,.15); color: #fbbf24; }
    html[data-theme="dark"] .tya-on-tile .ik.pppoe { color: #a78bfa; }
    html[data-theme="dark"] .tya-on-tile .ik.static { color: #60a5fa; }
    html[data-theme="dark"] .tya-on-tile .ik.total { color: #4ade80; }
    @media (max-width: 640px) {
        html[data-theme="dark"] .tya-topbar2 { background: #0b0b0f; box-shadow: 0 6px 18px rgba(0,0,0,.4); }
    }
</style>
