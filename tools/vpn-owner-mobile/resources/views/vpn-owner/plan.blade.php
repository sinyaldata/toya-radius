<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Paket Berlangganan — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vpn-owner.partials.styles')
</head>
<body>
<div class="tya-app">
    @include('vpn-owner.partials.sidebar', ['active' => 'plan'])
    <div class="tya-main">
    @include('vpn-owner.partials.topbar', ['active' => 'plan'])

    @if (session('success'))
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span>{{ session('success') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span>{{ $errors->first() }}</span></div>
    @endif

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Pilih Tenant</h2>
        </div>
        <form method="GET" action="{{ route('vpn-owner.plan') }}" class="tya-grid">
            <div class="tya-field">
                <label>Tenant</label>
                <select name="tenant" onchange="this.form.submit()">
                    <option value="">— Instalasi Utama ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}) —</option>
                    @foreach ($tenants as $t)
                        <option value="{{ $t->subdomain }}" @selected(request('tenant') === $t->subdomain)>{{ $t->name }} ({{ $t->subdomain }})</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Pemakaian Sekarang — {{ $tenant?->name ?? 'Instalasi Utama' }}</h2>
        </div>
        @php($persen = $plan->max_concurrent_online > 0 ? min(100, round(($online / $plan->max_concurrent_online) * 100)) : 0)
        <div style="font-size:.82rem; color:var(--tya-muted); display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
            <span>{{ $online }} / {{ $plan->max_concurrent_online }} user online</span>
            <span class="tya-badge blue">{{ $plan->name }}</span>
        </div>
        <div class="tya-usage-bar"><div class="tya-usage-bar-fill" style="width:{{ $persen }}%;"></div></div>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>✏️ Ubah Paket</h2>
        </div>
        <form method="POST" action="{{ route('vpn-owner.plan.store') }}">
            @csrf
            <input type="hidden" name="tenant" value="{{ $tenant?->subdomain }}">
            <div class="tya-grid span-2-last">
                <div class="tya-field">
                    <label>Nama Paket</label>
                    <input type="text" name="name" value="{{ old('name', $plan->name) }}" required>
                </div>
                <div class="tya-field">
                    <label>Batas User Online Bersamaan</label>
                    <input type="number" name="max_concurrent_online" value="{{ old('max_concurrent_online', $plan->max_concurrent_online) }}" min="1" required>
                </div>
                <div class="tya-field">
                    <label>Harga per Bulan (Rp)</label>
                    <input type="number" name="price_per_month" value="{{ old('price_per_month', $plan->price_per_month) }}">
                </div>
                <div class="tya-field">
                    <label>Aktif Sampai</label>
                    <input type="date" name="active_until" value="{{ old('active_until', $plan->active_until?->format('Y-m-d')) }}">
                </div>
                <div class="tya-field">
                    <label>Catatan</label>
                    <textarea name="notes" rows="2">{{ old('notes', $plan->notes) }}</textarea>
                </div>
            </div>
            <p class="tya-hint">Menyimpan paket baru menonaktifkan paket lama (riwayat tetap tersimpan di bawah).</p>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Simpan Paket</button>
            </div>
        </form>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Riwayat Paket</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Batas Online</th>
                        <th>Harga/Bulan</th>
                        <th>Aktif Sampai</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $item)
                        <tr>
                            <td data-label="Nama"><strong>{{ $item->name }}</strong></td>
                            <td data-label="Batas Online">{{ $item->max_concurrent_online }}</td>
                            <td data-label="Harga/Bulan">{{ $item->price_per_month ? 'Rp'.number_format($item->price_per_month, 0, ',', '.') : '-' }}</td>
                            <td data-label="Aktif Sampai">{{ $item->active_until?->format('d/m/Y') ?? '-' }}</td>
                            <td data-label="Status">
                                @if ($item->is_active)
                                    <span class="tya-badge green">Aktif</span>
                                @else
                                    <span class="tya-badge gray">Nonaktif</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="tya-table-empty">Belum ada riwayat.</td></tr>
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
