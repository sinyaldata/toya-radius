<?php

namespace App\Http\Controllers;

use App\Models\Central\PlanCatalog;
use Illuminate\Http\Request;

/**
 * Kelola KATALOG paket yang ditawarkan ke calon pelanggan di halaman
 * pendaftaran mandiri (/daftar) — beda dari "Paket Berlangganan" yang
 * mengatur batas SATU tenant tertentu.
 */
class OwnerPlanCatalogController extends Controller
{
    public function index()
    {
        $plans = PlanCatalog::orderBy('sort_order')->orderBy('id')->get();

        return view('vpn-owner.plan-catalog', compact('plans'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'max_concurrent_online' => 'required|integer|min:1',
            'price_per_month' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        PlanCatalog::create([...$data, 'is_active' => true]);

        return redirect()->route('vpn-owner.plan-catalog')->with('success', 'Paket berhasil ditambahkan ke katalog.');
    }

    public function toggle(PlanCatalog $plan)
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return redirect()->route('vpn-owner.plan-catalog')->with('success', $plan->is_active ? 'Paket diaktifkan.' : 'Paket disembunyikan dari halaman pendaftaran.');
    }

    public function destroy(PlanCatalog $plan)
    {
        $plan->delete();

        return redirect()->route('vpn-owner.plan-catalog')->with('success', 'Paket dihapus dari katalog.');
    }
}
