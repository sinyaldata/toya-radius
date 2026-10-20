<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $judul }} - {{ $namaIsp }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            padding-top: calc(1.5rem + env(safe-area-inset-top, 0px));
            padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(160deg, #fff7ed 0%, #ffedd5 45%, #fed7aa 100%);
        }
        .kartu {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(154, 52, 18, 0.18);
            padding: 2rem 1.75rem;
            text-align: center;
        }
        .ikon {
            width: 72px;
            height: 72px;
            margin: 0 auto 1.1rem;
            border-radius: 50%;
            background: #fff1e8;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ikon svg { width: 36px; height: 36px; }
        .nama-isp {
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #c2410c;
            margin-bottom: .6rem;
        }
        h1 {
            font-size: 1.28rem;
            font-weight: 800;
            color: #18181b;
            margin: 0 0 .75rem;
            line-height: 1.35;
        }
        p.pesan {
            font-size: .92rem;
            line-height: 1.6;
            color: #52525b;
            margin: 0 0 1.5rem;
            white-space: pre-line;
        }
        .rekening {
            background: #fafafa;
            border: 1px solid #e4e4e7;
            border-radius: 12px;
            padding: .85rem 1rem;
            font-size: .85rem;
            color: #3f3f46;
            margin-bottom: 1.5rem;
            text-align: start;
            white-space: pre-line;
        }
        .tombol {
            display: block;
            width: 100%;
            padding: .9rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: .95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .tombol-wa {
            background: #16a34a;
            color: #fff;
            margin-bottom: .65rem;
        }
        .tombol-ulang {
            background: #f4f4f5;
            color: #3f3f46;
        }
        .footer {
            margin-top: 1.5rem;
            font-size: .72rem;
            color: #a1a1aa;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="ikon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h.01"></path>
                <path d="M8.5 16.5a5 5 0 0 1 7 0"></path>
                <path d="M5 12.9a10 10 0 0 1 14 0"></path>
                <path d="M2 8.8a15 15 0 0 1 20 0"></path>
                <line x1="3" y1="3" x2="21" y2="21"></line>
            </svg>
        </div>

        <div class="nama-isp">{{ $namaIsp }}</div>
        <h1>{{ $judul }}</h1>
        <p class="pesan">{{ $pesan }}</p>

        @if ($rekening)
            <div class="rekening">{{ $rekening }}</div>
        @endif

        @if ($wa)
            @php
                $waAngka = preg_replace('/[^0-9]/', '', $wa);
                $waAngka = str_starts_with($waAngka, '0') ? '62'.substr($waAngka, 1) : $waAngka;
            @endphp
            <a class="tombol tombol-wa" href="https://wa.me/{{ $waAngka }}?text={{ urlencode('Halo, saya mau menanyakan tagihan internet saya yang sedang tidak aktif.') }}" target="_blank" rel="noopener">
                💬 Hubungi via WhatsApp
            </a>
        @endif

        <button type="button" class="tombol tombol-ulang" onclick="location.reload()">Muat Ulang Halaman</button>

        <div class="footer">Halaman ini muncul otomatis karena akses internet Anda sedang dibatasi.</div>
    </div>
</body>
</html>
