<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tenant — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vpn-owner.partials.styles')
</head>
<body>
<div class="tya-app">
    @include('vpn-owner.partials.sidebar', ['active' => 'tenants'])
    <div class="tya-main">
    @include('vpn-owner.partials.topbar', ['active' => 'tenants'])

    @if (session('success'))
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span>{{ session('success') }}</span></div>
    @endif
    @if (session('warning'))
        <div class="tya-flash warning"><span class="tya-flash-icon">⚠️</span><span>{{ session('warning') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span>{{ $errors->first() }}</span></div>
    @endif

    @php($pending = $tenants->where('status', 'pending'))
    @if ($pending->isNotEmpty())
        <div class="tya-card amber">
            <div class="tya-card-head">
                <h2>⏳ Pendaftaran Menunggu Persetujuan ({{ $pending->count() }})</h2>
            </div>
            <div class="tya-table-wrap">
                <table class="tya-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Subdomain</th>
                            <th>Pemilik</th>
                            <th>Paket</th>
                            <th>Kontak</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $tenant)
                            <tr>
                                <td data-label="Nama"><strong>{{ $tenant->name }}</strong></td>
                                <td data-label="Subdomain">{{ $tenant->subdomain }}.{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}</td>
                                <td data-label="Pemilik">{{ $tenant->owner_name }}</td>
                                <td data-label="Paket">{{ $tenant->plan?->name ?? '-' }}</td>
                                <td data-label="Kontak">{{ $tenant->owner_email }}<br>{{ $tenant->owner_phone }}</td>
                                <td data-label="">
                                    <div style="display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end;">
                                        <form method="POST" action="{{ route('vpn-owner.tenants.approve', $tenant) }}" class="tya-inline-form">
                                            @csrf
                                            <button type="submit" class="tya-btn sm">Setujui</button>
                                        </form>
                                        <form method="POST" action="{{ route('vpn-owner.tenants.reject', $tenant) }}" class="tya-inline-form" onsubmit="return confirm('Tolak & hapus pendaftaran ini?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="tya-btn danger ghost sm">Tolak</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="tya-card" id="tambah-tenant">
        <div class="tya-card-head">
            <h2>➕ Tambah Tenant Baru</h2>
        </div>
        <form method="POST" action="{{ route('vpn-owner.tenants.store') }}">
            @csrf
            <div class="tya-grid">
                <div class="tya-field">
                    <label>Nama ISP / Perusahaan</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="mis. Ijoe Net" required>
                </div>
                <div class="tya-field">
                    <label>Subdomain</label>
                    <input type="text" name="subdomain" value="{{ old('subdomain') }}" placeholder="mis. ijoe" required>
                </div>
                <div class="tya-field">
                    <label>Nama Pemilik</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name') }}">
                </div>
                <div class="tya-field">
                    <label>Email Pemilik (buat login SuperAdmin)</label>
                    <input type="email" name="owner_email" value="{{ old('owner_email') }}" required>
                </div>
                <div class="tya-field">
                    <label>No. HP</label>
                    <input type="text" name="owner_phone" value="{{ old('owner_phone') }}">
                </div>
                <div class="tya-field">
                    <label>Alamat</label>
                    <input type="text" name="address" value="{{ old('address') }}">
                </div>
                <div class="tya-field">
                    <label>Paket Tenant</label>
                    <select name="plan_catalog_id">
                        <option value="">— Belum dipilih —</option>
                        @foreach ($planCatalogs as $paket)
                            <option value="{{ $paket->id }}" @selected(old('plan_catalog_id') == $paket->id)>
                                {{ $paket->name }}
                                @if ($paket->price_per_month)
                                    (Rp{{ number_format($paket->price_per_month, 0, ',', '.') }}/bulan)
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="tya-field">
                    <label>Jadwal Jatuh Tempo</label>
                    <input type="date" name="billing_due_date" value="{{ old('billing_due_date') }}">
                </div>
            </div>
            <p class="tya-hint">
                Otomatis: bikin database baru, jalankan semua migration aplikasi, buat akun SuperAdmin pertama.
                Proses ini bisa makan waktu beberapa detik.
                Paket & jadwal jatuh tempo boleh dikosongkan (mis. tenant internal/gratis) — kalau diisi,
                dashboard tenant ini otomatis menampilkan pengingat pembayaran mulai H-10 sebelum tanggal jatuh tempo.
            </p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Buat Tenant</button>
            </div>
        </form>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Tenant Aktif & Lainnya ({{ $tenants->where('status', '!=', 'pending')->count() }})</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Subdomain</th>
                        <th>Paket & Jatuh Tempo</th>
                        <th>Database</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tenants->where('status', '!=', 'pending') as $tenant)
                        @php($sisaHari = $tenant->daysUntilBillingDue())
                        <tr>
                            <td data-label="Nama"><strong>{{ $tenant->name }}</strong></td>
                            <td data-label="Subdomain">{{ $tenant->subdomain }}.{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}</td>
                            <td data-label="Paket & Jatuh Tempo">
                                <form method="POST" action="{{ route('vpn-owner.tenants.billing', $tenant) }}" style="display:flex; flex-direction:column; gap:.3rem; min-width:11rem;">
                                    @csrf
                                    <select name="plan_catalog_id" style="padding:0.3rem 0.5rem; font-size:0.75rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                        <option value="">— Belum dipilih —</option>
                                        @foreach ($planCatalogs as $paket)
                                            <option value="{{ $paket->id }}" @selected($tenant->plan_catalog_id === $paket->id)>{{ $paket->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="date" name="billing_due_date" value="{{ $tenant->billing_due_date?->format('Y-m-d') }}" style="padding:0.3rem 0.5rem; font-size:0.75rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                    @if ($sisaHari !== null)
                                        <span class="tya-badge {{ $sisaHari < 0 ? 'red' : ($sisaHari <= 10 ? 'amber' : 'green') }}" style="align-self:flex-start;">
                                            @if ($sisaHari < 0) Lewat {{ abs($sisaHari) }} hari
                                            @elseif ($sisaHari === 0) Jatuh tempo hari ini
                                            @else H-{{ $sisaHari }}
                                            @endif
                                        </span>
                                    @endif
                                    <button type="submit" class="tya-btn ghost sm" style="align-self:flex-start;">Simpan</button>
                                </form>
                            </td>
                            <td data-label="Database">{{ $tenant->database_name }}</td>
                            <td data-label="Status">
                                <span class="tya-badge {{ $tenant->status === 'active' ? 'green' : ($tenant->status === 'suspended' ? 'red' : 'amber') }}">{{ ucfirst($tenant->status) }}</span>
                            </td>
                            <td data-label="Dibuat">{{ $tenant->created_at->format('d/m/Y H:i') }}</td>
                            <td data-label="">
                                <form method="POST" action="{{ route('vpn-owner.tenants.destroy', $tenant) }}" style="display:flex; gap:.4rem; align-items:center; justify-content:flex-end; flex-wrap:wrap;">
                                    @csrf
                                    @method('DELETE')
                                    <input type="text" name="confirm_subdomain" placeholder="ketik: {{ $tenant->subdomain }}" style="width:9rem; padding:0.4rem 0.6rem; font-size:0.72rem; border:1px solid #d4d4d8; border-radius:0.4rem; font-family:inherit;">
                                    <button type="submit" class="tya-btn danger sm" onclick="return confirm('Yakin hapus tenant ini PERMANEN?');">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="tya-table-empty">Belum ada tenant aktif.</td></tr>
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
