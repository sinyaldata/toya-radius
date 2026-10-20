<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice Pembayaran</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
            color: #1a1a1a;
            padding: 20mm 18mm;
        }
        .header { display: table; width: 100%; margin-bottom: 8mm; }
        .header .kiri { display: table-cell; vertical-align: top; }
        .header .kanan { display: table-cell; vertical-align: top; text-align: right; }
        .company-name { font-size: 20px; font-weight: 700; }
        .muted { color: #555; }
        .judul { font-size: 22px; font-weight: 700; letter-spacing: 1px; color: #333; }
        .no-invoice { color: #555; margin-top: 2px; }
        .kotak-info { display: table; width: 100%; margin: 8mm 0; }
        .kotak-info .kolom { display: table-cell; width: 50%; vertical-align: top; }
        .kotak-info .label { color: #777; font-size: 11px; text-transform: uppercase; margin-bottom: 2px; }
        table.item { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        table.item th {
            background: #2d3748; color: #fff; text-align: left;
            padding: 8px 10px; font-size: 12px;
        }
        table.item th.kanan, table.item td.kanan { text-align: right; }
        table.item td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        table.total { width: 100%; margin-top: 6mm; }
        table.total td { padding: 4px 10px; }
        table.total .label { text-align: right; color: #555; }
        table.total .nilai { text-align: right; width: 45mm; }
        table.total tr.grand td { font-size: 16px; font-weight: 700; border-top: 2px solid #2d3748; padding-top: 8px; }
        .footer { margin-top: 14mm; text-align: center; color: #888; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="kiri">
            <div class="company-name">{{ \App\Models\Setting::get('isp_name', config('app.name')) }}</div>
            <div class="muted">{{ \App\Models\Setting::get('isp_address') }}</div>
            <div class="muted">{{ \App\Models\Setting::get('isp_contact') }}</div>
        </div>
        <div class="kanan">
            <div class="judul">INVOICE</div>
            <div class="no-invoice">{{ $noStruk }}</div>
            <div class="no-invoice">{{ $tanggal }}</div>
        </div>
    </div>

    <div class="kotak-info">
        <div class="kolom">
            <div class="label">Ditagihkan kepada</div>
            <div class="bold" style="font-weight:700; font-size:14px;">{{ $pelanggan->name }}</div>
            @if($pelanggan->servicePackage)
                <div class="muted">Paket: {{ $pelanggan->servicePackage->name }}</div>
            @endif
            @if($outletName)
                <div class="muted">Outlet: {{ $outletName }}</div>
            @endif
        </div>
        <div class="kolom" style="text-align:right;">
            <div class="label">Metode Pembayaran</div>
            <div>{{ $metode }}</div>
        </div>
    </div>

    <table class="item">
        <thead>
            <tr>
                <th>Keterangan</th>
                <th class="kanan">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['label'] }}</td>
                    <td class="kanan">Rp{{ number_format($item['jumlah'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="total">
        <tr>
            <td class="label">TOTAL BAYAR</td>
            <td class="nilai" style="font-weight:700;">Rp{{ number_format($total, 0, ',', '.') }}</td>
        </tr>
        @if($sisaTagihan > 0)
            <tr>
                <td class="label">Sisa Tunggakan</td>
                <td class="nilai">Rp{{ number_format($sisaTagihan, 0, ',', '.') }}</td>
            </tr>
        @endif
        @if($saldoLebih > 0)
            <tr>
                <td class="label">Saldo Di Muka</td>
                <td class="nilai">Rp{{ number_format($saldoLebih, 0, ',', '.') }}</td>
            </tr>
        @endif
    </table>

    <div class="footer">
        Terima kasih atas pembayarannya — dicetak otomatis lewat bot Telegram.
    </div>
</body>
</html>
