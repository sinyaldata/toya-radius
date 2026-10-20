{{-- Topbar (cari/online/tema/akun) + kartu statistik + bar tab, dipakai
     bareng sidebar (lihat partials/sidebar.blade.php).
     Pakai: @include('vpn-owner.partials.topbar', ['active' => 'index'])
     Nilai $active: index | plan | tenants | plan-catalog | online --}}
@php
    // Statistik ringkas -- dihitung LANGSUNG dari database di sini
    // (bukan angka contoh), supaya kartu di atas tiap halaman selalu
    // menunjukkan kondisi terkini, sama seperti data yang sudah ada di
    // halaman Tenant & Akun VPN masing-masing.
    $tyaTotalTenants = \App\Models\Central\Tenant::count();
    $tyaTenantBulanIni = \App\Models\Central\Tenant::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();

    $tyaTotalAkun = \App\Models\VpnAccount::count();
    $tyaAkunBulanIni = \App\Models\VpnAccount::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
    $tyaAkunAktif = \App\Models\VpnAccount::where('is_active', true)->count();
    $tyaAkunNonaktif = $tyaTotalAkun - $tyaAkunAktif;
    $tyaPersenAktif = $tyaTotalAkun > 0 ? round($tyaAkunAktif / $tyaTotalAkun * 100, 1) : 0;
    $tyaPersenNonaktif = $tyaTotalAkun > 0 ? round($tyaAkunNonaktif / $tyaTotalAkun * 100, 1) : 0;

    // Total user online SEKARANG lintas semua tenant + instalasi utama
    // -- query yang sama seperti OwnerOnlineController, cuma dijumlah
    // total (bukan dipecah per tenant) buat badge kecil di topbar ini.
    $tyaTotalOnline = \Illuminate\Support\Facades\DB::connection('central')
        ->table('radacct')
        ->whereNull('acctstoptime')
        ->distinct()
        ->count('username');
@endphp

<div class="tya-topbar2">
    {{-- Judul panel versi HP (desktop judulnya ada di sidebar). --}}
    <a href="{{ route('vpn-owner.index') }}" class="tya-mbrand">
        <span class="tya-mbrand-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
        </span>
        <span class="tya-mbrand-text">VPN Owner Panel<small>Toya Radius &middot; Kontrol Pusat</small></span>
    </a>

    <button type="button" class="tya-hamburger" aria-label="Buka menu" onclick="document.body.classList.toggle('tya-sidebar-open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6.5h16M4 12h16M4 17.5h16"/></svg>
    </button>

    <div class="tya-search">
        <span class="tya-search-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg></span>
        <input type="text" id="tya-search-input" placeholder="Cari tenant, username, atau IP tunnel..." autocomplete="off">
    </div>

    <div class="tya-topbar-spacer"></div>

    <a href="{{ route('vpn-owner.online') }}" class="tya-online-pill" title="Total user online sekarang, semua tenant">
        <span class="tya-online-dot"></span>
        <span class="num">{{ number_format($tyaTotalOnline, 0, ',', '.') }}</span>
        <span class="label">Online</span>
    </a>

    <button type="button" class="tya-icon-btn" id="tya-theme-toggle" aria-label="Ganti tema terang/gelap" title="Ganti tema">
        <span id="tya-theme-icon">☀️</span>
    </button>

    <div class="tya-user-menu" id="tya-user-menu">
        <button type="button" class="tya-user-btn" onclick="document.getElementById('tya-user-menu').classList.toggle('open')">
            <span class="tya-user-avatar">A</span>
            Administrator
            <span style="font-size:.65rem; opacity:.7;">▾</span>
        </button>
        <div class="tya-user-dropdown">
            <form method="POST" action="{{ route('vpn-owner.logout') }}">
                @csrf
                <button type="submit">🚪 Keluar</button>
            </form>
        </div>
    </div>
</div>

<div class="tya-content">
    <div class="tya-page-head">
        <h1>Dashboard</h1>
        <p>Ringkasan pengelolaan VPN Anda</p>
    </div>

    <div class="tya-stats-grid">
        <div class="tya-stat-card">
            <div class="tya-stat-icon blue">👥</div>
            <div>
                <p class="tya-stat-label">Total Tenants</p>
                <p class="tya-stat-value">{{ $tyaTotalTenants }}</p>
                @if ($tyaTenantBulanIni > 0)
                    <div class="tya-stat-delta up">↑ +{{ $tyaTenantBulanIni }} bulan ini</div>
                @else
                    <div class="tya-stat-delta muted">Tidak ada tenant baru bulan ini</div>
                @endif
            </div>
        </div>

        <div class="tya-stat-card">
            <div class="tya-stat-icon orange">🔑</div>
            <div>
                <p class="tya-stat-label">Total Akun VPN</p>
                <p class="tya-stat-value">{{ $tyaTotalAkun }}</p>
                @if ($tyaAkunBulanIni > 0)
                    <div class="tya-stat-delta up">↑ +{{ $tyaAkunBulanIni }} bulan ini</div>
                @else
                    <div class="tya-stat-delta muted">Tidak ada akun baru bulan ini</div>
                @endif
            </div>
        </div>

        <div class="tya-stat-card">
            <div class="tya-stat-icon green">📶</div>
            <div>
                <p class="tya-stat-label">Akun Aktif</p>
                <p class="tya-stat-value">{{ $tyaAkunAktif }}</p>
                <div class="tya-stat-delta muted">{{ $tyaPersenAktif }}% dari total</div>
            </div>
        </div>

        <div class="tya-stat-card">
            <div class="tya-stat-icon gray">⛔</div>
            <div>
                <p class="tya-stat-label">Akun Nonaktif</p>
                <p class="tya-stat-value">{{ $tyaAkunNonaktif }}</p>
                <div class="tya-stat-delta muted">{{ $tyaPersenNonaktif }}% dari total</div>
            </div>
        </div>
    </div>
