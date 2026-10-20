<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\Radcheck;
use App\Models\Radreply;
use App\Models\VpnAccount;
use App\Services\L2tpAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Halaman VPN khusus owner. Provisioning-nya BEDA tergantung jenis VPN:
 * - L2TP (xl2tpd, TERBUKTI paling stabil & IP tunnel BENERAN tetap):
 *   tulis langsung ke file /etc/ppp/chap-secrets di server.
 * - PPTP (SoftEther, RADIUS Hub sudah diaktifkan): tetap lewat
 *   radcheck/radreply seperti sebelumnya — auth-nya jalan, tapi IP
 *   tunnel-nya tetap butuh trik "pref-src" di skrip MikroTik (SecureNAT
 *   SoftEther tidak mendukung Framed-IP-Address, sudah dicek resmi).
 *   (SSTP dihapus dari pilihan atas permintaan user -- kode di provision()
 *   di bawah tetap satu jalur dengan PPTP, cuma tidak lagi bisa dipilih
 *   dari form manapun.)
 *
 * MULTI-TENANT: vpn_accounts, nas, radcheck, radreply SEKARANG semua
 * hidup di database PUSAT (dipakai bersama, satu FreeRADIUS). Owner
 * tetap SATU-SATUNYA yang boleh provisioning (bukan self-service
 * tenant) — halaman ini punya SELEKTOR TENANT yang cuma memfilter
 * `tenant_id`, TIDAK PERLU lagi pindah-pindah koneksi database.
 */
class VpnOwnerController extends Controller
{
    public function showLogin()
    {
        if (session('vpn_owner_ok')) {
            return redirect()->route('vpn-owner.index');
        }

        return view('vpn-owner.login');
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $expected = config('services.softether.owner_password');

        if (! $expected || ! hash_equals((string) $expected, (string) $request->input('password'))) {
            throw ValidationException::withMessages([
                'password' => 'Password salah.',
            ]);
        }

        $request->session()->regenerate();
        session(['vpn_owner_ok' => true]);

        return redirect()->route('vpn-owner.index');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('vpn_owner_ok');
        $request->session()->regenerate();

        return redirect()->route('vpn-owner.login');
    }

    public function index(Request $request)
    {
        $tenantSlug = $request->query('tenant', 'main');
        $tenants = Tenant::where('status', 'active')->orderBy('name')->get();
        $tenantId = $this->resolveTenantId($tenantSlug);

        $accounts = VpnAccount::with('nas')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get();

        return view('vpn-owner.index', [
            'accounts' => $accounts,
            'tenants' => $tenants,
            'currentTenant' => $tenantSlug,
        ]);
    }

    public function store(Request $request, L2tpAccountService $l2tp)
    {
        $data = $request->validate([
            'username' => 'required|string|max:60|alpha_dash',
            'tunnel_ip' => 'required|ip',
            // SSTP dihapus dari pilihan (permintaan user) -- validasi ini
            // ikut disempitkan supaya tidak ada jalan buat submit 'sstp'
            // langsung lewat request manual walau sudah tidak ada di form.
            'vpn_type' => 'required|in:l2tp,pptp',
            'tenant' => 'nullable|string',
        ]);

        $username = strtolower($data['username']);

        // Cek lintas-tenant LANGSUNG ke file chap-secrets — tabel
        // vpn_accounts sekarang memang sudah satu database bersama,
        // tapi username L2TP-nya HARUS juga cocok dengan yang tertulis
        // di chap-secrets, jadi tetap perlu dicek terpisah dari situ.
        if ($l2tp->usernameExists($username)) {
            throw ValidationException::withMessages([
                'username' => 'Username ini sudah dipakai.',
            ]);
        }

        $tenantSlug = $data['tenant'] ?? 'main';
        $tenantId = $this->resolveTenantId($tenantSlug);

        $password = Str::random(12);

        VpnAccount::create([
            'tenant_id' => $tenantId,
            'username' => $username,
            'password' => $password,
            'tunnel_ip' => $data['tunnel_ip'],
            'vpn_type' => $data['vpn_type'],
            'is_active' => true,
        ]);

        $provisionMessage = $this->provision($data['vpn_type'], $username, $password, $data['tunnel_ip'], $l2tp);

        return redirect()->route('vpn-owner.index', ['tenant' => $tenantSlug])->with([
            'success' => "Akun \"{$username}\" berhasil dibuat. Password: {$password} (catat sekarang, tidak akan ditampilkan lagi).",
            'provision_message' => $provisionMessage,
        ]);
    }

