<?php

use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\OwnerOnlineController;
use App\Http\Controllers\OwnerPlanCatalogController;
use App\Http\Controllers\OwnerPlanController;
use App\Http\Controllers\OwnerTenantController;
use App\Http\Controllers\ReportPrintController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TenantRegistrationController;
use App\Http\Controllers\VoucherPrintController;
use App\Http\Controllers\VoucherPurchaseController;
use App\Http\Controllers\VpnOwnerController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\IsolirLandingController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Pendaftaran mandiri PUBLIK (tanpa login) — calon pelanggan ISP daftar
// sendiri di sini. Status "pending" dulu, owner yang approve dari
// /vpn-owner sebelum database tenant beneran dibuat.
Route::get('/daftar', [TenantRegistrationController::class, 'create'])->name('tenant-registration.create');
Route::post('/daftar', [TenantRegistrationController::class, 'store'])->name('tenant-registration.store');
Route::get('/daftar/berhasil', [TenantRegistrationController::class, 'success'])->name('tenant-registration.success');

// Halaman VPN khusus OWNER — SENGAJA di luar panel Filament/Toya Radius
// sepenuhnya (bukan Livewire, bukan lewat guard 'web' punya Filament).
// Login-nya cuma satu password (VPN_OWNER_PASSWORD di .env), terpisah
// dari akun admin/staff manapun.
Route::prefix('vpn-owner')->group(function () {
    Route::get('/login', [VpnOwnerController::class, 'showLogin'])->name('vpn-owner.login');
    Route::post('/login', [VpnOwnerController::class, 'login'])->name('vpn-owner.login.submit');

    Route::middleware('vpn.owner')->group(function () {
        Route::get('/', [VpnOwnerController::class, 'index'])->name('vpn-owner.index');
        Route::post('/accounts', [VpnOwnerController::class, 'store'])->name('vpn-owner.store');
        Route::post('/accounts/bulk', [VpnOwnerController::class, 'storeBulk'])->name('vpn-owner.store-bulk');
        Route::delete('/accounts/{account}', [VpnOwnerController::class, 'destroy'])->name('vpn-owner.destroy');
        Route::post('/logout', [VpnOwnerController::class, 'logout'])->name('vpn-owner.logout');

        // Paket berlangganan Toya Radius ITU SENDIRI (bukan paket
        // Hotspot/PPPoE tenant) — sengaja SATU LOGIN dengan VPN, supaya
        // owner cukup ingat 1 password buat semua yang "punya owner".
        Route::get('/paket', [OwnerPlanController::class, 'index'])->name('vpn-owner.plan');
        Route::post('/paket', [OwnerPlanController::class, 'store'])->name('vpn-owner.plan.store');

        // Kelola tenant (multi-tenant) — bikin ISP pelanggan baru,
        // database + SuperAdmin pertama otomatis kebuat.
        Route::get('/tenants', [OwnerTenantController::class, 'index'])->name('vpn-owner.tenants');
        Route::post('/tenants', [OwnerTenantController::class, 'store'])->name('vpn-owner.tenants.store');
        Route::post('/tenants/{tenant}/approve', [OwnerTenantController::class, 'approve'])->name('vpn-owner.tenants.approve');
        Route::delete('/tenants/{tenant}/reject', [OwnerTenantController::class, 'reject'])->name('vpn-owner.tenants.reject');
        // Update paket & jadwal jatuh tempo tenant yang SUDAH ada --
        // dipakai tiap kali tenant bayar (geser jatuh tempo ke bulan
        // berikutnya) tanpa perlu hapus-buat-ulang tenant-nya.
        Route::post('/tenants/{tenant}/billing', [OwnerTenantController::class, 'updateBilling'])->name('vpn-owner.tenants.billing');
        Route::delete('/tenants/{tenant}', [OwnerTenantController::class, 'destroy'])->name('vpn-owner.tenants.destroy');

        // Katalog paket buat halaman pendaftaran mandiri publik (/daftar).
        Route::get('/katalog-paket', [OwnerPlanCatalogController::class, 'index'])->name('vpn-owner.plan-catalog');
        Route::post('/katalog-paket', [OwnerPlanCatalogController::class, 'store'])->name('vpn-owner.plan-catalog.store');
        Route::post('/katalog-paket/{plan}/toggle', [OwnerPlanCatalogController::class, 'toggle'])->name('vpn-owner.plan-catalog.toggle');
        Route::delete('/katalog-paket/{plan}', [OwnerPlanCatalogController::class, 'destroy'])->name('vpn-owner.plan-catalog.destroy');

        // Ringkasan jumlah online lintas-tenant — cuma laporan, tidak
        // mengubah apapun (batas/limit tetap diatur manual di tempat lain).
        Route::get('/online', [OwnerOnlineController::class, 'index'])->name('vpn-owner.online');
    });
});

