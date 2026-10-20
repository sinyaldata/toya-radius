<?php

namespace App\Http\Controllers;

use App\Models\Central\PlanCatalog;
use App\Models\Central\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;

/**
 * Kelola SEMUA tenant (pelanggan Toya Radius) — SENGAJA di panel owner
 * ini, satu login dengan halaman VPN & Paket Berlangganan owner.
 */
class OwnerTenantController extends Controller
{
    public function index()
    {
        $tenants = Tenant::orderByDesc('id')->get();
        $planCatalogs = PlanCatalog::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('vpn-owner.tenants', compact('tenants', 'planCatalogs'));
    }

    public function store(Request $request, TenantProvisioningService $service)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'subdomain' => 'required|string|max:40|alpha_dash|unique:App\Models\Central\Tenant,subdomain',
            'owner_name' => 'nullable|string|max:100',
            'owner_email' => 'required|email|max:120',
            'owner_phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            // Paket & jadwal jatuh tempo -- DUA-DUANYA opsional, supaya
            // tenant internal/gratis (tanpa tagihan) tetap bisa dibuat
            // tanpa terhalang wajib isi.
            'plan_catalog_id' => 'nullable|integer|exists:App\Models\Central\PlanCatalog,id',
            'billing_due_date' => 'nullable|date',
        ]);

        try {
            $tenant = $service->createTenant($data);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'subdomain' => 'Gagal membuat tenant: '.$e->getMessage(),
            ])->withInput();
        }

        $generatedPassword = $tenant->getAttribute('_generated_admin_password');
        $sslMessage = $tenant->getAttribute('_ssl_message');

        return redirect()->route('vpn-owner.tenants')->with([
            'success' => "Tenant \"{$tenant->name}\" berhasil dibuat! Subdomain: {$tenant->subdomain}.".(parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url'))."".
                ($generatedPassword ? " — Password SuperAdmin pertama: {$generatedPassword} (catat sekarang, tidak akan ditampilkan lagi)." : ''),
            'warning' => $sslMessage,
        ]);
    }

    /**
     * Setujui pendaftaran mandiri — INI yang benar-benar memicu
     * pembuatan database + migrasi + akun SuperAdmin pertama. Sebelum
     * ini, tenant cuma "niat daftar" (status pending), belum makan
     * resource server sama sekali.
     */
    public function approve(Tenant $tenant, TenantProvisioningService $service)
    {
        if (! $tenant->isPending()) {
            return back()->withErrors(['approve' => 'Tenant ini bukan pendaftaran yang menunggu persetujuan.']);
        }

        try {
            $tenant = $service->provision($tenant);
        } catch (\Throwable $e) {
            return back()->withErrors(['approve' => 'Gagal provisioning: '.$e->getMessage()]);
        }

        $generatedPassword = $tenant->getAttribute('_generated_admin_password');
        $sslMessage = $tenant->getAttribute('_ssl_message');

        return redirect()->route('vpn-owner.tenants')->with([
            'success' => "Tenant \"{$tenant->name}\" disetujui & aktif! Subdomain: {$tenant->subdomain}.".(parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url'))."".
                ($generatedPassword ? " — Password SuperAdmin: {$generatedPassword} (catat sekarang)." : ''),
            'warning' => $sslMessage,
        ]);
    }

    /**
     * Update paket & jadwal jatuh tempo tenant yang SUDAH aktif --
     * dipakai owner tiap kali tenant bayar (geser jatuh tempo ke bulan
     * berikutnya), tanpa menyentuh data lain milik tenant itu sama
     * sekali (bukan hapus-buat-ulang).
     */
    public function updateBilling(Tenant $tenant, Request $request)
    {
        $data = $request->validate([
            'plan_catalog_id' => 'nullable|integer|exists:App\Models\Central\PlanCatalog,id',
            'billing_due_date' => 'nullable|date',
        ]);

        $tenant->update($data);

        return redirect()->route('vpn-owner.tenants')->with('success', "Paket & jadwal jatuh tempo tenant \"{$tenant->name}\" diperbarui.");
    }

    /**
     * Tolak pendaftaran mandiri — hapus catatannya sama sekali (belum
     * ada database yang perlu dibersihkan, karena provisioning belum
     * pernah jalan buat tenant berstatus pending).
     */
    public function reject(Tenant $tenant)
    {
        if (! $tenant->isPending()) {
            return back()->withErrors(['reject' => 'Tenant ini bukan pendaftaran yang menunggu persetujuan.']);
        }

        $name = $tenant->name;
        $tenant->delete();

        return redirect()->route('vpn-owner.tenants')->with('success', "Pendaftaran \"{$name}\" ditolak & dihapus.");
    }

    /**
     * Hapus tenant PERMANEN — database-nya (semua data pelanggan,
     * voucher, transaksi, dst) dan NAS/Router/Akun VPN milik tenant
     * ini di database pusat, SEMUA ikut terhapus. TIDAK BISA DIBATALKAN.
     * Konfirmasi WAJIB ketik ulang subdomain tenant persis.
     */
    public function destroy(Tenant $tenant, Request $request)
    {
        $request->validate([
            'confirm_subdomain' => 'required|string',
        ]);

        if ($request->input('confirm_subdomain') !== $tenant->subdomain) {
            return back()->withErrors(['confirm_subdomain' => 'Subdomain konfirmasi tidak cocok. Tenant TIDAK dihapus.']);
        }

        $name = $tenant->name;
        $subdomain = $tenant->subdomain;
        $databaseName = $tenant->database_name;

        // Bersihkan data pusat milik tenant ini dulu (NAS, Router, Akun VPN)
        \Illuminate\Support\Facades\DB::connection('central')->table('nas')->where('tenant_id', $tenant->id)->delete();
        \Illuminate\Support\Facades\DB::connection('central')->table('routers')->where('tenant_id', $tenant->id)->delete();
        \Illuminate\Support\Facades\DB::connection('central')->table('vpn_accounts')->where('tenant_id', $tenant->id)->delete();

        // Hapus database tenant-nya sendiri
        if ($databaseName) {
            \Illuminate\Support\Facades\DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$databaseName}`");
        }

        $tenant->delete();

        return redirect()->route('vpn-owner.tenants')->with('success', "Tenant \"{$name}\" ({$subdomain}) dihapus permanen beserta seluruh datanya.");
    }
}
