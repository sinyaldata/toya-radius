<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar — Toya Radius</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; padding: 2rem 1rem; }
        .wrap { max-width: 42rem; margin: 0 auto; }
        .brand { text-align: center; margin-bottom: 1.5rem; }
        .brand h1 { font-size: 1.4rem; margin: 0; }
        .brand h1 span { color: #f97316; }
        .brand p { color: #71717a; font-size: 0.85rem; margin-top: 0.25rem; }
        .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 0.85rem; padding: 1.75rem; }
        h2 { font-size: 1rem; margin: 0 0 1.25rem; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
        label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.3rem; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 0.6rem 0.75rem; border-radius: 0.5rem; border: 1px solid #d4d4d8; font-size: 0.88rem; }
        .subdomain-wrap { display: flex; align-items: center; }
        .subdomain-wrap input { border-radius: 0.5rem 0 0 0.5rem; }
        .subdomain-suffix { background: #f4f4f5; border: 1px solid #d4d4d8; border-left: none; border-radius: 0 0.5rem 0.5rem 0; padding: 0.6rem 0.75rem; font-size: 0.82rem; color: #71717a; white-space: nowrap; }
        .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-top: 0.4rem; }
        .plan-card { border: 2px solid #e4e4e7; border-radius: 0.6rem; padding: 0.9rem; cursor: pointer; position: relative; }
        .plan-card input { position: absolute; top: 0.75rem; right: 0.75rem; width: auto; }
        .plan-card:has(input:checked) { border-color: #f97316; background: #fff7ed; }
        .plan-name { font-weight: 700; font-size: 0.9rem; }
        .plan-limit { font-size: 0.78rem; color: #71717a; margin-top: 0.15rem; }
        .plan-price { font-weight: 700; color: #f97316; margin-top: 0.5rem; font-size: 0.95rem; }
        .btn { display: block; width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #f97316; color: #fff; font-weight: 700; font-size: 0.92rem; cursor: pointer; margin-top: 1.5rem; }
        .flash-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 0.85rem 1rem; border-radius: 0.6rem; margin-bottom: 1.25rem; font-size: 0.85rem; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <h1>Toya<span>Radius</span></h1>
        <p>Daftar jadi mitra — kelola billing & RADIUS ISP kamu sendiri</p>
    </div>

    @if ($errors->any())
        <div class="flash-error">
            @foreach ($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ route('tenant-registration.store') }}">
            @csrf

            <h2>Data Perusahaan / ISP</h2>
            <div class="grid">
                <div>
                    <label>Nama ISP / Perusahaan</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="mis. Ijoe Net" required>
                </div>
                <div>
                    <label>Alamat</label>
                    <input type="text" name="address" value="{{ old('address') }}" required>
                </div>
                <div>
                    <label>Nama Pemilik</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name') }}" required>
                </div>
                <div>
                    <label>No. HP</label>
                    <input type="text" name="owner_phone" value="{{ old('owner_phone') }}" required>
                </div>
                <div style="grid-column: span 2;">
                    <label>Email (buat login SuperAdmin nanti)</label>
                    <input type="email" name="owner_email" value="{{ old('owner_email') }}" required>
                </div>
                <div style="grid-column: span 2;">
                    <label>Subdomain</label>
                    <div class="subdomain-wrap">
                        <input type="text" name="subdomain" value="{{ old('subdomain') }}" placeholder="ijoenet" required>
                        <span class="subdomain-suffix">.sinyaldata.web.id</span>
                    </div>
                </div>
            </div>

            <h2 style="margin-top: 1.75rem;">Pilih Paket</h2>
            <div class="plans">
                @forelse ($plans as $plan)
                    <label class="plan-card">
                        <input type="radio" name="plan_catalog_id" value="{{ $plan->id }}" {{ old('plan_catalog_id') == $plan->id ? 'checked' : '' }} required>
                        <div class="plan-name">{{ $plan->name }}</div>
                        <div class="plan-limit">Maks {{ number_format($plan->max_concurrent_online) }} user online</div>
                        @if ($plan->price_per_month)
                            <div class="plan-price">Rp{{ number_format($plan->price_per_month, 0, ',', '.') }}/bln</div>
                        @endif
                    </label>
                @empty
                    <p style="color:#a1a1aa; font-size:0.85rem;">Belum ada paket tersedia — hubungi admin.</p>
                @endforelse
            </div>

            <button type="submit" class="btn">Daftar Sekarang</button>
            <p style="text-align:center; font-size:0.75rem; color:#a1a1aa; margin-top:0.85rem;">
                Pendaftaran akan ditinjau dulu sebelum akun diaktifkan.
            </p>
        </form>
    </div>
</div>
</body>
</html>
