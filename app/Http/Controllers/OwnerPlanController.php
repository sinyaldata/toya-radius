<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Kelola paket berlangganan tenant — SENGAJA di panel owner ini (bukan
 * di Filament tenant), supaya SuperAdmin tenant TIDAK BISA ubah batas
 * paketnya sendiri. Satu login dengan halaman VPN owner.
 *
 * subscription_plans TETAP di database MASING-MASING tenant (bukan
 * pusat) — jadi buat edit paket tenant TERTENTU, koneksi 'mysql'
 * dialihkan sementara ke database tenant itu, WAJIB dikembalikan lagi
 * ke instalasi utama sebelum request selesai (lihat resetConnection()).
 */
class OwnerPlanController extends Controller
{
    public function index(Request $request)
    {
        // Diambil dari CONFIG SEBELUM koneksi dialihkan ke database
        // tenant, bukan env() di resetConnection() -- kalau config
        // sedang di-cache (php artisan config:cache), env() di luar file
        // config selalu bernilai null, jadi database malah ke-reset jadi
        // KOSONG ("No database selected"), bukan balik ke database
        // utama. Pola yang sama seperti RunsPerTenant/MigrateTenants.
        $mainDatabase = config('database.connections.mysql.database');

        $tenantSubdomain = $request->query('tenant');
        $tenant = $tenantSubdomain ? Tenant::where('subdomain', $tenantSubdomain)->first() : null;

        $this->switchConnection($tenant);

        try {
            $plan = SubscriptionPlan::current();
            $online = SubscriptionPlan::currentOnlineCount();
            $riwayat = SubscriptionPlan::orderByDesc('id')->limit(10)->get();
        } finally {
            $this->resetConnection($mainDatabase);
        }

        $tenants = Tenant::orderBy('name')->get();

        return view('vpn-owner.plan', compact('plan', 'online', 'riwayat', 'tenants', 'tenant'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant' => 'nullable|string',
            'name' => 'required|string|max:100',
            'max_concurrent_online' => 'required|integer|min:1',
            'price_per_month' => 'nullable|numeric|min:0',
            'active_until' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $mainDatabase = config('database.connections.mysql.database');

        $tenantSubdomain = $data['tenant'] ?? null;
        $tenant = $tenantSubdomain ? Tenant::where('subdomain', $tenantSubdomain)->first() : null;

        $this->switchConnection($tenant);

        try {
            // Paket lama dinonaktifkan, paket baru dibuat aktif — supaya ada
            // riwayat perubahan paket dari waktu ke waktu (bukan ditimpa).
            SubscriptionPlan::where('is_active', true)->update(['is_active' => false]);

            SubscriptionPlan::create([
                'name' => $data['name'],
                'max_concurrent_online' => $data['max_concurrent_online'],
                'price_per_month' => $data['price_per_month'] ?? null,
                'active_until' => $data['active_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ]);
        } finally {
            $this->resetConnection($mainDatabase);
        }

        return redirect()->route('vpn-owner.plan', ['tenant' => $tenantSubdomain])
            ->with('success', 'Paket berhasil disimpan untuk '.($tenant?->name ?? 'Instalasi Utama').'.');
    }

    protected function switchConnection(?Tenant $tenant): void
    {
        if (! $tenant) {
            return;
        }

        Config::set('database.connections.mysql.database', $tenant->database_name);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    protected function resetConnection(string $mainDatabase): void
    {
        Config::set('database.connections.mysql.database', $mainDatabase);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}
