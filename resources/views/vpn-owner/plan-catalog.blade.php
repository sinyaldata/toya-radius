<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Katalog Paket — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vpn-owner.partials.styles')
</head>
<body>
<div class="tya-app">
    @include('vpn-owner.partials.sidebar', ['active' => 'plan-catalog'])
    <div class="tya-main">
    @include('vpn-owner.partials.topbar', ['active' => 'plan-catalog'])

    @if (session('success'))
        <div class="tya-flash success"><span class="tya-flash-icon">✅</span><span>{{ session('success') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="tya-flash error"><span class="tya-flash-icon">⛔</span><span>{{ $errors->first() }}</span></div>
    @endif

    <div class="tya-card" id="tambah-paket">
        <div class="tya-card-head">
            <h2>➕ Tambah Paket ke Katalog</h2>
        </div>
        <p class="tya-card-sub">Paket di sini yang muncul buat calon pelanggan pilih di halaman pendaftaran (<code>/daftar</code>).</p>
        <form method="POST" action="{{ route('vpn-owner.plan-catalog.store') }}">
            @csrf
            <div class="tya-grid span-2-last">
                <div class="tya-field">
                    <label>Nama Paket</label>
                    <input type="text" name="name" placeholder="mis. Gold" required>
                </div>
                <div class="tya-field">
                    <label>Batas User Online Bersamaan</label>
                    <input type="number" name="max_concurrent_online" min="1" required>
                </div>
                <div class="tya-field">
                    <label>Harga per Bulan (Rp)</label>
                    <input type="number" name="price_per_month" min="0">
                </div>
                <div class="tya-field">
                    <label>Urutan Tampil (kecil = duluan)</label>
                    <input type="number" name="sort_order" min="0" value="0">
                </div>
                <div class="tya-field">
                    <label>Deskripsi (opsional)</label>
                    <textarea name="description" rows="2"></textarea>
                </div>
            </div>
            <div class="tya-actions-end">
                <button type="submit" class="tya-btn">Tambah ke Katalog</button>
            </div>
        </form>
    </div>

    <div class="tya-card">
        <div class="tya-card-head">
            <h2>Daftar Paket ({{ $plans->count() }})</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Batas Online</th>
                        <th>Harga/Bulan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr>
                            <td data-label="Nama"><strong>{{ $plan->name }}</strong></td>
                            <td data-label="Batas Online">{{ number_format($plan->max_concurrent_online) }}</td>
                            <td data-label="Harga/Bulan">{{ $plan->price_per_month ? 'Rp'.number_format($plan->price_per_month, 0, ',', '.') : '-' }}</td>
                            <td data-label="Status">
                                <span class="tya-badge {{ $plan->is_active ? 'green' : 'gray' }}">{{ $plan->is_active ? 'Aktif' : 'Disembunyikan' }}</span>
                            </td>
                            <td data-label="">
                                <div style="display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end;">
                                    <form method="POST" action="{{ route('vpn-owner.plan-catalog.toggle', $plan) }}" class="tya-inline-form">
                                        @csrf
                                        <button type="submit" class="tya-btn gray sm">{{ $plan->is_active ? 'Sembunyikan' : 'Aktifkan' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('vpn-owner.plan-catalog.destroy', $plan) }}" class="tya-inline-form" onsubmit="return confirm('Hapus paket ini dari katalog?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="tya-btn danger ghost sm">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="tya-table-empty">Belum ada paket di katalog.</td></tr>
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