    public function destroy(Request $request, VpnAccount $account, L2tpAccountService $l2tp)
    {
        $tenantSlug = $request->query('tenant', 'main');

        if ($account->isUsed()) {
            return back()->withErrors(['delete' => 'Akun ini masih dipakai NAS "'.$account->nas->shortname.'" — lepaskan dulu dari NAS itu sebelum dihapus.']);
        }

        if ($account->vpn_type === 'l2tp') {
            $l2tp->removeAccount($account->username);
        } else {
            Radcheck::where('username', $account->username)->delete();
            Radreply::where('username', $account->username)->delete();
        }

        $account->delete();

        return redirect()->route('vpn-owner.index', ['tenant' => $tenantSlug])->with('success', 'Akun berhasil dihapus.');
    }

    public function storeBulk(Request $request, L2tpAccountService $l2tp)
    {
        $data = $request->validate([
            'prefix' => 'required|string|max:40|alpha_dash',
            'jumlah' => 'required|integer|min:1|max:50',
            'ip_awal' => 'required|ip',
            // SSTP dihapus dari pilihan (permintaan user) -- validasi ini
            // ikut disempitkan supaya tidak ada jalan buat submit 'sstp'
            // langsung lewat request manual walau sudah tidak ada di form.
            'vpn_type' => 'required|in:l2tp,pptp',
            'tenant' => 'nullable|string',
        ]);

        $tenantSlug = $data['tenant'] ?? 'main';
        $tenantId = $this->resolveTenantId($tenantSlug);

        $ipParts = explode('.', $data['ip_awal']);
        $lastOctet = (int) array_pop($ipParts);
        $ipBase = implode('.', $ipParts);

        $created = 0;
        $skipped = [];
        $provisionErrors = [];

        for ($i = 0; $i < (int) $data['jumlah']; $i++) {
            $username = strtolower($data['prefix']).str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $ip = $ipBase.'.'.($lastOctet + $i);

            if ($l2tp->usernameExists($username) || VpnAccount::where('username', $username)->exists()) {
                $skipped[] = $username;

                continue;
            }

            $password = Str::random(12);

            VpnAccount::create([
                'tenant_id' => $tenantId,
                'username' => $username,
                'password' => $password,
                'tunnel_ip' => $ip,
                'vpn_type' => $data['vpn_type'],
                'is_active' => true,
            ]);

            $result = $this->provision($data['vpn_type'], $username, $password, $ip, $l2tp);
            if ($result) {
                $provisionErrors[] = "{$username}: {$result}";
            }

            $created++;
        }

        return redirect()->route('vpn-owner.index', ['tenant' => $tenantSlug])->with([
            'success' => "{$created} akun berhasil dibuat.",
            'provision_message' => trim(
                ($provisionErrors ? implode("\n", $provisionErrors)."\n" : '').
                ($skipped ? 'Dilewati (username sudah ada): '.implode(', ', $skipped) : '')
            ) ?: null,
        ]);
    }

    /**
     * "main" (instalasi utama, sinyaldata) disimpan sebagai tenant_id
     * NULL — konsisten dengan TenantScope di model Nas/Router (yang
     * juga menganggap NULL = instalasi utama).
     */
    protected function resolveTenantId(string $tenantSlug): ?int
    {
        if ($tenantSlug === 'main') {
            return null;
        }

        $tenant = Tenant::where('subdomain', $tenantSlug)->where('status', 'active')->first();

        if (! $tenant) {
            abort(404, 'Tenant tidak ditemukan.');
        }

        return $tenant->id;
    }

    /**
     * Provisioning nyata (bukan cuma catatan di aplikasi) — beda jalur
     * tergantung jenis VPN. Mengembalikan PESAN kalau ada masalah, atau
     * null kalau berhasil/tidak perlu provisioning otomatis.
     */
    protected function provision(string $vpnType, string $username, string $password, string $tunnelIp, L2tpAccountService $l2tp): ?string
    {
        if ($vpnType === 'l2tp') {
            $result = $l2tp->upsertAccount($username, $password, $tunnelIp);

            return $result['success'] ? null : $result['message'];
        }

        // PPTP (SoftEther) — auth-nya lewat RADIUS kita (Hub sudah
        // di-set RadiusServerSet), tapi user-nya SENDIRI masih perlu
        // dibuat manual sekali di vpncmd server VPN (UserCreate +
        // UserRadiusSet) — belum otomatis dari sini.
        Radcheck::where('username', $username)->delete();
        Radreply::where('username', $username)->delete();

        Radcheck::create([
            'username' => $username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => $password,
        ]);

        Radreply::create([
            'username' => $username,
            'attribute' => 'Framed-IP-Address',
            'op' => '=',
            'value' => $tunnelIp,
        ]);

        return 'Tersimpan di RADIUS — TAPI untuk PPTP (SoftEther) masih perlu jalankan manual di vpncmd server VPN: Hub ToyaHub, lalu UserCreate '.$username.' /GROUP:none /REALNAME:none /NOTE:none, lalu UserRadiusSet '.$username.'.';
    }
}
