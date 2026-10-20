{{-- Sidebar navigasi VPN Owner Panel (gelap, tetap di kiri).
     Pakai: @include('vpn-owner.partials.sidebar', ['active' => 'index'])
     Nilai $active: index | plan | tenants | plan-catalog | online

     CATATAN SCOPE (permintaan user): referensi desain yang dikirim juga
     menampilkan menu "IP Tunnel" dan "Log Aktivitas" terpisah, serta
     "Pengaturan" -- tapi TIDAK ADA halaman/fitur itu di aplikasi ini.
     Supaya tidak ada tautan yang menuju ke halaman kosong/404, menu di
     bawah CUMA berisi halaman yang BENERAN ada: data IP Tunnel sudah
     kelihatan di tabel "Akun VPN", dan yang paling dekat dengan "Log
     Aktivitas" adalah halaman "Online Sekarang" yang sudah ada
     (vpn-owner.online) -- jadi dipakai/dilabeli apa adanya, bukan
     mengarang halaman baru. --}}
<div class="tya-sidebar" id="tya-sidebar">
    <div class="tya-sidebar-brand">
        <div class="tya-sidebar-brand-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
        </div>
        <div class="tya-sidebar-brand-text">
            VPN Owner Panel
            <small>Toya Radius &middot; Kontrol Pusat</small>
        </div>
    </div>

    <nav class="tya-nav" aria-label="Navigasi Owner Panel">
        <a href="{{ route('vpn-owner.index') }}" class="{{ ($active ?? '') === 'index' ? 'active' : '' }}">
            <span class="tya-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.25 12l8.954-8.955a1.125 1.125 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/></svg>
            </span> Dashboard
        </a>

        <a href="{{ route('vpn-owner.tenants') }}" class="{{ ($active ?? '') === 'tenants' ? 'active' : '' }}">
            <span class="tya-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
            </span> Tenants
        </a>

        <div class="tya-nav-group {{ ($active ?? '') === 'index' ? 'open' : '' }}">
            <button type="button" class="tya-nav-toggle" onclick="this.parentElement.classList.toggle('open')">
                <span class="tya-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                </span> Akun VPN
                <span class="tya-nav-chevron">▶</span>
            </button>
            <div class="tya-nav-sub">
                <a href="{{ route('vpn-owner.index') }}#daftar-akun">Daftar Akun</a>
                <a href="{{ route('vpn-owner.index') }}#buat-akun">Buat Akun</a>
                <a href="{{ route('vpn-owner.index') }}#generate-massal">Generate Massal</a>
            </div>
        </div>

        <a href="{{ route('vpn-owner.plan') }}" class="{{ ($active ?? '') === 'plan' ? 'active' : '' }}">
            <span class="tya-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </span> Paket Berlangganan
        </a>

        <a href="{{ route('vpn-owner.plan-catalog') }}" class="{{ ($active ?? '') === 'plan-catalog' ? 'active' : '' }}">
            <span class="tya-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.25 12.75V12a2.25 2.25 0 012.25-2.25h3.879a1.5 1.5 0 011.06.44l1.122 1.12a1.5 1.5 0 001.06.44h6.379a2.25 2.25 0 012.25 2.25v6a2.25 2.25 0 01-2.25 2.25H4.5A2.25 2.25 0 012.25 18.75v-6z"/></svg>
            </span> Katalog Paket
        </a>

        <a href="{{ route('vpn-owner.online') }}" class="{{ ($active ?? '') === 'online' ? 'active' : '' }}">
            <span class="tya-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.807 9.98-3.807 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12 18.75h.008v.008H12v-.008z"/></svg>
            </span> Online Sekarang
        </a>
    </nav>

    {{-- Khusus HP: tombol tema & keluar (di desktop ada di topbar). --}}
    <div class="tya-side-m">
        <button type="button" data-tya-theme-toggle><span data-tya-theme-icon>☀️</span> Ganti tema terang/gelap</button>
        <form method="POST" action="{{ route('vpn-owner.logout') }}">
            @csrf
            <button type="submit" class="keluar">🚪 Keluar</button>
        </form>
    </div>

    <div class="tya-sidebar-foot">
        <strong>VPN Owner Panel</strong>
        Kelola semua tenant dalam satu tempat
    </div>
</div>

