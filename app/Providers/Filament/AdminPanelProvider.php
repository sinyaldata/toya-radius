<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\GreetingWidget;
use App\Filament\Widgets\TenantBillingReminderWidget;
use App\Filament\Widgets\LoginActivityWidget;
use App\Filament\Widgets\SalesChartWidget;
use App\Filament\Widgets\OutletRevenueWidget;
use App\Filament\Widgets\InvoiceListWidget;
use App\Filament\Widgets\VoucherSalesListWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Orange,
                'gray' => Color::Zinc,
                // Dipakai kartu "Online Sekarang" di Dashboard.
                'ungu' => Color::Purple,
            ])
            ->brandName('Toya Radius')
            ->brandLogo(fn () => view('filament.branding.logo'))
            ->brandLogoHeight('2.6rem')
            // Lebar sidebar diperkecil dari default Filament (20rem) supaya
            // tidak banyak ruang kosong kiri-kanan di sekitar teks menu.
            // Dinaikkan sedikit dari 15rem -> 16.5rem supaya label grup
            // "PPPoE - DHCP" (paling panjang di sidebar) muat 1 baris,
            // tidak terpotong ke baris kedua.
            ->sidebarWidth('16.5rem')
            // Mode "icon rail": klik hamburger di topbar mengecilkan sidebar
            // jadi cuma ikon, arahkan kursor ke ikon untuk lihat sub-menu
            // flyout. Ini fitur BAWAAN Filament (bukan mekanisme custom lagi).
            ->sidebarCollapsibleOnDesktop()
            // favicon.ico DIHAPUS dari sini — ternyata file-nya 0 byte
            // (rusak/kosong), dan karena favicon() dipanggil 2x, yang
            // rusak ini yang menang menimpa favicon.svg yang valid.
            // Sekarang cukup satu, yang benar-benar ada isinya.
            ->favicon(asset('favicon.svg'))
            ->darkMode()
            ->themeSwitcher()
            // Daftarkan Dashboard secara EKSPLISIT (bukan cuma mengandalkan default bawaan
            // Filament) supaya pasti selalu tampil di navigasi paling atas, apapun kondisinya.
            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // TIDAK pakai ->discoverWidgets() dengan sengaja — kalau dipakai,
            // Filament otomatis menaruh SEMUA file widget di folder ini ke
            // Dashboard, padahal sebagian widget (statistik Hotspot User,
            // PPPoE User, dll) khusus untuk halaman lain, didaftarkan manual
            // lewat getHeaderWidgets() di masing-masing List page-nya.
            // Dashboard cukup pakai daftar eksplisit ->widgets() di bawah ini.
            // Widget: 4 kartu ringkasan di baris atas, lalu 3 widget
            // sejajar (Grafik Pendapatan, Performa Outlet, Log Aktivitas
            // Login) — semua muat dalam 1 layar tanpa scroll berlebihan.
            ->widgets([
                // Pengingat pembayaran langganan tenant-ke-owner --
                // CUMA tampil kalau sedang di dashboard salah satu
                // tenant DAN tenant itu sudah masuk H-10 jatuh tempo
                // (lihat TenantBillingReminderWidget::canView()). Tidak
                // pernah muncul di instalasi utama.
                TenantBillingReminderWidget::class,
                GreetingWidget::class,
                // Sisanya SENGAJA dikosongkan dulu — mulai dari titik
                // bersih yang pasti jalan, sebelum dibangun ulang
                // pelan-pelan satu per satu.
                DashboardStatsWidget::class,
                // Baris dua kolom (permintaan user 2026-09-18, GANTI
                // LAGI): Grafik Pendapatan (batang, Harian/Bulanan,
                // Voucher+Invoice terpisah) | 10 Outlet (donat, di
                // kanan). "Grafik Pengguna Online" (OnlineChartWidget)
                // DICABUT lagi dari sini -- widget itu lemot dimuat
                // (harus mengecek tumpang-tindih sesi login satu-satu),
                // dan user memilih Grafik Pendapatan sebagai gantinya
                // dari 3 pilihan yang ditawarkan. File OnlineChartWidget
                // & NasTrafficWidget masih ada, tidak dihapus, cuma
                // tidak lagi didaftarkan di Dashboard.
                SalesChartWidget::class,
                OutletRevenueWidget::class,
                LoginActivityWidget::class,
                InvoiceListWidget::class,
                VoucherSalesListWidget::class,
            ])
            // Jam berjalan di topbar, persis sebelum menu user (avatar) —
            // teks kecil, bukan widget kartu besar.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.topbar-clock'),
            )
            // Auto-reload diam-diam saat sesi/CSRF expired (419) alih-alih
            // menampilkan dialog "This page has expired" bawaan Livewire.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.livewire-419-handler'),
            )
            // Isi awal status buka/tutup grup sidebar SEBELUM Alpine sempat
            // membaca localStorage-nya sendiri -- lihat penjelasan panjang
            // di dalam view-nya (filament.sidebar-collapsed-seed) kenapa ini
            // WAJIB di HEAD_END, bukan BODY_END seperti hook-hook di bawah.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.sidebar-collapsed-seed'),
            )
            // Sidebar accordion: buka satu grup menu otomatis menutup grup lain.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.sidebar-accordion'),
            )
            // Toggle manual untuk sub-menu item BIASA yang punya anak lewat
            // $navigationParentItem (mis. "Profile" di grup Pelanggan) --
            // beda dari grup di atas, pola ini TIDAK ADA tombol buka/tutup
            // bawaan Filament sama sekali, lihat penjelasan di view-nya.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.sidebar-parentitem-toggle'),
            )
            // Sub-menu flyout (mode ikon) terbuka saat kursor didekatkan,
            // tidak perlu diklik dulu.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.sidebar-flyout-hover'),
            )
            // Logo ikon-saja (tanpa teks), tampil terpusat saat sidebar
            // di-collapse jadi mode ikon.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_LOGO_AFTER,
                fn () => view('filament.branding.logo-icon-only'),
            )
            // Kartu "Butuh Bantuan?" di bagian bawah sidebar.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn () => view('filament.sidebar-help'),
            )
            // CSS global tabel/filter/search/aksi — lewat Blade langsung
            // (BUKAN theme.css/Vite), supaya tidak perlu "npm run build"
            // lagi untuk perubahan ini. Cukup upload + view:clear.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.global-table-style'),
            )
            // Footer copyright kecil di bawah konten setiap halaman.
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn () => view('filament.footer'),
            )
            // FIX celah/strip hitam di bawah topbar -- lihat penjelasan
            // lengkap di dalam view-nya. Naruh di BODY_END (CSS+JS
            // via Blade langsung, BUKAN theme.css/Vite) supaya tidak
            // perlu "npm run build" lagi, cukup upload + view:clear.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.topbar-gap-fix'),
            )
            // Bottom navigation bar (tab bar) khusus mobile/tablet kecil
            // (<=1023px) -- Dashboard, Pelanggan, Generate Voucher,
            // Laporan, Setting. Disembunyikan total lewat CSS di layar
            // besar (lihat isi view-nya), jadi aman ditaruh di BODY_END
            // global tanpa mempengaruhi tampilan desktop.
            //
            // HANYA untuk yang SUDAH LOGIN. BODY_END ikut dirender di
            // halaman LOGIN juga (login memang bagian dari panel ini),
            // dan di situ bottom bar tidak ada gunanya: seluruh tautannya
            // menuju halaman yang dijaga auth, jadi ditekan pun cuma
            // dilempar balik ke login.
            //
            // Penjagaannya ditaruh DI SINI, bukan di dalam view, supaya
            // view-nya tidak ikut dirender sama sekali -- isi view itu
            // memanggil Resource::getUrl() yang butuh konteks pengguna.
            //
            // Memakai Filament::auth(), BUKAN auth() biasa: yang benar
            // adalah guard milik panel ini. Keduanya kebetulan sama
            // sekarang, tapi kalau suatu saat panel diberi guard sendiri,
            // auth() biasa akan memeriksa guard yang salah.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => \Filament\Facades\Filament::auth()->check()
                    ? view('filament.mobile-bottom-nav')
                    : '',
            )
            // Desain ulang SEMUA form (field, label, section, modal,
            // tombol, wizard, dropdown) supaya tidak lagi kelihatan
            // template Filament standar — minimalis & berkelas.
            //
            // WAJIB DIDAFTARKAN PALING AKHIR di antara render hook
            // BODY_END: file global-table-style di atas berisi blok
            // "reset ke default Filament" dengan !important pada
            // spesifisitas yang sama, jadi yang menang adalah yang
            // dirender BELAKANGAN. Kalau urutan ini ditukar, tampilan
            // form akan balik ke gaya lama.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.global-form-style'),
            )
            // Penyempurnaan global TERAKHIR: tombol filter full width di
            // HP, sudut kotak di semua elemen, checkbox minimalis, tabel
            // mengecil & mengikuti lebar isi di HP, form responsif.
            //
            // WAJIB PALING AKHIR dari semua render hook BODY_END --
            // aturannya sengaja dibuat menimpa global-table-style DAN
            // global-form-style pada spesifisitas yang sama, jadi yang
            // menentukan adalah urutan render.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.global-ui-polish'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString('<style>.fi-simple-page .fi-section, .fi-simple-page .fi-simple-layout, .fi-simple-page [class*="rounded"] { border-radius: 0 !important; }</style>'),
            )
            // Urutan array ini menentukan urutan tampil grup di sidebar dari atas ke bawah.
            // Dashboard TIDAK ikut array ini karena tidak punya grup (item tanpa grup
            // selalu ditaruh Filament paling atas secara otomatis, di atas semua grup).
            // Urutan final di sidebar: Dashboard, lalu urutan grup di bawah ini.
            //
            // ->collapsed() di setiap grup: supaya SEMUA grup tertutup by default
            // sebelum pernah diklik. Filament menyimpan status buka/tutup grup di
            // localStorage BROWSER (bukan per-user di server) — SEKALI localStorage
            // itu diisi (browser pertama kali buka sidebar), nilainya diambil dari
            // ->collapsed() masing-masing grup. Tanpa ->collapsed(), default-nya
            // "semua kebuka", dan begitu localStorage sudah terisi sekali, browser
            // itu akan terus pakai nilai lama itu (termasuk kombinasi buka/tutup
            // tidak konsisten dari sesi testing sebelumnya) sampai localStorage-nya
            // dikosongkan manual (clear site data) di browser itu.
            ->navigationGroups([
                NavigationGroup::make('Master Data')->icon('lucide-database')->collapsed(),
                NavigationGroup::make('Mitra')->icon('lucide-users-round')->collapsed(),
                // Grup menu biasa (permintaan user, ganti dari
                // percobaan hub/submenu bersarang yang kurang menarik)
                // -- ditaruh persis di bawah Mitra.
                NavigationGroup::make('Profile Paket')->icon('lucide-file-sliders')->collapsed(),
                NavigationGroup::make('Hotspot')->icon('lucide-wifi')->collapsed(),
                // "PPPoE - DHCP" diganti jadi "Pelanggan" (permintaan
                // user) -- isinya PPPoE, Static IP, & Member (dipindah
                // dari grup Hotspot, karena itu pelanggan langganan
                // bulanan, bukan penjualan voucher jalan), plus
                // Online/Offline/Isolir. Profile PPPoE/Static IP/Hotspot
                // TIDAK lagi di sini -- sudah pindah ke grup sendiri
                // "Profile Paket" di atas (percobaan submenu bersarang
                // & halaman hub sudah dibatalkan, permintaan user).
                NavigationGroup::make('Pelanggan')->icon('lucide-users-round')->collapsed(),
                // FTTH ditaruh SETELAH PPPoE dan sebelum Invoice: isinya
                // (peta, ODP, OLT, ONU) adalah sisi FISIK dari pelanggan
                // PPPoE yang ada di grup atasnya, jadi berdekatan.
                NavigationGroup::make('FTTH')->icon('lucide-network')->collapsed(),
                NavigationGroup::make('Invoice')->icon('lucide-file-text')->collapsed(),
                NavigationGroup::make('Keuangan')->icon('lucide-wallet')->collapsed(),
                NavigationGroup::make('Transaksi')->icon('lucide-banknote')->collapsed(),
                // Area/Wilayah DIPINDAH keluar dari grup Mitra ke sini
                // (permintaan user) -- ditaruh tepat di bawah Transaksi.
                NavigationGroup::make('Area / Wilayah')->icon('lucide-map-pin')->collapsed(),
                NavigationGroup::make('Laporan')->icon('lucide-bar-chart-3')->collapsed(),
                NavigationGroup::make('Pengaturan')->icon('lucide-settings')->collapsed(),
                NavigationGroup::make('Log')->icon('lucide-scroll-text')->collapsed(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // DINONAKTIFKAN SEMENTARA — sempat bikin akun Outlet
                // ke-403 padahal seharusnya boleh akses. Perlu diperbaiki
                // dulu sebelum diaktifkan lagi.
                // \App\Http\Middleware\EnsureAccessPermission::class,
            ]);
    }
}