// Halaman cetak voucher (dibuka dari panel admin lewat tombol "Cetak Voucher").
// Pakai middleware 'auth' bawaan Laravel — sesi login yang sama dengan panel
// Filament (guard default 'web'), jadi cukup login sekali di /admin.
//
// Alurnya 2 langkah: pilih-template dulu (6 pilihan desain), baru cetak sungguhan.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/vouchers/print-templates', [VoucherPrintController::class, 'chooseTemplate'])
        ->name('vouchers.print-templates');

    Route::get('/vouchers/print', [VoucherPrintController::class, 'show'])
        ->name('vouchers.print');

    Route::get('/laporan/penjualan/print', [ReportPrintController::class, 'sales'])
        ->name('laporan.penjualan.print');

    Route::get('/laporan/outlet/print', [ReportPrintController::class, 'outlets'])
        ->name('laporan.outlet.print');

    // Cetak Invoice — 2 pilihan format (dibuka dari tombol "Cetak" di
    // panel admin, buka tab baru).
    Route::get('/invoice/{invoice}/print/regular', [InvoicePrintController::class, 'regular'])
        ->name('invoice.print.regular');

    Route::get('/invoice/{invoice}/print/thermal', [InvoicePrintController::class, 'thermal'])
        ->name('invoice.print.thermal');
});

// Beli Voucher MANDIRI lewat QRIS — publik, TANPA login. Dibuka
// pelanggan lewat link/QR di halaman login hotspot, pakai kuota
// seluler mereka sendiri (bukan lewat hotspot, karena mereka belum
// login). {subdomain} = "utama" untuk instalasi utama, atau kolom
// tenants.subdomain untuk tenant lain — lihat
// App\Http\Controllers\Concerns\ResolvesTenantBySubdomain.
// Route::get('/beli/{subdomain}/{kodeOutlet}', [VoucherPurchaseController::class, 'pilihPaket'])
//     ->name('voucher-purchase.pilih');

// Route::post('/beli/{subdomain}/{kodeOutlet}', [VoucherPurchaseController::class, 'buatPesanan'])
//     ->name('voucher-purchase.buat');

// Route::get('/beli/status/{subdomain}/{orderNo}', [VoucherPurchaseController::class, 'status'])
//     ->name('voucher-purchase.status');

// Route::get('/beli/status/{subdomain}/{orderNo}/cek', [VoucherPurchaseController::class, 'cek'])
//     ->name('voucher-purchase.cek');

// Webhook Xendit (notifikasi QRIS lunas) — bukan dari browser, jadi
// TANPA proteksi CSRF, seperti webhook Telegram di bawah. Keamanannya
// dari token verifikasi Xendit sendiri (header x-callback-token),
// dicek di dalam XenditWebhookController::handle().
// Route::post('/webhook/xendit/qris/{subdomain}/{orderNo}', [XenditWebhookController::class, 'handle'])
//     ->withoutMiddleware([VerifyCsrfToken::class])
//     ->name('xendit.webhook.qris');

// Webhook Telegram Bot — DINONAKTIFKAN SEMENTARA (setup domain/HTTPS untuk
// Telegram masih ditunda). Aktifkan lagi dengan menghapus komentar di bawah
// begitu domain+SSL sudah siap, dan pastikan file TelegramWebhookController
// sudah ter-deploy dengan benar di server.
//
// use App\Http\Controllers\TelegramWebhookController;
// use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
//
Route::post('/telegram/webhook/{subdomain?}', TelegramWebhookController::class)
->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
->name('telegram.webhook');

// Halaman "walled garden" isolir -- PUBLIK, tanpa login.
if ($isolirDomain = config('services.isolir.domain')) {
    Route::domain($isolirDomain)->group(function () {
        Route::get('/{any?}', [IsolirLandingController::class, 'show'])->where('any', '.*');
    });
}

Route::get('/isolir', [IsolirLandingController::class, 'show'])->name('isolir.show');

// APLIKASI-HP (tools/pasang-aplikasi-hp.py)
require __DIR__.'/aplikasi-hp.php';
