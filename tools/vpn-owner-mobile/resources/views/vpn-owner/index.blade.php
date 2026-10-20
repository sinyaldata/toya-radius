<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Akun VPN — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vpn-owner.partials.styles')
</head>
<body>
<div class="tya-app">
    @include('vpn-owner.partials.sidebar', ['active' => 'index'])
    <div class="tya-main">
    @include('vpn-owner.partials.topbar', ['active' => 'index'])

    @if (session('success'))
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span>{{ session('success') }}</span></div>
    @endif
    @if (session('provision_message'))
        <div class="tya-flash warning"><span class="tya-flash-icon">⚠️</span><span>{{ session('provision_message') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span>{{ $errors->first() }}</span></div>
    @endif

    <div class="tya-card tint">
        <div class="tya-card-head">
            <h2>Sedang Mengelola VPN Untuk</h2>
        </div>
        <form method="GET" action="{{ route('vpn-owner.index') }}" class="tya-grid">
            <div class="tya-field">
                <label>Pilih Tenant</label>
                <select name="tenant" onchange="this.form.submit()">
                    <option value="main" {{ $currentTenant === 'main' ? 'selected' : '' }}>{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }} (Instalasi Utama)</option>
                    @foreach ($tenants as $t)
                        <option value="{{ $t->subdomain }}" {{ $currentTenant === $t->subdomain ? 'selected' : '' }}>{{ $t->name }} ({{ $t->subdomain }})</option>
                    @endforeach
                </select>
            </div>
        </form>
        <p class="tya-hint" style="margin-bottom:0;">Akun VPN yang dibuat/dilihat di halaman ini KHUSUS buat tenant yang dipilih — setiap tenant punya daftar akunnya sendiri.</p>
    </div>

    <div class="tya-flash info">
        <span class="tya-flash-icon">ℹ️</span>
        <span>
            <strong>L2TP (disarankan)</strong> — IP tunnel BENERAN tetap sesuai yang kamu tentukan, otomatis tersimpan ke <code>chap-secrets</code>, tidak perlu langkah manual lagi.<br>
            <strong>PPTP (SoftEther)</strong> — masih perlu 1 langkah manual di <code>vpncmd</code> server VPN setelah bikin akun (<code>Hub ToyaHub</code> → <code>UserCreate</code> → <code>UserRadiusSet</code>), dan IP tunnel-nya pakai trik <code>pref-src</code> di skrip MikroTik (bukan tetap beneran — batasan SoftEther SecureNAT).
        </span>
    </div>

    <div class="tya-card" id="generate-massal">
        <div class="tya-card-head">
            <h2>⚡ Generate Banyak Sekaligus</h2>
        </div>
        <form method="POST" action="{{ route('vpn-owner.store-bulk') }}">
            @csrf
            <input type="hidden" name="tenant" value="{{ $currentTenant }}">
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Prefix Username</label>
                    <input type="text" name="prefix" value="toyavpn" required>
                </div>
                <div class="tya-field">
                    <label>Jumlah Akun</label>
                    <input type="number" name="jumlah" value="10" min="1" max="50" required>
                </div>
                <div class="tya-field">
                    <label>IP Tunnel Awal</label>
                    <input type="text" name="ip_awal" placeholder="mis. 10.90.90.10" required>
                </div>
                <div class="tya-field">
                    <label>Jenis VPN</label>
                    <select name="vpn_type" required>
                        <option value="l2tp">L2TP (disarankan)</option>
                        <option value="pptp">PPTP (SoftEther)</option>
                    </select>
                </div>
            </div>
            <p class="tya-hint">IP berikutnya otomatis naik 1 per akun (mis. .10, .11, .12, dst).</p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Generate</button>
            </div>
        </form>
    </div>

    <div class="tya-card" id="buat-akun">
        <div class="tya-card-head">
            <h2>➕ Tambah Satu Akun VPN</h2>
        </div>
        <form method="POST" action="{{ route('vpn-owner.store') }}">
            @csrf
            <input type="hidden" name="tenant" value="{{ $currentTenant }}">
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="tya-field">
                    <label>IP Tunnel Tetap</label>
                    <input type="text" name="tunnel_ip" placeholder="mis. 10.90.90.51" required>
                </div>
                <div class="tya-field">
                    <label>Jenis VPN</label>
                    <select name="vpn_type" required>
                        <option value="l2tp">L2TP (disarankan)</option>
                        <option value="pptp">PPTP (SoftEther)</option>
                    </select>
                </div>
            </div>
            <p class="tya-hint">Password digenerate otomatis.</p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Buat Akun</button>
            </div>
        </form>
    </div>

    <div class="tya-card" id="daftar-akun">
        <div class="tya-card-head">
            <h2>Daftar Akun VPN ({{ $accounts->count() }})</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>IP Tunnel</th>
                        <th>Jenis</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <td data-label="Username"><strong>{{ $account->username }}</strong></td>
                            <td data-label="IP Tunnel">{{ $account->tunnel_ip }}</td>
                            <td data-label="Jenis"><span class="tya-badge blue">{{ strtoupper($account->vpn_type) }}</span></td>
                            <td data-label="Status">
                                @if ($account->isUsed())
                                    <span class="tya-badge amber">Dipakai: {{ $account->nas->shortname }}</span>
                                @else
                                    <span class="tya-badge green">Belum dipakai</span>
                                @endif
                            </td>
                            <td data-label="">
                                @unless ($account->isUsed())
                                    <form method="POST" action="{{ route('vpn-owner.destroy', $account) }}?tenant={{ $currentTenant }}" onsubmit="return confirm('Hapus akun {{ $account->username }}?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="tya-btn danger sm">Hapus</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="tya-table-empty">Belum ada akun VPN buat tenant ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div> {{-- /.tya-content --}}
    </div> {{-- /.tya-main --}}
</div> {{-- /.tya-app --}}
@include('vpn-owner.partials.scripts')
</body>
</html>
