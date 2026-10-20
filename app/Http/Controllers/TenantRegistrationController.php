<?php

namespace App\Http\Controllers;

use App\Models\Central\PlanCatalog;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;

/**
 * Halaman pendaftaran MANDIRI, publik (tanpa login) — calon pelanggan
 * ISP daftar sendiri di sini. TIDAK langsung bikin database — cuma
 * catat status "pending", owner yang approve dari /vpn-owner.
 */
class TenantRegistrationController extends Controller
{
    public function create()
    {
        $plans = PlanCatalog::where('is_active', true)->orderBy('sort_order')->get();

        return view('tenant-registration.create', compact('plans'));
    }

    public function store(Request $request, TenantProvisioningService $service)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'owner_name' => 'required|string|max:100',
            'owner_email' => 'required|email|max:120',
            'owner_phone' => 'required|string|max:30',
            'address' => 'required|string|max:255',
            'subdomain' => 'required|string|max:40|alpha_dash|unique:App\Models\Central\Tenant,subdomain',
            'plan_catalog_id' => 'required|exists:App\Models\Central\PlanCatalog,id',
        ]);

        $service->registerPending($data);

        return redirect()->route('tenant-registration.success');
    }

    public function success()
    {
        return view('tenant-registration.success');
    }
}
