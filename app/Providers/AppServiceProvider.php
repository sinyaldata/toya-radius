<?php

namespace App\Providers;

use App\Listeners\LogFailedLogin;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\LogSuccessfulLogout;
use App\Models\CashierDeposit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Nas;
use App\Models\Voucher;
use App\Observers\CashierDepositObserver;
use App\Observers\NasObserver;
use App\Observers\TransactionObserver;
use App\Observers\VoucherObserver;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // PALING PENTING, PALING ATAS — alihkan koneksi database ke
        // tenant yang benar SEBELUM apapun lain jalan. Ditaruh di sini
        // (bukan cuma di middleware IdentifyTenant) karena terbukti ada
        // request (khususnya /admin, rute dashboard Filament) yang entah
        // kenapa tidak melewati middleware biasa sama sekali — provider
        // boot() ini jalan lebih awal di siklus request, jadi harus
        // menangkap SEMUA request, apapun jalurnya.
        $this->switchToTenantDatabaseIfNeeded();

        Voucher::observe(VoucherObserver::class);
        Nas::observe(NasObserver::class);
        Transaction::observe(TransactionObserver::class);

        // Setoran kasir -> beri tahu admin lewat Telegram bahwa outlet
        // tersebut sudah bayar (lihat CashierDepositObserver).
        CashierDeposit::observe(CashierDepositObserver::class);

        // Tombol aksi form (Simpan/Batal dkk) di halaman PENUH (bukan modal —
        // mis. halaman Edit NAS, Edit Router, Settings) dibuat MENEMPEL di
        // bawah layar, tidak ikut ke-scroll. Fitur bawaan Filament, cukup
        // di-aktifkan sekali di sini, otomatis berlaku ke SEMUA halaman
        // (BasePage adalah induk dari semua Page, termasuk resource
        // Create/Edit dan custom Page seperti Pengaturan).
        \Filament\Pages\BasePage::stickyFormActions();

        // Terapkan Zona Waktu Server yang diatur user lewat menu Pengaturan >
        // Perusahaan ke SELURUH aplikasi (jam topbar, timestamp voucher,
        // laporan, dll) — sebelumnya cuma tersimpan di DB tanpa efek nyata
        // karena config('app.timezone') Laravel tidak otomatis membacanya.
        // Dibungkus try/catch + cek tabel ada, supaya tidak mengganggu
        // `php artisan migrate` pertama kali (tabel settings belum ada).
        try {
            if (Schema::hasTable('settings')) {
                $timezone = Setting::get('timezone', config('app.timezone', 'Asia/Jakarta'));

                if ($timezone) {
                    config(['app.timezone' => $timezone]);
                    date_default_timezone_set($timezone);
                }
            }
        } catch (\Throwable) {
            // Diamkan — kalau database belum siap (mis. saat migrate awal),
            // biarkan pakai default config app.timezone dari .env.
        }

        // Kalau aplikasi diakses lewat reverse-proxy path-based (mis. /toya-radius/),
        // paksa semua url()/asset()/route() generate dengan prefix APP_URL dari .env,
        // bukan mengikuti path request internal yang sudah di-strip proxy.
        //
        // CATATAN (multi-tenant): TIDAK perlu paksa apapun buat request
        // web biasa — Laravel otomatis pakai domain request yang benar
        // (subdomain tenant maupun domain utama) tanpa bantuan apapun.
        // Baris ini cuma fallback buat konteks TANPA request sama sekali
        // (console/queue/scheduler).
        if (config('app.url') && ! app()->bound('request')) {
            URL::forceRootUrl(config('app.url'));
        }

        // Custom theme CSS (putih/oranye/hitam) — di-copy ke public/ lewat
        // `php artisan filament:assets`, TIDAK butuh npm/Vite/Node.js.
        FilamentAsset::register([
            Css::make('toya-theme', resource_path('css/filament/admin/theme.css')),
        ]);

        // Log aktivitas login/logout pengguna panel (Super Admin, Admin, Outlet)
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Failed::class, LogFailedLogin::class);
        Event::listen(Logout::class, LogSuccessfulLogout::class);

        // ============================================================
        // TOMBOL WIZARD (Kembali/Lanjut) — berlaku di SEMUA form wizard
        // sekaligus: Member Hotspot, User PPPoE, Profile Hotspot &
        // PPPoE, NAS, Outlet, Reseller, dan Biller.
        //
        // Dipasang lewat Wizard::configureUsing() supaya cukup ditulis
        // SEKALI di sini. Kalau ditulis per resource, setiap wizard baru
        // nanti harus diingat-ingat lagi untuk dikasih ikon.
        // ============================================================
        \Filament\Schemas\Components\Wizard::configureUsing(function (\Filament\Schemas\Components\Wizard $wizard): void {
            $wizard
                ->nextAction(
                    fn (\Filament\Actions\Action $action) => $action
                        ->label('Lanjut')
                        ->icon('lucide-arrow-right')
                        // Ikon di KANAN teks — arah panah jadi searah
                        // dengan arti tombolnya (maju ke langkah
                        // berikutnya).
                        ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                )
                ->previousAction(
                    fn (\Filament\Actions\Action $action) => $action
                        ->label('Kembali')
                        ->icon('lucide-arrow-left')
                        ->color('gray')
                );
        });

        // Berlaku untuk SEMUA tabel di seluruh aplikasi (bukan cuma satu
        // resource): kolom aksi (Edit/Hapus dkk) dikasih judul "Aksi", DAN
        // ikon tombol filter diganti yang lebih elegan (bukan funnel bawaan).
        Table::configureUsing(function (Table $table): void {
            $table->recordActionsColumnLabel('Aksi');

            // Tombol filter: dari ikon polos jadi TOMBOL BERLABEL
            // "Filter", dan posisinya dibiarkan di tempat bawaan
            // Filament -- yaitu di baris toolbar, tepat di sebelah
            // kotak Search (memang sudah pas di situ, tidak perlu
            // digeser-geser lewat CSS).
            //
            // GANTI TAMPILAN PANEL FILTER DI SELURUH APLIKASI (permintaan
            // user, dikirim contoh gambar): dari dropdown kecil yang
            // melayang, jadi PANEL YANG MUNCUL DI BAWAH TOMBOL "Tampilkan
            // Filter" -- berisi kolom-kolom filter dalam bentuk grid rapi,
            // ditutup baris tombol "Reset" + "Terapkan Filter".
            //
            // Diatur SEKALI di sini (bukan per-resource) supaya otomatis
            // berlaku ke SEMUA tabel di seluruh aplikasi ("disemua
            // tempat" sesuai permintaan). TIDAK ADA data/struktur filter
            // yang berubah -- tiap resource tetap mendefinisikan filternya
            // sendiri-sendiri seperti sebelumnya; yang berubah cuma CARA
            // TAMPILNYA (layout + CSS di global-table-style.blade.php).
            $table->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible);

            // Tombol "Reset" & "Terapkan Filter" digabung jadi SATU baris
            // di bagian BAWAH panel (bukan "Reset" kecil di header atas),
            // persis seperti contoh gambar.
            $table->filtersResetActionPosition(\Filament\Tables\Enums\FiltersResetActionPosition::Footer);

            // Filament v4 sudah otomatis menahan filter sampai user pencet
            // "Terapkan Filter" (tidak langsung nyaring tiap ganti nilai) --
            // ini teks tombolnya (Indonesia, bawaan Filament) + ikon kaca
            // pembesar biar sama seperti contoh.
            $table->filtersApplyAction(
                fn (\Filament\Actions\Action $action) => $action
                    ->icon('lucide-search')
                    ->color('primary')
            );

            // Tombol pemicu panel filter (yang di-klik untuk buka/tutup) --
            // label "Tampilkan Filter" sesuai contoh, ikon funnel, gaya
            // outline supaya tidak terlalu mencolok dibanding tombol aksi
            // lain (Excel/CSV/Generate dkk) di toolbar yang sama.
            // Ikon diganti (permintaan user, "icon filter diganti yang
            // lebih bagus") -- dari "lucide-list-filter" (baris horizontal
            // polos) ke "lucide-sliders-horizontal" (3 slider dgn titik
            // kenop), lebih umum dikenali sebagai simbol "filter/pengaturan
            // tampilan" di aplikasi mobile modern.
            $table->filtersTriggerAction(
                fn (\Filament\Actions\Action $action) => $action
                    ->icon('lucide-sliders-horizontal')
                    ->label('Tampilkan Filter')
                    ->color('primary')
                    ->button()
                    ->outlined()
            );

            // Tombol "Kolom Manager" (ikon kolom di sebelah Search, untuk
            // sembunyi/tampilkan kolom tabel) DIMATIKAN TOTAL di SELURUH
            // aplikasi sesuai permintaan -- bukan cuma disembunyikan lewat
            // CSS, tapi memang tidak dirender sama sekali oleh Filament.
            // Posisi bekasnya (ujung kanan baris Search) sekarang dipakai
            // tombol "Tampilkan Filter" (diatur lewat CSS Grid di
            // global-table-style.blade.php).
            $table->columnManager(false);
        });

        // Ikon Hapus (DeleteAction/DeleteBulkAction) & Edit diseragamkan —
        // SEMUA oranye (identitas aplikasi ini), sesuai permintaan terbaru.
        \Filament\Actions\DeleteAction::configureUsing(
            fn (\Filament\Actions\DeleteAction $action) => $action->icon('lucide-trash-2')->color('primary')
        );
        \Filament\Actions\DeleteBulkAction::configureUsing(
            fn (\Filament\Actions\DeleteBulkAction $action) => $action->icon('lucide-trash-2')->color('primary')
        );

        \Filament\Actions\EditAction::configureUsing(
            fn (\Filament\Actions\EditAction $action) => $action->icon('lucide-pencil')->color('primary')
        );

        // Ikon default untuk tombol yang SELAMA INI polos tanpa ikon di
        // Filament v4 (beda dari EditAction/DeleteAction/ViewAction yang
        // sudah otomatis ada ikonnya bawaan framework). Diatur SEKALI di
        // sini supaya berlaku ke SEMUA resource — tombol "Tambah ..." di
        // seluruh aplikasi otomatis dapat ikon, tidak perlu edit satu-satu.
        // Kalau suatu action sudah di-set ->icon() manual di kode resource,
        // itu yang menang (default ini cuma dipakai kalau belum diisi).
        \Filament\Actions\CreateAction::configureUsing(function (\Filament\Actions\CreateAction $action): void {
            $action->icon('lucide-plus');

            // Tombol "Create & create another" DIHAPUS di semua form Create
            // (di seluruh aplikasi), sisa 2 tombol saja: submit & cancel,
            // dengan label Indonesia.
            $action
                ->createAnother(false)
                ->modalSubmitActionLabel('Simpan')
                ->modalCancelActionLabel('Batal');
        });
        \Filament\Actions\Action::configureUsing(function (\Filament\Actions\Action $action): void {
            // Tombol submit/simpan bawaan Filament pakai nama 'save' atau 'create'.
            if (in_array($action->getName(), ['save', 'create', 'submit'], true) && ! $action->getIcon()) {
                $action->icon('lucide-check');
            }

            // Tombol navigasi Wizard (Data Secret > Data Pelanggan > dst) —
            // "Next" panah kanan, "Back/Previous" panah kiri, biar konsisten
            // dengan referensi tampilan yang diminta.
            if ($action->getName() === 'next' && ! $action->getIcon()) {
                $action->icon('lucide-arrow-right')->iconPosition(\Filament\Support\Enums\IconPosition::After);
            }

            if ($action->getName() === 'previous' && ! $action->getIcon()) {
                $action->icon('lucide-arrow-left');
            }
        });
    }

    /**
     * Baca subdomain dari request SEKARANG (kalau ada), alihkan koneksi
     * 'mysql' ke database tenant yang sesuai. Dipindah dari middleware
     * ke sini karena terbukti lebih pasti jalan buat SEMUA request.
     */
    protected function switchToTenantDatabaseIfNeeded(): void
    {
        if (! $this->app->bound('request') || $this->app->runningInConsole()) {
            return;
        }

        $request = $this->app->make(\Illuminate\Http\Request::class);
        $host = $request->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        if (in_array($host, $centralDomains, true)) {
            return;
        }

        $subdomain = explode('.', $host)[0] ?? null;

        if (! $subdomain) {
            return;
        }

        \Illuminate\Support\Facades\DB::purge('central');

        $tenant = \App\Models\Central\Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant || ! $tenant->isActive()) {
            abort($tenant ? 403 : 404, $tenant ? 'Langganan tenant ini sedang tidak aktif.' : 'Tenant tidak ditemukan.');
        }

        Config::set('database.connections.mysql.database', $tenant->database_name);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');

        $this->app->instance('currentTenant', $tenant);

        try {
            $this->app->make('auth')->forgetGuards();
        } catch (\Throwable $e) {
            //
        }
    }
}
